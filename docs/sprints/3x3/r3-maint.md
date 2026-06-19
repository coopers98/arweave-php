# 3×3 Round 3 — MAINTAINABILITY & OSS-READINESS: `coopers98/arweave-php`

**Repo:** `/root/projects/arweave-php` · **Branch:** `main` · **HEAD:** `8d04f28`
**Round:** R3 (final) · **Lens:** maintainability + OSS-readiness · **Mode:** READ-ONLY (nothing edited/staged/committed)
**Reviewer:** R3 maint agent

---

## Verification run (verify, don't trust)

| Command | Result |
|---|---|
| `composer install -q` | exit 0 |
| `./vendor/bin/pest --testsuite Unit` (offline) | **58 passed / 229 assertions** — exit 0 |
| `./vendor/bin/pest` (full, ArLocal reachable) | **61 passed / 239 assertions** (incl. 3 Integration) — exit 0 |
| `./vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` — exit 0 |
| `composer validate --strict` | `./composer.json is valid` — exit 0 |
| `git status --porcelain` | empty (clean working tree, no stray untracked artifacts) |
| `git ls-files \| grep -E 'vendor\|node_modules\|/logs'` | empty (no tracked build artifacts) |

Counts match R2's recorded after-state (58 unit / 61 full). ArLocal v1.x reachable; no integration skips.

---

## R2 fix confirmation (all landed)

- **R2-IMP2** (Node lockfile reproducibility): `tools/package-lock.json` is now **tracked** (`git ls-files tools/` → `generate-golden.cjs`, `package-lock.json`, `package.json`). `tools/node_modules` / `tools/logs` remain gitignored. README (lines 151–155) and CONTRIBUTING (lines 34, 38–40) document `npm ci` (not `npm install`) for reproducible golden-vector regeneration, with the drift rationale. **Confirmed.**
- **SUG-E** (pint): `laravel/pint:^1.0` dev dep present; composer `lint` / `lint:fix` / `test` scripts present. `pint --test` clean. **Confirmed.**
- **SUG-C** ("plain phpunit"): zero `plain phpunit` matches in README/CONTRIBUTING/tests. `tests/helpers.php` header and README §test section reworded to "run via pest, no plain-phpunit entry point." **Confirmed.**
- **SUG-F** (Merkle divergence): `src/Crypto/Merkle.php:60–66` carries the docblock warning that `dataRoot('')` returns the root of a zero-length chunk, deliberately differing from `Transaction::create('')->dataRoot()` which returns `''`. **Confirmed.**
- **SUG-D** (monorepo naming): no `packages/` or `agentimprint/arweave-php` repo-path refs left in `tools/generate-golden.cjs` / `tests/Integration/ArLocalTest.php`; generator header now says `coopers98/arweave-php` and `npm ci`. (The remaining `App: AgentImprint` strings are golden test-data tag values, not naming defects — they must stay to match the committed vectors.) **Confirmed.**

---

## Findings

### CRITICAL
None.

### IMPORTANT
None.

### SUGGESTION

- **SUG-1 — LICENSE / package-name mismatch (cosmetic).** `LICENSE:3` reads `Copyright (c) 2026 AgentImprint`, while the published package is `coopers98/arweave-php` and `composer.json` carries no `authors` block. Not a license-validity issue (MIT is intact and `composer validate --strict` passes), but a Packagist visitor sees an owner name unrelated to the namespace. Consider aligning the copyright holder and/or adding a composer `authors` entry. Non-blocking.

- **SUG-2 — `composer.lock` gitignored (pre-existing library convention).** `.gitignore:2` ignores `composer.lock`; the file exists on disk (179 KB) but is untracked. This is the standard PHP-library convention, so the `laravel/pint` pin lives only in each developer's local lock, not in-repo. Acceptable and explicitly carried over from R2; flagged again only for completeness. Non-blocking.

---

## Previously-noted minor items — re-checked

All three are now **resolved / no longer applicable** (no fix needed):

- **`Transaction::$chunks` nullable fallback** — `src/Transaction.php:32` types it as non-nullable `private array $chunks`; the empty-data path (`:44`) assigns a fully-populated `['data_root' => '', 'chunks' => [], 'proofs' => []]` shape, so the field is always a valid struct (no `?? null` fallback at read sites). **Closed.**

- **Dead `RsaPss::configure()` PublicKey branch** — `src/Crypto/RsaPss.php:65` runs a post-sign self-verify via `configure($this->key->getPublicKey())`, with an explicit comment (`:64`) noting this exercises `configure()`'s `PublicKey` path. The branch is live and covered. **Closed.**

- **`getData()` / `send()` transport duplication** — both now delegate to a single private `dispatch()` helper (`src/ArweaveClient.php:128–135`) for the PSR-18 send + credential-safe error wrapping. `getData()` keeps only its distinct 404→`null` semantics (`:97–99`); `send()` throws on all ≥400. The shared transport/error-wrap path is no longer duplicated. **Closed.**

---

## Code-quality / OSS-hygiene scorecard

| Check | Result |
|---|---|
| `declare(strict_types=1)` in every `src/*.php` | 10/10 present |
| `final` on all concrete classes | 9/9 (`ArweaveException`, `ArweaveClient`, `Transaction`, `Wallet`, `DeepHash`, `RsaPss`, `Merkle`, `SignedTransaction`, `Base64Url`) + `SignerInterface` interface |
| Single exception type | `ArweaveException extends RuntimeException`; all 19 `throw new` sites use it |
| Debug / dead-code markers (`var_dump`/`dd`/`die`/`exit`/`TODO`/`FIXME`/`HACK`) | none in `src/` or `tests/` |
| Golden / known-answer tests | `tests/fixtures/golden.json` (34 KB, arweave-js-derived); parity suites assert byte-for-byte equality and guard against empty vectors |
| Offline transport error paths | `ArweaveClientErrorTest` covers 404→null, ≥400→typed throw, transport failure→wrapped (creds never in public message) via mock PSR-18 clients |
| Helper-loading robustness | `tests/helpers.php` double-registered (composer `autoload-dev.files` + bootstrap), each def `function_exists`-guarded |
| Tracked build artifacts | none (`vendor`, `node_modules`, `logs`, `.phpunit.result.cache` all gitignored & untracked) |
| `.gitignore` completeness | covers vendor, composer.lock, phpunit caches, tools/node_modules, tools/logs, logs |
| `.gitattributes` dist hygiene | `export-ignore` on tests/tools/docs/.github/phpunit.xml — dist tarball ships src + metadata only |
| LICENSE / CHANGELOG / CONTRIBUTING / SECURITY | all present; MIT; Keep-a-Changelog format; CONTRIBUTING documents the full offline + regeneration flow |
| README install UX | accurate (`composer require coopers98/arweave-php` with not-yet-published note; PSR-18/17 install hint; `npm ci` regen flow) |
| `composer validate --strict` | valid |

---

## Verdict: **PASS**

All R2 maintainability fixes are confirmed in-tree. The full verification matrix is green (61/61 tests, pint clean, strict composer validate, clean working tree, no tracked artifacts). The three previously-noted minor items are all closed. The only open findings are two non-blocking cosmetic SUGGESTIONs (LICENSE copyright holder vs. package name; the conventional gitignored `composer.lock`). The library is maintainable and OSS-ready.
