# 3×3 Round 3 — SPEC/API & PACKAGING review: `coopers98/arweave-php`

- **Repo / HEAD:** `coopers98/arweave-php` @ `main` / `8d04f28`
- **Lens:** Spec/API compliance & OSS packaging hygiene
- **Round:** 3 of 3 (final). Confirms R2 SPEC/PACKAGING fixes landed + fresh pass.
- **Verdict: PASS**

## Verification commands run

| Command | Result |
| --- | --- |
| `composer validate --strict` | **PASS** — "composer.json is valid" |
| `composer install -q && ./vendor/bin/pest --testsuite Unit` | **PASS** — 58 passed (229 assertions), 2.62s |
| `git archive HEAD \| tar -t` | Ships only `src/` + CHANGELOG/CONTRIBUTING/LICENSE/README/SECURITY/composer.json — tests/tools/docs/.github/phpunit.xml all export-ignored ✓ |
| `git ls-files` | `tools/package-lock.json` tracked (IMP-2) ✓; `composer.lock` NOT tracked (correct for a library) ✓; no `node_modules` tracked ✓ |
| `npm ci --dry-run` (in `tools/`) | "up to date" — lockfile in sync, fixtures reproducible (IMP-2) ✓ |
| `git tag` | No tags — consistent with README/CHANGELOG "not yet tagged/published" ✓ |
| `git check-ignore tools/node_modules tools/logs` | Both ignored ✓ |

## R2 fix confirmations (this lens)

- **S9 (param-order swap `reward, lastTx`)** — consistently applied: `src/Transaction.php:96` (`signatureMessage`), `:130` (`sign`), `:146` (`attachSignature`), and README `:90-93`. README↔code 1:1.
- **IMP-2 (Node lockfile tracked / reproducible fixtures)** — `tools/package-lock.json` tracked; `npm ci --dry-run` clean; `package.json` pins exact versions (`arlocal 1.1.66`, `arweave 1.15.5`); README:151 & CONTRIBUTING:38 say `npm ci`.
- **SUG-A (full `attachSignature` signature in README)** — README:93 shows full 4-arg signature, matches `src/Transaction.php:146`.
- **SUG-D (monorepo naming)** — `tools/generate-golden.cjs:3,7` and `tests/Integration/ArLocalTest.php` use standalone `coopers98/arweave-php` paths + `npm ci`. No `packages/arweave-php` or `agentimprint/arweave-php` package paths remain.

## Findings

### CRITICAL
None.

### IMPORTANT
None.

### SUGGESTION

- **SUG-1 (cosmetic, README constructor param-name drift).** README `:104` documents `ArweaveClient::__construct(..., ?RequestFactoryInterface $rf = null, ?StreamFactoryInterface $sf = null)`, but the code names them `$requestFactory` / `$streamFactory` (`src/ArweaveClient.php:33-34`). Positional usage is unaffected; only named-argument callers would be misled. Trivial doc nit — align the README param names or drop them.
- **SUG-2 (informational, namespace vs. package name).** PSR-4 namespace remains `AgentImprint\Arweave\` (`composer.json:28`) while the package is `coopers98/arweave-php`. This is intentional and explicitly documented (README:26-27 "namespace ≠ Packagist name"). No action required; flagged only so a future reviewer does not mistake it for leakage. The package *name*, repo paths, `composer require` line, and generator script are all clean `coopers98/arweave-php`.

## Notes / non-issues checked

- Runtime `require` is minimal (`composer.json:7-15`): php ^8.2, ext-hash, ext-openssl, phpseclib ^3.0, PSR http-client/http-factory/http-message. Guzzle + Nyholm are `require-dev` (`:16-21`) and `suggest` (`:22-25`) only — correct.
- The residual `AgentImprint` strings outside the namespace are the `'App' => 'AgentImprint'` example/fixture **tag values** (README:55, tests, generator) — legitimate sample app-tag content, not package identity. Not leakage.
- No ANS-104 / Turbo / bundler **code** in `src/` — the only `dispatch(` hits are an internal private PSR-18 helper (`ArweaveClient.php:128`), not a bundler. Roadmap prose in docblocks (e.g. `SignerInterface.php:8-10`) is allowed per lens scope.
- CI workflow present (`.github/workflows/ci.yml`): PHP 8.2/8.3/8.4 matrix, `composer validate --strict`, runs the offline Unit byte-parity gate. PR-only trigger is per project Actions-minutes policy.
- CHANGELOG.md present, Keep-a-Changelog format, `[0.1.0] — pending`, marked not-yet-tagged — consistent with the empty `git tag` state.

## Verdict: PASS

All R2 SPEC/PACKAGING fixes landed and verified. No CRITICAL or IMPORTANT findings. Two
cosmetic SUGGESTIONs (README constructor param-name drift; namespace/package-name note)
do not block release.
