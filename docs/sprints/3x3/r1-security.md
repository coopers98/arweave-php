# 3×3 Round 1 — Security / Crypto Review

**Package:** `coopers98/arweave-php` (pure-PHP native-L1 Arweave transaction signer, `AgentImprint\Arweave`)
**Scope:** Standalone OSS crypto library whose signing will authorize mainnet transactions (lost funds/data if wrong).
**Mode:** READ-ONLY. No code modified.
**Commit reviewed:** `a8bdb76` ("Initial release"), branch `main`.
**Test state at review:** `49 passed (208 assertions)` via PHPUnit 11.5 / PHP 8.3; golden vectors generated from **arweave-js 1.15.5**.

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| IMPORTANT | 2 |
| SUGGESTION | 6 |

**Headline:** The cryptographic core is correct and is pinned **byte-for-byte against arweave-js 1.15.5** — deep-hash message, `data_root`, tx id, full `POST /tx` body, and `POST /chunk` proof bytes are all asserted equal to the reference client, and a *real arweave-js signature* is verified against the message this package computes (GATE 3 in `TransactionParityTest`). The multi-chunk `data:""` zeroing regression flagged in the prior embedded review is fixed (`SignedTransaction.php:72`) and now pinned by `GatewayBodyParityTest` + the `gatewayBodies` golden vectors. No CRITICAL crypto-correctness defects found.

The two IMPORTANT findings are **secret-handling / defense-in-depth** issues, not parity defects: a private-key-leak-into-stack-trace path that violates the package's own "never logs secret material" contract, and the absence of a post-sign self-verification for a fund/data-authorizing signer.

---

## Verified correct (explicit non-findings — high confidence)

These were scrutinized closely because the task brief raised them; all are correct and pinned.

1. **Deep-hash field list + ORDER.** `Transaction::signatureMessage()` (`Transaction.php:106-116`) hashes
   `["2", owner, target, quantity, reward, last_tx, tags, data_size, data_root]`.
   ⚠️ Note: the task brief states the spec order as `[format, owner, target, data_root, data_size, quantity, reward, last_tx, tags]` — **that brief order is inaccurate.** I diffed the actual reference: `tools/node_modules/arweave/node/lib/transaction.js` `getSignatureData()` case 2 (lines 203-213) hashes exactly `[format, owner, target, quantity, reward, last_tx, tags, data_size, data_root]`. The code **matches arweave-js**, which is the canonical/authoritative ordering. Triple-confirmed: (a) source diff, (b) `signatureMessage_hex` golden vectors captured from arweave-js's own `getSignatureData()`, (c) GATE 3 verifies a real arweave-js PSS signature against the computed message — impossible if a single byte/field were reordered.
2. **Deep-hash construction.** SHA-384 throughout (`DeepHash.php:38-41`); blob = `sha384(sha384("blob"+len) . sha384(data))`; list = fold `sha384(acc . deepHash(elem))` seeded `sha384("list"+count)`. List vs blob are distinguished (`DeepHashTest` asserts `hash(['x']) !== hash('x')`). Tag list is `[[name,value],…]` raw bytes. Format integer rendered as ASCII (`"2"`), matching arweave-js `stringToBuffer(format.toString())`.
3. **RSA-PSS parameters.** SHA-256 message hash, MGF1-SHA-256, salt length 32 (`RsaPss.php:113-118`) — matches arweave-js node/webcrypto driver. The 48-byte deep-hash output is the message; PSS internally applies SHA-256 to it, identical to arweave-js (proved by GATE 3).
4. **Transaction id.** `base64url(sha256(signature))` (`SignedTransaction.php:39`) — pinned to arweave-js `id` for every transaction/gatewayBody vector.
5. **Merkle / `data_root`.** 256 KiB max / 32 KiB min chunking with the second-to-last rebalance (`Merkle.php:72-99`) mirrors arweave-js `merkle.js`; trailing zero-length chunk is included in the root then discarded from the upload set (`Merkle.php:40-45`). Pinned across boundaries (`empty, tiny, 256K-1, 256K, 256K+1, 512K, 600K, 1M+7`) and `data_path` proof bytes pinned per-chunk (`ChunkProofParityTest`).
6. **Multi-chunk body zeroing.** `toGatewayJson()` posts `data:""` iff `isMultiChunk()` (`SignedTransaction.php:72,107-110`); single-chunk inlines. Pinned by `GatewayBodyParityTest` (asserts both paths are exercised) and the `post_body` golden bytes.
7. **Base64Url strictness.** `decode()` rejects non-`[A-Za-z0-9_-]` input including `+`, `/`, `=` padding, and whitespace (`Base64Url.php:25`), tested in `Base64UrlTest`. Encode is unpadded URL-safe.
8. **JWK validation (Wallet).** Requires `kty:"RSA"`, `e == "AQAB"` (65537), and all CRT params `n,e,d,p,q,dp,dq,qi` present, string-typed, non-empty (`Wallet.php:33-51`), tested in `WalletTest`.
9. **No secret logging in transport.** `ArweaveClient` keeps possibly-cred-bearing URLs only on the *chained previous* exception and uses `getUri()->getPath()` (no userinfo/query) in public messages (`ArweaveClient.php:92-94,118-125`).
10. **Golden vectors are NOT self-referential.** They are produced by an independent implementation (arweave-js, JS) via `tools/generate-golden.cjs`, capturing `tx.getSignatureData()`, `tx.toJSON()`, `tx.signature`, `tx.id`, `generateTransactionChunks()`, and `tx.getChunk()` — then cross-checked by verifying arweave-js's real signature. This is the correct trust anchor for an L1 client. (See SUGGESTION-2 re: pinning the exact arweave-js version.)

---

## IMPORTANT

### IMP-1 — Private-key JWK can leak into logs via the chained exception's stack trace
**File:** `src/Crypto/RsaPss.php:36-38`
```php
$key = RSA::loadFormat('JWK', (string) json_encode($jwk));
} catch (Throwable $e) {
    throw new ArweaveException('Failed to load RSA JWK into phpseclib: '.$e->getMessage(), 0, $e);
```
**Problem.** The library's stated contract (class docblocks in `RsaPss`/`Wallet`, composer description) is *"never logs, no secret material thrown."* This path can violate it. The argument to `RSA::loadFormat()` is the **full private-key JWK serialized as JSON** (`n,d,p,q,dp,dq,qi`). When `loadFormat` throws, the captured trace's `loadFormat` frame includes that JSON string as a call argument. By passing `$e` as `previous`, that trace is propagated out of the package. PHP includes function arguments in stack traces unless `zend.exception_ignore_args=On`; consumer frameworks (Laravel/Monolog, Sentry, etc.) routinely render `getTraceAsString()` / previous-exception traces to logs. Net effect: a malformed-but-secret-bearing JWK, or any phpseclib load failure, can write the wallet private key to the consumer's logs. For a wallet signer this is the single most sensitive byte string in the system.

It is borderline CRITICAL; rated IMPORTANT because exploitation depends on (a) a load failure occurring and (b) the consumer logging full traces with args enabled.

**Fix.** Do not propagate a trace whose frames carry the key material. Options, in order of preference:
1. Throw without the `previous` and with a static message: `throw new ArweaveException('Failed to load RSA JWK into phpseclib.');` (drop `$e` entirely).
2. If a cause chain is desired for debugging, re-wrap into a sanitized exception that strips args, and never include `$e->getMessage()` (phpseclib messages can echo parsed key fragments).
3. Avoid handing the raw JWK string to `loadFormat` as a positional arg in a frame that can throw outward (harder; #1 is simplest).

Apply the same scrutiny to `Wallet`/`RsaPss` construction generally: the decoded `$jwk` array is itself a candidate for appearing in traces of any exception thrown while those frames are live.

### IMP-2 — Signer does not verify its own signature before returning
**File:** `src/Crypto/RsaPss.php:49-52` (and `Wallet::sign`, `Transaction::sign`)
**Problem.** `sign()` returns the PSS signature with no self-check. For a component whose output authorizes irreversible mainnet writes ("lost funds/data if wrong"), a faulty signature (library/openssl regression, RSA fault, memory corruption, an HSM-style future backend) is only detected later as a gateway rejection — by which time, in a batch/bundle flow, partial uploads or a wasted reward may already have occurred, and the data may not persist (perpetuity break). A self-verify is cheap defense-in-depth and a standard practice for signing libraries (it also hardens against fault-injection on the private key).

**Fix.** After producing the signature, verify it against the signer's own public modulus before returning; throw `ArweaveException` on mismatch. Reuse `self::verify($modulus, $message, $signature)` (the modulus is derivable from `$this->key`). Add a unit test that a returned signature always self-verifies.

---

## SUGGESTION

### SUG-1 — `RsaPss` public constructor does not enforce the Arweave exponent (e == AQAB)
**File:** `src/Crypto/RsaPss.php:29-46`
`RsaPss` is a `final` class with a public constructor and is the actual signing primitive, but it only checks `kty == "RSA"` and presence of `n,d`. The `e == "AQAB"` (65537) guard lives only in `Wallet`. A consumer who instantiates `RsaPss` directly (it is public, exported API) bypasses the exponent check and could sign with a non-Arweave key. Add the same `e == "AQAB"` validation to `RsaPss` so the invariant holds regardless of entry point (defense-in-depth; `Wallet` remains the recommended path).

### SUG-2 — Pin the exact arweave-js version of the trust anchor
**File:** `tools/package.json:8`
The correctness gate is only as trustworthy as the reference it was captured from. `"arweave": "^1.15.5"` is caret-ranged; a future `npm install` could regenerate vectors from a different minor and silently shift the baseline. `node_modules` currently holds 1.15.5 and `package-lock.json` is committed (good), but recommend pinning the exact version (`"1.15.5"`, no caret) and documenting in the generator header which arweave-js version the committed `golden.json` corresponds to. Also worth stating explicitly in docs that the trust anchor is **arweave-js**, not a live mainnet node (acceptable — arweave-js is canonical and GATE 3 cross-validates a real signature — but it should be a conscious, documented choice).

### SUG-3 — `ArweaveClient::getData()` interpolates caller-supplied id into the URL without validation
**File:** `src/ArweaveClient.php:85-87`
```php
$request = $this->requests->createRequest('GET', "{$this->gateway}/{$id}");
```
`$id` is concatenated into the path unencoded/unvalidated. A caller passing an id containing `../`, a query (`?`), or a scheme could redirect the request away from the intended object/host (path traversal / request-target injection). Risk is low (read-only GET, normally a base64url tx id), but for a library a `Base64Url`-shape validation of `$id` before interpolation (or `rawurlencode`) closes it cheaply. `price(int $bytes)` is already type-safe.

### SUG-4 — RSA key size is not enforced (Arweave mainnet wallets are 4096-bit)
**File:** `src/Wallet.php` / `src/Crypto/RsaPss.php`
The library accepts any RSA modulus length (tests use 2048-bit). A sub-4096-bit key still produces a structurally valid signature locally but is not a standard Arweave wallet and may be rejected by the network. Consider validating/ warning when the modulus is not 512 bytes (4096-bit), or document the expectation clearly so a consumer does not ship a weak/non-standard wallet.

### SUG-5 — Document that this is a data-only (zero-value) signer
**File:** `src/Transaction.php:23-25` (`TARGET = ''`, `QUANTITY = '0'`)
`target` and `quantity` are hardcoded empty/zero and are signed as such, so this package **cannot construct value-transfer (AR-sending) transactions**. This is *safe* (it cannot accidentally move funds), but the brief frames signing as authorizing "funds." Make the data-only scope explicit in the README so a consumer does not assume fund-transfer support and roll their own unsafe path. (No code change required.)

### SUG-6 — Note the 64-bit integer assumption for Merkle offsets
**File:** `src/Crypto/Merkle.php:217-227` (`intToBuffer`) and chunk cursor arithmetic
Byte offsets and `maxByteRange` use native PHP `int`. On 64-bit PHP (the realistic target for `php ^8.2`) this is fine for any plausible data size. On a 32-bit build, large transactions could overflow the cursor/offset math and corrupt `data_root`/proofs. Recommend documenting the 64-bit requirement (or asserting `PHP_INT_SIZE >= 8`).

---

## Notes for later rounds
- R2 (GPT) should re-examine IMP-1 specifically — whether to also strip the decoded `$jwk` array from any exception path in `Wallet`/`RsaPss`, and whether to recommend `zend.exception_ignore_args` guidance in the README.
- IMP-2 (self-verify) is a design call; flag for spec/maintainability reviewers to weigh latency vs. safety.
- All findings are defense-in-depth or hygiene; none block the parity gate, which is green.
