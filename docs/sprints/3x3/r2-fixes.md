# 3×3 R2 — Fixes Applied: `coopers98/arweave-php`

**Branch / HEAD before:** `main` @ `15d966c`
**Applied by:** R2 fix agent (single writer)
**Source of truth:** [`r2-consolidated.md`](r2-consolidated.md) (+ `r2-security.md`, `r2-maint.md`)

## Parity gate (crypto byte-path)

`git diff --stat tests/fixtures/golden.json` → **empty** (golden fixtures untouched). No
signed-bytes logic changed: `DeepHash`, `Merkle` (logic), the v2 signature-message field
order in `Transaction`, RSA-PSS params in `RsaPss`, and id derivation in `SignedTransaction`
are all byte-for-byte unchanged. The only `src/` edits are two `#[\SensitiveParameter]`
attribute annotations (signature metadata, not bytes) and one Merkle docblock. The parity
suites (`TransactionParityTest`, `GatewayBodyParityTest`, `ChunkProofParityTest`,
`MerkleTest`) stay green.

## Test counts (before → after)

| Suite | Before | After |
|---|---|---|
| Unit (`pest --testsuite Unit`) | 57 tests / 222 assertions | **58 tests / 229 assertions** |
| Full (`pest`, ArLocal reachable) | 60 tests / 232 assertions | **61 tests / 239 assertions** |

Unit count increased by the new `tests/Unit/SensitiveParameterLeakTest.php` regression
(IMP-1). ArLocal v1.1.66 was reachable; the 3 Integration tests ran (no skips).
`composer validate --strict` → valid. `composer lint` (pint) → passed.

## Disposition

| Finding | Disposition | Notes |
|---|---|---|
| **IMP-1** (security, BLOCKING) — private JWK leak via trace args | **Fixed** | Added `#[\SensitiveParameter]` to the decoded-JWK param on `Wallet::__construct` and `RsaPss::__construct`. Kept R1's static-message / no-`previous` handling in `RsaPss`. New out-of-process regression (`SensitiveParameterLeakTest`) runs a child `php -d zend.exception_ignore_args=Off` that drives (a) a `Wallet` missing-`qi` validation failure and (b) a `RsaPss` unloadable-key failure reaching the phpseclib catch, dumps `json_encode($e->getTrace())` for each, and asserts a private sentinel embedded in `d/p/q/...` appears nowhere in the child output. Verified the child reaches both branches (asserts the `qi` and "Failed to load…" messages) so it can't pass by no-op. |
| **IMP-2** (maint, BLOCKING) — Node lockfile ignored / non-reproducible fixtures | **Fixed** | Removed `/tools/package-lock.json` from `.gitignore`; regenerated the lockfile to match the now-exact-pinned `tools/package.json` (the committed lockfile had stale `^` ranges that would fail `npm ci`); committed `tools/package-lock.json` (254 KB). `npm ci --dry-run` confirms in-sync. README + CONTRIBUTING now say `npm ci` (not `npm install`). `tools/node_modules` and `tools/logs` remain gitignored/untracked (verified via `git check-ignore`); `node_modules` not committed. |
| **SUG-A** (spec) — README `attachSignature` signature | **Fixed** | README "Public API" now shows the full `attachSignature(string $rawOwner, string $signature, string $reward, string $lastTx): SignedTransaction` (verified against `src/Transaction.php:146`). |
| **SUG-B** (spec) — product-specific env example | **Fixed** | README custody prose replaces `SOVEREIGN_WALLET_SECRET` with a generic `ARWEAVE_WALLET_JWK` example and notes the library itself reads no env var. |
| **SUG-C** (maint) — stale "plain phpunit" comments | **Fixed** | Reworded the `tests/bootstrap.php` and `tests/helpers.php` header comments to drop "plain phpunit" and state the suite is always run via `pest` (the package's own binary or an outer `pest` against this `phpunit.xml`). |
| **SUG-D** (maint) — stale monorepo naming | **Fixed** | `tools/generate-golden.cjs` (`agentimprint/arweave-php`, `packages/arweave-php/tools`) and `tests/Integration/ArLocalTest.php` (`packages/arweave-php/...`) contributor comments now use the standalone `coopers98/arweave-php` repo paths (`tools/`, repo root). Also switched the generate-golden comment to `npm ci`. |
| **SUG-E** (maint) — pint absent | **Fixed** | Added `laravel/pint:^1.0` dev dep + composer scripts (`lint` = `pint --test`, `lint:fix` = `pint`, `test` = `pest --testsuite Unit`). Ran pint once: it fixed two pre-existing test files (`ArweaveClientErrorTest`, `TransactionGuardTest`); no crypto-source files were touched. `pint --test` now passes clean. |
| **SUG-F** (maint) — `Merkle::dataRoot('')` vs `Transaction::dataRoot()` divergence | **Fixed** | Added a docblock to `Merkle::dataRoot()` warning refactorers that `''` yields the root of a zero-length chunk, intentionally differing from `Transaction::create('')->dataRoot()` which returns `''`. Comment-only; no logic change. |

## Deviations / concerns

- **`composer.lock` is gitignored** (existing library convention), so the new `laravel/pint`
  pin lives only in the lockfile, not committed. Consistent with prior project state — not
  changed.
- Pint uses the default Laravel preset (no `pint.json` added); it agreed with the existing
  code style apart from the two test files it auto-fixed. No `src/` reformatting occurred.
- The R2 review docs (`r2-consolidated.md`, `r2-security.md`, `r2-maint.md`, `r2-spec.md`)
  were untracked; committed alongside this disposition so the review record persists in-repo.
