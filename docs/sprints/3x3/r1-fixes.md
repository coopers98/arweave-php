# 3×3 R1 — Fix Report: `coopers98/arweave-php`

**Branch:** `main` · **Base:** `a8bdb76` · **Round:** R1 fixes (applied by the R1 fix agent)
**Test counts:** Unit 46 → **57** · Full (incl. ArLocal) 49 → **60** · parity gate **byte-for-byte green** · `composer validate` **OK**

No signed bytes were altered. DeepHash, Merkle, the v2 signature-message field order, the
RSA-PSS params (salt 32 / MGF1-SHA-256 / SHA-256), and id derivation are unchanged;
`TransactionParityTest`, `GatewayBodyParityTest`, `ChunkProofParityTest`, and `MerkleTest`
remain green and `golden.json` was NOT regenerated.

---

## MUST FIX (F1–F7)

| # | Disposition | Detail |
|---|-------------|--------|
| **F1** | ✅ Fixed | `RsaPss::__construct` now catches the load failure with `catch (Throwable)` and throws a **static-message** `ArweaveException` — no `previous`, no `$e->getMessage()`. The caught phpseclib exception's stack-trace frame args carried the full private JWK; they no longer propagate. New test `a failed JWK load never leaks key material…` asserts the JWK `n/d/p/q` values appear nowhere in the message + trace of the exception **or its (null) chain**, and that `getPrevious()` is null. Verified the input genuinely hits the `loadFormat` catch (phpseclib throws `SodiumException`). |
| **F2** | ✅ Fixed | `RsaPss::sign()` now self-verifies the produced signature against its own public key (`$this->key->getPublicKey()` via `configure()`) and throws `ArweaveException` on mismatch before returning. New test `a freshly produced signature self-verifies…`. (The failure branch needs fault injection to hit directly; the guard + happy-path assertion is the unit-testable surface.) |
| **F3** | ✅ Fixed | New `tests/Unit/ArweaveClientErrorTest.php` with canned PSR-18 clients: 404 → `null`, ≥400 → typed `ArweaveException`, transport exception → wrapped. Every throw asserts the gateway host/userinfo (`vault.example`, `s3cr3t`, `user:`) is absent from the message. Covers both `getData()` and the `send()` path (`price`/`anchor`). |
| **F4** | ✅ Fixed | `.github/workflows/ci.yml` — `composer validate` → `composer install` → `vendor/bin/pest --testsuite Unit` on a PHP 8.2/8.3/8.4 matrix, **`pull_request`-only** (no push, no schedule). |
| **F5** | ✅ Fixed | `.gitattributes` with `export-ignore` for `tests/`, `tools/`, `docs/`, `.github/`, `phpunit.xml`, `.gitignore`, `.gitattributes`. |
| **F6** | ✅ Fixed | `/logs` added to `.gitignore`; stray root `logs` file deleted. |
| **F7** | ✅ Fixed | `CHANGELOG.md` seeded (Keep-a-Changelog), `0.1.0` as a **pending, un-tagged** initial release. **No git tag created** — tagging + Packagist publish left to Cooper. |

## SUGGESTIONs (S1–S16)

| # | Disposition | Detail |
|---|-------------|--------|
| **S1** | ✅ Applied | README testing note no longer claims a bare `phpunit` run works (Pest replaces the binary); CONTRIBUTING says the same. |
| **S2** | ✅ Applied | `Transaction::$chunks` is now non-nullable `array` (always assigned in the ctor); dropped the dead `?? […]` fallback in `assemble()`. |
| **S3** | ✅ Applied | `RsaPss::configure()`'s `PublicKey` branch is now live — F2's self-verify routes the public key through `configure()`, so the method is exercised rather than dead. |
| **S4** | ✅ Applied | Extracted a private `dispatch()` helper; `send()` and `getData()` share the single transport-wrap. Genuinely DRY and pinned by the new F3 tests (no behavior change — `submit`/`postChunks` still go through `send()`). |
| **S5** | ✅ Applied | `CONTRIBUTING.md` added (test commands, golden regen, ArLocal how-to, "don't hand-edit golden.json", crypto-change warning). |
| **S6** | ✅ Applied | `tests/Unit/TransactionGuardTest.php` — `signatureMessage()` "Owner must be set" guard + `Merkle::dataRoot()` wrapper agrees with `generateTransactionChunks()`. `discoverFactory()` throw left as-is (covered indirectly; acceptable). |
| **S7** | ✅ Applied | README "pre-extraction"/monorepo path-repository note replaced with a clean standalone "not yet on Packagist" note. |
| **S8** | ⚠️ Kept (justified, not deferred) | `ext-openssl` kept as a hard `require`. phpseclib *can* run RSA pure-PHP, but PSS over Arweave's 4096-bit keys without the openssl big-integer/RSA acceleration is prohibitively slow for a signing library; the README/composer already advertise it as a requirement. Documented here rather than dropped. |
| **S9** | ✅ Applied | Aligned param order: `signatureMessage(string $reward, string $lastTx)` now matches `sign(…, $reward, $lastTx)` and the deep-hash field order (reward before last_tx). All call sites updated (3 in `TransactionParityTest`, the internal `sign()`, README). Parity gate re-run **byte-for-byte green** — the swap maps args correctly. |
| **S10** | ✅ Applied | `SECURITY.md` added (private reporting, scope, hardening guarantees). |
| **S11** | ✅ Applied | `RsaPss::__construct` now rejects a non-`AQAB` public exponent (Wallet already did; RsaPss is constructable directly). New test. |
| **S12** | ✅ Applied | `tools/package.json` pins `arweave` 1.15.5 and `arlocal` 1.1.66 **exactly** (+ a `comment` field on why); README testing section notes the pinned trust anchor. |
| **S13** | ✅ Applied | `getData()` validates the id is unpadded base64url (`^[A-Za-z0-9_-]+$`) before interpolating it into the URL, blocking path/query smuggling. New test rejects `../tx_anchor`. |
| **S14** | ✅ Applied | `Wallet` enforces a **2048-bit minimum** modulus floor (rejects trivially weak keys; production Arweave keys are 4096-bit, test fixtures 2048-bit so the floor sits at 2048). Documented inline. |
| **S15** | ✅ Applied | README Scope section now documents the data-only / zero-value scope explicitly (`target=""`, `quantity="0"`, no AR value transfer). |
| **S16** | ✅ Applied | `Merkle::intToBuffer()` docblock notes the 64-bit (`PHP_INT_SIZE === 8`) offset assumption and the 32-bit truncation caveat. |

## Deferred

None. F1–F7 fully resolved; S1–S16 all applied except **S8**, which was deliberately
**kept and justified** (ext-openssl is a real performance requirement) rather than deferred.

## Verification

- `./vendor/bin/pest --testsuite Unit` → **57 passed (222 assertions)** — offline gate green.
- `./vendor/bin/pest` (incl. ArLocal v1.1.66 integration) → **60 passed (232 assertions)**.
- `composer validate` → valid (exit 0).
- `git diff --stat tests/fixtures/golden.json` → empty (golden NOT regenerated).
- No debug code / TODOs / hardcoded secrets; no `logs`/`vendor`/`node_modules` committed.
