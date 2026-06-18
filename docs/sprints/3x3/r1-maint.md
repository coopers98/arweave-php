# 3×3 Round 1 — Maintainability Review

**Package:** `coopers98/arweave-php` (namespace `AgentImprint\Arweave`)
**Scope:** standalone OSS extraction — pure-PHP native-L1 Arweave tx signer
**Mode:** READ-ONLY (no code modified)
**Date:** 2026-06-18 · branch `main`

## Verification run

| Check | Result |
|-------|--------|
| `./vendor/bin/pest` (full suite, ArLocal reachable) | ✅ **49 passed, 208 assertions** (3.84s) |
| `./vendor/bin/pest --testsuite Unit` (offline gate) | ✅ **46 passed, 198 assertions** (1.64s) |
| Integration (`Tests\Integration\ArLocalTest`) | ✅ ran live (ArLocal v1.1.66 up): single-chunk round-trip, multi-chunk chunk uploads, unfunded→typed-exception |
| Linter (`./vendor/bin/pint`) | ⚠️ **not installed** — not a dev dependency; could not run PSR-12 check mechanically |
| Leftover ANS-104 / Turbo / bundler **code** | ✅ none — only intentional *future-scope* prose in README + `SignerInterface` docblock |
| TODO / FIXME / `var_dump` / `dd(` / `dump(` / debug | ✅ none in `src/` or `tests/` |
| PHP | 8.3.6 (package targets `^8.2`) |

**Canonical test command (document this):**
```bash
composer install
./vendor/bin/pest --testsuite Unit      # offline byte-parity + crypto gate
./vendor/bin/pest                        # + ArLocal integration (auto-skips if no node)
```

## Overall

Maintainability is **strong**. Code is uniformly `declare(strict_types=1)`, `final`,
`readonly` where appropriate, fully type-hinted (params + returns), single typed
exception (`ArweaveException`), and well-commented with *why* not just *what*. The
parity suite is genuine **golden / known-answer** coverage, not smoke tests:
arweave-js vectors pin deep-hash, `data_root` across every chunk boundary, multi-chunk
`data_path` proof bytes, full `POST /tx` JSON, and id derivation byte-for-byte. No
CRITICAL maintainability defects. Findings below are coverage gaps, OSS-readiness DX,
and small clarity nits.

---

## CRITICAL

None.

---

## IMPORTANT

### IMP-1 — `ArweaveClient` HTTP error paths have zero offline unit coverage
**`src/ArweaveClient.php:84-126`, `tests/Unit/ArweaveClientFactoryTest.php`**

The only offline test of `ArweaveClient` is the happy path (canned PSR-18 client
returns `200`). None of the error-handling branches are exercised without a network:
- `send()` 4xx → `ArweaveException` with `"{method} {path}"` message (`:118-123`)
- `getData()` `404` → `null` (`:97-99`)
- `getData()` ≥400 → throws (`:101-103`)
- transport `ClientExceptionInterface` → wrapped, message scrubbed of creds (`:91-95`, `:112-115`)

These are exactly the branches whose *whole purpose* is safety (credential scrubbing,
typed errors, 404-as-null), yet they're only touched by `ArLocalTest`, which
**auto-skips in offline CI**. The existing `fakeHttpClient()` pattern makes this
trivial to close.
**Fix:** add Unit cases with a canned client returning `404`/`500`/throwing —
assert `getData()` returns `null` on 404, `ArweaveException` on ≥400, and that the
thrown message does **not** contain the gateway URL (the chained previous does).

### IMP-2 — No CI workflow; the parity gate is not enforced automatically
**(repo root — no `.github/workflows/`)**

For a package being extracted to OSS, there is no automated CI running
`./vendor/bin/pest --testsuite Unit` on PRs. The byte-parity gate ("nothing built on
top of this is trustworthy until this suite is green" — `TransactionParityTest`) relies
on a human running it. A standalone repo should gate merges on the offline suite across
the supported matrix (PHP 8.2 / 8.3 / 8.4).
**Fix:** add a minimal GH Actions workflow: `composer install` → `vendor/bin/pest
--testsuite Unit` on a PHP version matrix. (Per project policy CI runs on PRs only.)

### IMP-3 — Stray `logs` file is not git-ignored and will be committed
**`.gitignore` (only ignores `/tools/logs`), repo-root `logs`**

Running the integration suite from the repo root makes ArLocal write a `logs` CSV into
the **project root**. `git status` shows `?? logs` — untracked and **not ignored** (the
`.gitignore` entry is `/tools/logs`, which doesn't match root `logs`). A careless
`git add -A` commits an ArLocal request log into the OSS package.
**Fix:** add `/logs` to `.gitignore` (and delete the stray file).

---

## SUGGESTION

### SUG-1 — README's "plain phpunit" robustness claim is literally unrunnable
**`README.md:139-142`, mirrored in `tests/helpers.php:5-11`, `tests/bootstrap.php:5-11`, `tests/Pest.php:5-9`**

The docs say the suite "runs identically under the package's own `vendor/bin/pest`, a
root/monorepo binary, **or plain `phpunit`**." But the pest plugin hijacks the phpunit
binary — `./vendor/bin/phpunit --testsuite Unit` errors with
`Pest\Exceptions\InvalidPestCommand: Please run [./vendor/bin/pest] instead.` The
helper-loading *mechanism* (composer `autoload-dev.files` + `bootstrap.php` + `Pest.php`,
all `function_exists`-guarded) is genuinely robust and is the right fix for the prior
root-binary breakage — but "plain phpunit" can't be invoked in this package, so the
wording overstates it.
**Fix:** drop "or plain phpunit" (or qualify as "a root/monorepo phpunit driving this
package's autoloader"). The triple-registration design is sound; only the prose is off.

### SUG-2 — `Transaction::$chunks` nullable + unreachable `??` fallback
**`src/Transaction.php:32, 163`**

`$chunks` is typed `?array`, but **both** constructor branches assign it (`:43-45`
empty, `:49` non-empty), so it is never `null` post-construction. The
`$this->chunks ?? ['data_root' => …]` fallback at `:163` is dead defensive code.
**Fix:** type as non-nullable `array`, initialise in the constructor, and drop the `??`
fallback — or add a one-line comment if the nullable is deliberate future-proofing.

### SUG-3 — `RsaPss::configure()` PublicKey branch is dead
**`src/Crypto/RsaPss.php:105-118`**

`configure()` is generic over `PrivateKey|PublicKey`, but the only caller is `sign()`
with a `PrivateKey`. `verify()` (`:73-79`) inlines its own
`withHash/withMGFHash/withPadding/withSaltLength` config and never routes through
`configure()`, so the PublicKey half of the union is unreachable.
**Fix:** narrow `configure()` to `PrivateKey`, **or** route `verify()`'s per-salt
configuration through `configure()` for DRY (one place defines the PSS parameters).

### SUG-4 — `getData()` duplicates `send()`'s transport-wrap logic
**`src/ArweaveClient.php:84-106` vs `:108-126`**

`getData()` reimplements the `try { sendRequest } catch (ClientExceptionInterface)`
credential-scrubbing wrap because it needs the `404 → null` special case. The duplicated
catch is a small DRY smell that could drift (the scrubbing comment is copy-pasted).
**Fix:** optional — a private `dispatch(RequestInterface): ResponseInterface` doing the
wrapped `sendRequest`, with `send()` and `getData()` layering status handling on top.

### SUG-5 — No `CONTRIBUTING.md` / `CHANGELOG.md` for the OSS extraction
**(repo root)**

The README "Testing & the parity gate" section already documents the canonical test
command, golden regeneration (`tools/generate-golden.cjs`), and ArLocal startup — good
DX. But a standalone OSS package conventionally ships a short `CONTRIBUTING.md` (test
command, how to regenerate goldens, ArLocal how-to, "don't hand-edit `golden.json`") and
a `CHANGELOG.md`. Most content can link to the existing README section.
**Fix:** add a brief `CONTRIBUTING.md` pointing at the existing instructions; seed
`CHANGELOG.md` with the initial release.

### SUG-6 — A few internal branches/wrappers lack direct unit coverage
**`src/Transaction.php:97-99`, `src/Crypto/Merkle.php:57-61`, `src/ArweaveClient.php:138-141`**

Minor uncovered branches (suite is otherwise excellent):
- `signatureMessage()` "Owner must be set" guard (`Transaction:97`) — no test asserts the throw.
- `Merkle::dataRoot()` convenience wrapper — only `generateTransactionChunks()` is tested directly.
- `discoverFactory()`'s "No PSR-17 factory found" `ArweaveException` (`ArweaveClient:138`) — only the success branch is covered (both Guzzle and Nyholm are dev-present, so the throw can't be hit without dependency manipulation; acceptable to leave, but worth a note).

**Fix:** cheap one-liners for the first two; the third is acceptable as-is (document why).

---

## Coverage assessment (crypto primitives — the headline risk)

| Primitive | Known-answer / golden coverage | Edge cases |
|-----------|-------------------------------|------------|
| `DeepHash` | ✅ 4 arweave-js golden vectors (blob, empty blob, 2-elem list, nested) + list≠blob distinction | empty blob ✅ |
| `Merkle` `data_root` | ✅ **8** golden vectors spanning boundaries: 0, 5, max−1, max, max+1 (rebalance), 2×max, 3-chunk, 5-chunk-remainder | empty ✅, single↔multi boundary ✅, exact-multiple zero-chunk discard ✅, rebalance ✅ |
| `Merkle` chunk proofs | ✅ 2 golden vectors, `data_path` proof bytes byte-for-byte + monotonic offsets + payload-slice coverage | ✅ |
| `RsaPss` | ✅ interop proven: arweave-js's own signature verifies against our message (`TransactionParityTest` GATE 3); local sign→verify round-trip; tamper-reject; salt randomization | known-answer signature impossible (random PSS salt) — verify-interop is the correct substitute ✅ |
| `Base64Url` | ✅ round-trip + url-safe alphabet + **strict** rejection of `+`/`/`/padding/whitespace | empty string ✅ |
| `Transaction`/`SignedTransaction` | ✅ 4 tx vectors (four_tags, no_tags, **unicode_tag**, **empty_data**) full-JSON parity; 3 gateway-body vectors (multi zeroed / single inline); tag validation (missing/non-string/Stringable) | unicode JSON-escaping ✅, multi-chunk data-zeroing ✅, empty data ✅ |

**Verdict:** crypto coverage is golden-vector / known-answer throughout, not smoke-only.
The one real test gap is **transport** (`ArweaveClient` error paths, IMP-1), not crypto.
ArLocal integration is meaningful (real mint→sign→POST→mine→GET byte-identical assert)
but auto-skips offline, so it must not be counted as offline coverage — hence IMP-1.

## Notes (not findings)
- `composer.lock` is git-ignored — conventional for a **library**; intentional, not flagged.
- `golden.json` is committed (34.8 KB) and regenerable; fixtures are public-only (no key material) — verified in `generate-golden.cjs:9-11`.
- Future-scope ANS-104/bundle references in README (`:164-167`) and `SignerInterface` docblock are deliberate roadmap prose, **not** dead code.
