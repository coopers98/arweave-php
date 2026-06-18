# 3×3 Round 1 — Spec/API & Packaging Review

**Package:** `coopers98/arweave-php` · branch `main` · pure-PHP native-L1 Arweave tx signer
**Scope:** evaluate as a standalone, public-Packagist OSS library. Read-only; no code modified.
**Verdict:** Code/API surface is clean and framework-free. The gaps are **packaging/release hygiene**, not design. No CRITICAL blockers; several IMPORTANT items should land before a public tag.

---

## Summary of what's good (verified)

- `composer validate` → **valid**. `name` = `coopers98/arweave-php`, `type: library`, `license: MIT`.
- PSR-4 autoload maps `AgentImprint\Arweave\` → `src/` correctly; namespace ≠ Packagist vendor is **documented** in the README.
- **No app/framework coupling in `src/`.** `grep -niE 'illuminate|laravel|config\(|facade|env\(|getenv|error_log|var_dump|dd\('` over `src/` → **zero hits**.
- Runtime `require` is clean: `phpseclib/phpseclib` + PSR HTTP interfaces only. Guzzle/Nyholm are `require-dev` + `suggest`, referenced in `src/ArweaveClient.php` **only behind `class_exists()` guards** in `discoverFactory()` (`use` aliases don't force loading) — graceful degradation with a clear error message. No dev dep leaks into runtime.
- `composer.lock`, `vendor/`, `tools/node_modules/`, `tools/logs` all gitignored; **node_modules is NOT tracked** (the 1MB tool output was working-tree only).
- Public API in README matches the actual signatures 1:1 (Wallet, SignerInterface, Transaction, SignedTransaction, ArweaveClient, ArweaveException all verified). Honest custody claims ("loads no secrets itself, but key material lives in object state while signing") are explicitly stated.
- LICENSE file present, MIT, consistent with `composer.json`. `ext-hash` correctly declared (only `hash()` is called directly in `src/`).

---

## CRITICAL
None.

---

## IMPORTANT

### I-1 — No CI workflow (`.github/workflows/` absent)
`find .github -type f` → empty. The README sells **byte-for-byte arweave-js parity** as "the correctness gate," yet nothing runs that gate on push/PR. For a public library whose entire value proposition is wire-correctness, an unguarded parity suite is the single biggest gap.
**Fix:** add `.github/workflows/ci.yml` running `composer install` + `./vendor/bin/pest --testsuite Unit` on a PHP 8.2/8.3/8.4 matrix (Unit suite is offline/no-network, so it's CI-safe; Integration needs ArLocal and can be a separate optional job). Add a status badge to the README.

### I-2 — No `.gitattributes` `export-ignore` → tests/tools/fixtures ship in every install
`.gitattributes` is **absent**. `composer require coopers98/arweave-php` therefore pulls `tests/` (incl. the 35K `golden.json`), `tools/`, `phpunit.xml`, and CI files into every consumer's `vendor/` dist (~116K of non-runtime files tracked under `tests/` + `tools/`). Standard OSS packaging hygiene is to strip them from the dist tarball.
**Fix:** add `.gitattributes`:
```
/tests          export-ignore
/tools          export-ignore
/docs           export-ignore
/.github        export-ignore
/phpunit.xml    export-ignore
/.gitattributes export-ignore
```

### I-3 — No version tag / no CHANGELOG → not Packagist-installable as a stable release
`git tag -l` → **0 tags**; only the initial commit exists. Packagist resolves stable versions from semver tags; with none, `composer require coopers98/arweave-php` (no `:dev-main`) can't resolve, contradicting the README install line. No `CHANGELOG.md` either.
**Fix:** add `CHANGELOG.md` (Keep a Changelog format) and cut `v0.1.0` once CI is green. State the stability contract — the README already flags `Crypto\*`/`Util\*` as "internal (unit-tested, not stable)", which is good; reinforce it in the CHANGELOG.

---

## SUGGESTION

### S-1 — README "pre-extraction" note leaks monorepo context
`README.md` install section carries a `NOTE (pre-extraction)` paragraph referencing "the AgentImprint monorepo" and a "Composer **path repository**." Honest, but it's internal context that reads oddly to an external adopter landing on the public repo.
**Fix:** on public release, replace the note with a plain Packagist install line (+ a one-line "namespace is `AgentImprint\Arweave\`, Packagist name is `coopers98/arweave-php`" clarifier, which is genuinely useful).

### S-2 — `ext-openssl` declared as hard `require` but never called directly in `src/`
`composer.json` requires `"ext-openssl": "*"`, but `grep -noE 'openssl_[a-z_]+' src/` → **zero**. RSA-PSS goes through phpseclib, which has a pure-PHP fallback and only *prefers* ext-openssl. Declaring it as a hard requirement can block install on minimal PHP builds even though the code would run.
**Fix:** keep it for performance if intended (defensible — RSA in pure PHP is slow), but consider moving to `suggest` / documenting it as a performance recommendation rather than a hard gate. At minimum, the choice is worth an inline rationale.

### S-3 — Param-order foot-gun: `signatureMessage($lastTx, $reward)` vs `sign($wallet, $reward, $lastTx)`
`Transaction::signatureMessage(string $lastTx, string $reward)` (`src/Transaction.php:95`) takes `lastTx, reward`, but `Transaction::sign(Wallet, string $reward, string $lastTx)` (`:129`) takes the reverse. Both are documented, and `signatureMessage`/`setOwner` are explicitly "exposed for golden-vector parity," so this is low-risk — but two adjacent public methods with inverted same-typed `string` params is a classic mix-up.
**Fix:** add an `@param`-level note (or align the order) so a caller can't silently swap two strings.

### S-4 — Missing standard OSS community files
No `CONTRIBUTING.md`, `SECURITY.md`, or `CODE_OF_CONDUCT.md`. For a **crypto/signing** library, a `SECURITY.md` with a vulnerability-disclosure contact matters more than usual.
**Fix:** add at least `SECURITY.md` (disclosure address + supported versions) before public announcement; `CONTRIBUTING.md` documenting the parity-regeneration workflow is a nice-to-have.

### S-5 — Integration suite isn't reachable from the documented default command
README's canonical command is `--testsuite Unit`; Integration needs a manually-started ArLocal on `:1984`. Fine, but a one-line note that Integration is **excluded from CI / opt-in** (and why) would set adopter expectations.

---

## Notes / non-issues (checked, no action)
- `tools/package.json` is `"private": true` and clearly labeled dev-only; correctly out of the PHP autoload. OK to ship or `export-ignore` (S-2 list covers it).
- `psr/http-message: ^1.0 || ^2.0` — good forward/backward latitude.
- LICENSE copyright holder "AgentImprint" is consistent across LICENSE + composer.json; matches the namespace vendor. No mismatch.
- `ext-sodium` is **not** required (the task brief's mention was inaccurate) — correct, since no `sodium_*` calls exist. ed25519 is listed only as *future* scope.
