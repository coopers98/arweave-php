# 3×3 R3 — Security/Crypto Review: `coopers98/arweave-php`

**Branch:** `main` · **HEAD:** `8d04f28` · **Round:** R3 (final) · **Mode:** READ-ONLY (no edits/stage/commit)
**Lens:** crypto correctness + secret custody on an irreversible funds-signing path.

---

## Verification runs

| Run | Result |
|---|---|
| `composer install -q` | clean |
| `./vendor/bin/pest --testsuite Unit` (offline gate) | **58 passed (229 assertions)** |
| `./vendor/bin/pest` (full, ArLocal v1.1.66 reachable) | **61 passed (239 assertions)** — 3 Integration tests ran, no skips |

Counts match the R2 fix report exactly (58 / 61). No regressions.

### Independent arweave-js cross-check (dev-only `tools/node_modules`, arweave 1.15.5)

Computed three primitives in arweave-js and in this library from identical inputs; **all byte-identical**:

| Primitive | arweave-js | arweave-php |
|---|---|---|
| deep-hash of `["2","hello","",[["App","AgentImprint"]]]` | `56455a52…a2e26a94` | `56455a52…a2e26a94` ✓ |
| `data_root` of a 262145-byte payload (>256 KiB, rebalance path) | `rRUkQivFouwhOka6C-GA6u5haWGLsX3yVwnEP7fHLDk` | same ✓ |
| id = base64url(sha256(sig)) | `qi9LV5HZD5SkY2__0YjMRO3nXNtk0dGuHOA_JOxHE_k` | same ✓ |

This independently re-confirms the parity gate (not just the committed golden fixtures).

---

## Byte-parity gate is REAL

The four parity suites pin against arweave-js golden vectors and self-guard against being no-ops:

- **DeepHash** (`src/Crypto/DeepHash.php:22-36`) — recursive SHA-384, `blob`/`list` tags, raw 48-byte digests. Matches arweave-js + my independent run.
- **Merkle / data_root** (`src/Crypto/Merkle.php`) — SHA-256, 32-byte big-endian notes, ≤256 KiB chunking with the <32 KiB final-chunk rebalance (`Merkle.php:86-89`), odd-node promotion (`hashBranch` null right, `:150`), and the trailing zero-length chunk discarded for exact-multiple-of-256-KiB data (`:40-45`). `golden().dataRoot` covers **all boundaries**: 0, 5, 262143, 262144 (exact max), 262145 (just-over → rebalance), 524288 (two full chunks → trailing zero chunk), 614400, 1048583. `MerkleTest` + `ChunkProofParityTest` pin proof bytes (`data_path`) byte-for-byte.
- **RSA-PSS params** (`src/Crypto/RsaPss.php:129-136`) — salt 32 / MGF1-SHA-256 / SHA-256, applied identically on the private and public path via `configure()`.
- **tx id** (`src/SignedTransaction.php:39`) — `base64url(sha256(signature))`. Pinned by `TransactionParityTest` GATE 4 + `GatewayBodyParityTest`.
- **Multi-chunk `POST /tx` body emits `data:""`** (`src/SignedTransaction.php:72`) — `GatewayBodyParityTest` asserts `data === ''` for both multi-chunk vectors and inline for the single-chunk one, *and* guards that both paths were exercised (`$sawMultiChunk`/`$sawSingleChunk`, `:62-63`). `data_size`/`data_root` still describe the full data.
- GATE 3 of `TransactionParityTest` verifies **arweave-js's own signature** against the message *we* compute — proving message-byte identity, not just self-consistency.

### Golden fixtures carry NO private-key material — CONFIRMED

`tests/fixtures/golden.json` top-level keys: `wallet, deepHash, dataRoot, chunkUploads, gatewayBodies, transactions`. The `wallet` object has only `address` and `owner_b64url` (the public modulus). A `jq` path scan for any `d/p/q/dp/dq/qi` key and a regex for those JSON keys both return **empty**. Vectors expose only public moduli, signatures, ids, proofs, and serialized bodies.

---

## Prior fixes — all HELD

| Fix | Status | Evidence |
|---|---|---|
| **R1-F1** static-message JWK-load failure (no `previous`, no `getMessage()` leak) | ✅ Held | `RsaPss.php:43-48` — `catch (Throwable)` → static `ArweaveException`, no chaining. `RsaPssTest:64-91` asserts `getPrevious()===null` and no JWK field bytes in message/trace. |
| **R1-F2** `sign()` self-verifies before returning | ✅ Held | `RsaPss.php:65-67` verifies against `$this->key->getPublicKey()` (salt 32), throws on mismatch. Tested `RsaPssTest:93-102`. |
| **R2-IMP1** `#[\SensitiveParameter]` on both JWK ctor params + regression | ✅ Held | `Wallet.php:33`, `RsaPss.php:29`. `SensitiveParameterLeakTest` genuinely exercises the failing branches (see below). |

### `SensitiveParameterLeakTest` is a real exercise, not a no-op

It spawns a child `php -d zend.exception_ignore_args=Off` (the setting a trace-logging framework uses; can't be toggled in-process), drives **both** leak-prone paths — (a) `Wallet` missing-`qi` validation, (b) `RsaPss` unloadable-key reaching the phpseclib catch — dumps `json_encode($e->getTrace())` for each, then asserts: both threw (`::MSG::` present, `::NOTHROW` absent), the intended branches were hit (`qi` + `Failed to load the RSA JWK into phpseclib.`), and the private `SENTINEL` appears **nowhere** in child output. Verified green in the Unit run.

---

## Secret custody — `src/` reads no env/files, logs nothing — CONFIRMED

`grep -rnE 'getenv|\$_ENV|\$_SERVER|\$_GET|\$_POST|file_get_contents|fopen|fwrite|file_put_contents|require|include|error_log|var_dump|print_r|syslog|exec|shell_exec|proc_open|system|eval|echo|print'` over `src/` yields **only** a docblock substring match ("chunk"). No I/O, no env, no logging, no shelling out. JWK arrives by value on the stack of `Wallet`/`RsaPss` only (`Wallet.php:10-15`, `RsaPss.php:18-20`).

`ArweaveClient::dispatch()` (`ArweaveClient.php:128-135`) wraps transport failures in a static-message exception, keeping any credential-bearing gateway URL only on the chained `previous` (per R1-F3, still in place).

---

## Fresh adversarial pass

- **Base64Url strictness** (`Base64Url.php:25`): regex `^[A-Za-z0-9_-]+$` rejects `+`, `/`, `=`, whitespace, and path segments (`../tx`); empty string allowed (the empty anchor). Verified live — all five malformed inputs rejected, `''` → `''`.
- **Forgery resistance**: a `random_bytes(256)` signature and an all-zero signature both return `false` from `RsaPss::verify()` under the full salt-fallback set `[32, 0, max]`; a too-short/garbage modulus returns `false` without throwing or leaking. Verified live.
- **Salt-length fallback** (`RsaPss.php:91`): trying salt {32, 0, max} on *verify* is standard PSS leniency and matches arweave-js's verifier. It does not weaken forgery resistance — an attacker still needs a valid EMSA-PSS encoding under the modulus; salt length is recovered from the encoded block, not a secret. `sign()` always uses salt 32 and self-verifies with salt 32. Benign.
- **`attachSignature`** (`Transaction.php:146-149`): assembles a `SignedTransaction` from a caller-supplied raw owner + signature *without* validating the signature. This is intentional (HSM/remote-signer + parity-suite path) and **not** a forgery vector: the gateway is authoritative and rejects any tx whose PSS signature doesn't validate against `owner` over the canonical message, and the id is deterministically `sha256(signature)` so a swapped signature yields a different id. No private key ever flows through it. The library makes no trust claim about an externally-supplied signature.
- **JWK validation**: `Wallet` requires all 8 RSA params as non-empty strings (`Wallet.php:39-43`), pins `e==="AQAB"` (`:45`), enforces a 2048-bit modulus floor (`:54`). `RsaPss` independently re-checks `kty==="RSA"`, `n`+`d` present, and `e==="AQAB"` since it is directly constructable (`RsaPss.php:31-39`). `verify()` hard-codes `e=AQAB` so a caller cannot smuggle a different exponent into verification (`RsaPss.php:84`).
- **`getData()` id smuggling** (`ArweaveClient.php:91-93`): id is regex-validated before URL interpolation; `../tx_anchor` rejected.

---

## Findings

No CRITICAL findings. No IMPORTANT findings.

**SUGGESTION (S1, non-blocking):** `RsaPss::verify()`'s salt-fallback iterates `[32, 0, maxSaltLength($modulus)]`. The leniency is correct and arweave-js-compatible, but a one-line code comment that the broadened salt set is *verify-only* (sign + self-verify are fixed at 32) would forestall a future refactorer mistakenly widening the *signing* salt. Documentation-only; zero security impact.

**SUGGESTION (S2, informational):** `attachSignature()`'s no-validation contract is well-documented in its docblock but could note explicitly that the gateway — not the library — is the signature authority, mirroring the (good) `SignedTransaction::toGatewayJson` perpetuity note. Optional.

Neither suggestion touches signed bytes, secret handling, or correctness; both are pure documentation polish.

---

## Verdict: PASS
