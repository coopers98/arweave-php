# 3x3 Round 2 - Security / Crypto Review

Package: `coopers98/arweave-php`  
Branch / commit reviewed: `main` @ `15d966cc7c7964005765999e04d3e35e3a10d056`  
Mode: READ-ONLY review. No source edits, commits, or staging.

## Summary

| Severity | Count |
| --- | ---: |
| CRITICAL | 0 |
| IMPORTANT | 1 |
| SUGGESTION | 0 |

Headline: the byte-level crypto path still matches `arweave-js` 1.15.5: DeepHash, v2 signature-message order, Merkle `data_root`/proofs, transaction id derivation, multi-chunk `data:""` body behavior, and RSA-PSS verification all passed the offline parity gate. I found one IMPORTANT secret-custody gap: the R1 private-key trace-leak fix removed the chained phpseclib exception, but the constructors still accept the decoded private JWK as a normal PHP parameter, so structured trace logging with args enabled can still capture the private key from the current exception's trace.

## Verification Performed

- Required offline gate: `composer install -q && ./vendor/bin/pest --testsuite Unit` -> 57 passed, 222 assertions.
- Integration check: `./vendor/bin/pest --testsuite Integration` -> 3 passed, 10 assertions. ArLocal was reachable; integration did not skip.
- Confirmed dev reference versions from installed tools: `arweave` 1.15.5 and `arlocal` 1.1.66.
- Ran an independent PHP -> arweave-js dynamic cross-check with a fresh RSA JWK: arweave-js 1.15.5 recomputed the same signature message, same `data_root`, same id from the PHP signature, and verified the PHP RSA-PSS signature.
- Scanned committed `tests/fixtures/golden.json` for private JWK fields (`d`, `p`, `q`, `dp`, `dq`, `qi`) and found none.

## Verified Good

- DeepHash matches arweave-js's SHA-384 blob/list construction: PHP tags `blob`/`list` with decimal ASCII lengths/counts in `src/Crypto/DeepHash.php:24-35`, matching arweave-js 1.15.5 `tools/node_modules/arweave/node/lib/deepHash.js:8-24`. The unit gate pins this in `tests/Unit/DeepHashTest.php:7-23`.
- The v2 signature message field order is correct: `[format, owner, target, quantity, reward, last_tx(decoded raw), tags, data_size, data_root]` in `src/Transaction.php:107-117`, matching arweave-js `tools/node_modules/arweave/node/lib/transaction.js:203-212`. The parity gate is cryptographic, not a shape check: it verifies arweave-js's actual signature against the message computed by this package in `tests/Unit/TransactionParityTest.php:28-37`.
- RSA-PSS signing uses SHA-256, MGF1-SHA256, PSS padding, and salt length 32 in `src/Crypto/RsaPss.php:57-69` and `src/Crypto/RsaPss.php:129-135`; the verifier also accepts arweave-js/webcrypto salt variants in `src/Crypto/RsaPss.php:78-104`.
- R1's self-verify guard is present: `RsaPss::sign()` verifies the fresh signature against its own public key before returning in `src/Crypto/RsaPss.php:60-67`.
- Merkle chunking and proofs mirror arweave-js, including 256 KiB max chunks, 32 KiB rebalance, and inclusion-then-discard of the trailing zero-length chunk in `src/Crypto/Merkle.php:33-54` and `src/Crypto/Merkle.php:66-99`. Boundary vectors and POST `/chunk` proof bytes are pinned in `tests/Unit/MerkleTest.php:8-35` and `tests/Unit/ChunkProofParityTest.php:20-55`.
- Multi-chunk `POST /tx` bodies emit `data: ""` while preserving full `data_size` and `data_root` in `src/SignedTransaction.php:58-77`; the gate asserts both multi-chunk and single-chunk paths in `tests/Unit/GatewayBodyParityTest.php:20-64`.
- Transaction id derivation is `base64url(sha256(signature))` in `src/SignedTransaction.php:38-40`, pinned against arweave-js ids in `tests/Unit/TransactionParityTest.php:39-44`.
- JWK exponent validation is enforced at both public entry points: `Wallet` rejects non-`AQAB` in `src/Wallet.php:45-47`, and direct `RsaPss` construction rejects non-`AQAB` in `src/Crypto/RsaPss.php:35-39`.
- Source custody check: `src/` contains no env/config/file secret loading and no logging calls. The only `file_get_contents()` in the test helper loads committed golden fixtures, not runtime secrets.

## IMPORTANT

### IMP-1 - Private JWK can still leak through current-exception trace args

Files:

- `src/Wallet.php:32-58`
- `src/Crypto/RsaPss.php:28-47`
- `tests/Unit/RsaPssTest.php:64-90`

The R1 F1 fix correctly changed the phpseclib load failure to a static-message `ArweaveException` with no `previous` in `src/Crypto/RsaPss.php:41-47`. That removes the chained phpseclib exception and its `RSA::loadFormat()` frame, which was the biggest leak.

But the decoded private JWK is still a normal constructor parameter on both `Wallet::__construct(array $jwk)` and `RsaPss::__construct(array $jwk)`. If an exception is thrown while those frames are active, PHP's structured trace can include the constructor argument when `zend.exception_ignore_args=Off` or when an error collector captures trace args. That means a validation failure in `Wallet` or a load failure in `RsaPss` can still expose `d/p/q/dp/dq/qi` via `$e->getTrace()`, even though `$e->getTraceAsString()` only prints `Array`.

I reproduced this read-only with a sentinel JWK:

```bash
php -d zend.exception_ignore_args=Off ...
```

Result: `json_encode($e->getTrace())` contained the private sentinel values for a `Wallet` validation failure, and the same pattern applies to `RsaPss` load failure. The existing regression in `tests/Unit/RsaPssTest.php:80-88` checks `getTraceAsString()` and the previous chain, but it does not inspect `getTrace()` argument arrays, so it can pass while structured trace args still carry the key.

Why this is IMPORTANT: this is the same class of bug as R1 F1, just one frame closer. It requires an exception path plus trace-argument logging, so I am not rating it CRITICAL, but it violates the library's "never logs secrets / no key material in traces" security contract for a funds-path signer.

Recommended fix:

- Add PHP 8.2+ `#[\SensitiveParameter]` to decoded JWK parameters:
  - `public function __construct(#[\SensitiveParameter] array $jwk)` in `Wallet`.
  - `public function __construct(#[\SensitiveParameter] array $jwk)` in `RsaPss`.
- Keep the R1 static-message/no-previous handling in `RsaPss`.
- Add a regression that runs in a separate PHP process with `zend.exception_ignore_args=Off` and asserts `json_encode($e->getTrace())` does not contain private sentinel values for both:
  - a `Wallet` validation failure, e.g. missing `qi`;
  - a `RsaPss` load failure that reaches the phpseclib catch.

## Verdict

Verdict: NEEDS-FIXES
