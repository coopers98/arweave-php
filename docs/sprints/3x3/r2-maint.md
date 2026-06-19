# 3x3 Round 2 - Maintainability & OSS-Readiness Review

**Package:** `coopers98/arweave-php`  
**Branch / HEAD:** `main` @ `15d966cc7c7964005765999e04d3e35e3a10d056`  
**Mode:** READ-ONLY review; no code edits/staging/commits.

## Verification

| Check | Result |
|---|---|
| `composer install -q` | PASS |
| `./vendor/bin/pest --testsuite Unit` | PASS - **57 tests, 222 assertions** |
| `./vendor/bin/pest` | PASS - **60 tests, 232 assertions**; ArLocal integration was reachable and ran, no skips |
| Package Pest binary helper loading | PASS - `golden()` / `patternBytes()` loaded through the package Pest path |
| Root invocation helper loading | PASS - from `/root`: `/root/projects/arweave-php/vendor/bin/pest -c /root/projects/arweave-php/phpunit.xml --testsuite Unit` -> **57 tests, 222 assertions** |
| Plain `./vendor/bin/phpunit --testsuite Unit` | Expected failure - Pest reports `Please run [./vendor/bin/pest] instead`; README/CONTRIBUTING now say this correctly |
| `composer validate --strict` | PASS - `composer.json is valid` |
| `php -l` over `src/` + `tests/` | PASS - no syntax errors |
| `./vendor/bin/pint` | Not available - no Pint dev dependency/binary |
| Tracked artifacts | PASS - `git ls-files` contains no `vendor/`, `node_modules/`, or `logs` |

Ignored local artifacts are present after install/test runs (`vendor/`, `tools/node_modules/`, `composer.lock`, `tools/package-lock.json`, `.phpunit.result.cache`, `logs`, `tools/logs`), but none are tracked.

## R1 Fix Verification

- Transport-error coverage landed: `tests/Unit/ArweaveClientErrorTest.php` covers `getData()` 404 -> `null`, `getData()` >=400 -> typed `ArweaveException`, transport failure wrapping, and credential scrubbing.
- CI landed: `.github/workflows/ci.yml` runs `composer validate`, `composer install`, and `vendor/bin/pest --testsuite Unit` on PHP 8.2/8.3/8.4 for PRs.
- Stray root `logs` is ignored via `.gitignore`; no logs file is tracked.
- R1 dead-code notes were addressed:
  - `Transaction::$chunks` is now non-nullable and the dead `??` fallback is gone (`src/Transaction.php:31-32`, `src/Transaction.php:151-165`).
  - `RsaPss::configure()`'s `PublicKey` path is live through self-verification after signing (`src/Crypto/RsaPss.php:60-66`, `src/Crypto/RsaPss.php:129-135`).
  - `ArweaveClient` has a shared `dispatch()` transport wrapper used by both `send()` and `getData()` (`src/ArweaveClient.php:95`, `src/ArweaveClient.php:108-134`).

## Code Quality

Overall maintainability is strong: every PHP file declares `strict_types=1`; source classes are `final`; public/private methods have return types; source exceptions consolidate on `ArweaveException`; no `TODO`/`FIXME`/`var_dump`/`dd()`/debug code was found in `src/` or `tests/`. Some mutable properties could be `readonly`, but the current style is consistent and not a blocker.

## Test Quality

The correctness suite is genuine known-answer coverage, not smoke testing. Golden vectors from `arweave-js` pin DeepHash, Merkle `data_root`, chunk proof `data_path` bytes, transaction IDs, and full serialized `POST /tx` JSON. R1 added the missing offline transport error tests, so the main remaining test caveat is process/tooling rather than crypto coverage.

## CRITICAL

None.

## IMPORTANT

### IMP-1 - Golden fixture regeneration is not fully reproducible because the Node lockfile is ignored

**Files:** `.gitignore:6`, `README.md:146-149`, `tools/package.json:6-10`

The README promises reproducible fixture regeneration because `tools/package.json` pins `arweave` and `arlocal` exactly. That pins the top-level packages, but the generated `tools/package-lock.json` is ignored and untracked, so a fresh `npm install` can still resolve different transitive dependency versions over time. For a crypto/parity package, fixture regeneration should be deterministic enough that a contributor can review intentional vector changes without hidden dependency drift.

**Fix:** track `tools/package-lock.json`, remove `/tools/package-lock.json` from `.gitignore`, and document `npm ci` for regeneration; or weaken the README's reproducibility claim. I recommend tracking the lockfile.

## SUGGESTION

### SUG-1 - Stale helper/bootstrap comments still claim plain phpunit support

**Files:** `tests/helpers.php:7-9`, `tests/bootstrap.php:6-9`

README/CONTRIBUTING now correctly say to run Pest, not plain phpunit, but the helper/bootstrap comments still include "plain phpunit". I verified `./vendor/bin/phpunit --testsuite Unit` fails with Pest's `InvalidPestCommand`, so these comments should be aligned with the docs and the actual supported invocations.

### SUG-2 - Standalone OSS comments still reference the old monorepo/package naming

**Files:** `tools/generate-golden.cjs:3`, `tools/generate-golden.cjs:7`, `tests/Integration/ArLocalTest.php:22-23`

The public docs use the standalone repo path, but internal contributor-facing comments still mention `agentimprint/arweave-php` and `packages/arweave-php`. This is harmless at runtime, but it is stale OSS polish.

### SUG-3 - Pint is documented by process but not available in the package

**File:** `composer.json:16-20`

There is no `laravel/pint` dev dependency or `composer` script, so reviewers cannot mechanically run the expected style fixer. The code already appears consistently formatted, but an OSS package should either add a formatter script/dev dependency or explicitly document that style is enforced by review only.

### SUG-4 - `Merkle::dataRoot('')` and `Transaction::dataRoot()` intentionally diverge for empty data without a guard/comment

**Files:** `src/Crypto/Merkle.php:33-60`, `src/Transaction.php:42-50`, `tests/Unit/TransactionParityTest.php:135-162`

`Transaction::create('')->dataRoot()` returns `''`, matching the empty-data transaction parity vector. `Merkle::dataRoot('')`, however, computes the root of a zero-length chunk. That may be correct for the internal `generateTransactionChunks()` helper, but the public static method name is easy to misuse during future refactors. A short docblock note or guard would prevent accidentally replacing the transaction special case with the Merkle wrapper.

## Verdict: NEEDS-FIXES

No runtime/crypto correctness blockers were found, and all suites pass. The only IMPORTANT item is OSS reproducibility for golden-vector regeneration: track the Node lockfile or adjust the reproducibility claim.
