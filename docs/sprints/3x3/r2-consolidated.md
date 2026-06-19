# 3×3 R2 — Consolidated Findings & Fix Plan: `coopers98/arweave-php`

**Branch / HEAD reviewed:** `main` @ `15d966c`
**Round:** R2 (gpt-5.5 reviewers, three lenses)
**Consolidated by:** main session orchestrator

## Verdicts
| Lens | Verdict | CRITICAL | IMPORTANT | SUGGESTION |
|---|---|---:|---:|---:|
| Spec/API & Packaging | PASS | 0 | 0 | 2 |
| Security / Crypto | NEEDS-FIXES | 0 | 1 | 0 |
| Maintainability / OSS | NEEDS-FIXES | 0 | 1 | 4 |

**No CRITICAL findings.** Crypto byte-parity vs arweave-js 1.15.5 independently re-verified (DeepHash, v2 message order, Merkle data_root/proofs, id derivation, multi-chunk `data:""`, RSA-PSS). No signed-bytes change is in scope for this fix round — guard the parity gate stays green.

---

## IMPORTANT — must fix

### IMP-1 (security) — Private JWK can leak via current-exception trace args
**Files:** `src/Wallet.php` (ctor), `src/Crypto/RsaPss.php` (ctor), `tests/Unit/RsaPssTest.php`
R1 F1 removed the chained phpseclib exception, but the decoded private JWK is still a plain constructor parameter on `Wallet::__construct(array $jwk)` and `RsaPss::__construct(array $jwk)`. With `zend.exception_ignore_args=Off`, structured `$e->getTrace()` args can still capture `d/p/q/dp/dq/qi`. Confirmed reproducible by the reviewer.
**Fix:**
- Add `#[\SensitiveParameter]` to the decoded-JWK param on both constructors: `__construct(#[\SensitiveParameter] array $jwk)`.
- Keep R1's static-message / no-`previous` handling in `RsaPss`.
- Add a regression that runs in a **separate PHP process** with `zend.exception_ignore_args=Off` and asserts `json_encode($e->getTrace())` contains no private sentinel values, for BOTH: (a) a `Wallet` validation failure (e.g. missing `qi`), and (b) a `RsaPss` load failure reaching the phpseclib catch.

### IMP-2 (maintainability) — Golden-fixture regeneration not reproducible (Node lockfile ignored)
**Files:** `.gitignore`, `README.md` (~146-149), `tools/package.json`
README promises reproducible fixtures, but `tools/package-lock.json` is gitignored/untracked, so a fresh `npm install` can drift transitive deps.
**Fix:** Remove `/tools/package-lock.json` from `.gitignore`, commit `tools/package-lock.json`, and document `npm ci` (not `npm install`) for regeneration in README + CONTRIBUTING.

---

## SUGGESTION — apply if cheap & safe (no crypto changes)

- **SUG-A (spec):** README "Public API" — spell out full `attachSignature(string $rawOwner, string $signature, string $reward, string $lastTx): SignedTransaction` signature (`README.md:93`).
- **SUG-B (spec):** README custody prose — replace product-specific `SOVEREIGN_WALLET_SECRET` example with a generic one (e.g. `ARWEAVE_WALLET_JWK`); the lib reads no env var (`README.md:118`).
- **SUG-C (maint):** Remove stale "plain phpunit" claims in `tests/helpers.php:7-9` and `tests/bootstrap.php:6-9` (Pest binary is required; plain phpunit errors).
- **SUG-D (maint):** Fix stale monorepo naming in contributor comments: `tools/generate-golden.cjs:3,7` and `tests/Integration/ArLocalTest.php:22-23` (`agentimprint/arweave-php`, `packages/arweave-php` → standalone repo path).
- **SUG-E (maint):** Pint documented-by-process but absent — either add `laravel/pint` dev dep + a `composer` script, or document "style enforced by review." Prefer adding the dev dep + script for OSS polish.
- **SUG-F (maint):** Add a short docblock/guard noting `Merkle::dataRoot('')` (root of zero-length chunk) intentionally differs from `Transaction::create('')->dataRoot()` (`''`), to prevent future-refactor misuse (`src/Crypto/Merkle.php:33-60`, `src/Transaction.php:42-50`).

---

## Guardrails for the fix agent
- Single writer; verify `git rev-parse --short HEAD` == `15d966c` on `main` before editing, and that no other `claude -p` process is active in this repo.
- DO NOT regenerate `golden.json` or touch any signed-bytes path (DeepHash / Merkle / Transaction message order / RsaPss params / id derivation). Parity tests must stay byte-for-byte green.
- Run the offline gate `./vendor/bin/pest --testsuite Unit` + full `./vendor/bin/pest` (ArLocal if reachable). Report counts before→after; test count must increase (new SensitiveParameter regression).
- agentimprint-family policy: **no PR, no Copilot.** Commit + push to `main`, write `docs/sprints/3x3/r2-fixes.md` disposition table, then stop and report.
