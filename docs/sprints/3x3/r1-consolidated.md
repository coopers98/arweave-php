# 3×3 R1 — Consolidated Findings: `coopers98/arweave-php`

**Branch:** `main` @ `a8bdb76` · **Round:** R1 (Opus) · spec+packaging / security+crypto / maintainability
**Headline:** 0 CRITICAL across all three lenses. Crypto core verified byte-for-byte vs arweave-js 1.15.5 (49 passed / 208 assertions). All findings are security hardening, transport-test coverage, and OSS release hygiene — none block the parity gate.

Sources: `r1-spec.md`, `r1-security.md`, `r1-maint.md` (this dir).

---

## MUST FIX before R2 (IMPORTANT)

### F1 — Private-key JWK can leak into logs via chained-exception stack trace  ⚠️ borderline-CRITICAL
`src/Crypto/RsaPss.php:36-38`. The full private JWK is the argument to `RSA::loadFormat()`; passing the caught `$e` as `previous` propagates a trace whose frame args carry the key material. A framework logging full traces (args enabled) would write the wallet private key to logs — violates the package's "never logs secrets" contract.
**Fix:** throw a static-message `ArweaveException` with **no `previous`** and **no `$e->getMessage()`**. Add a test asserting the thrown exception (and its chain) contains no JWK field values.

### F2 — Signer doesn't self-verify before returning
`src/Crypto/RsaPss.php:49-52`. For an irreversible-write/funds-path signer, verify the produced signature against the own modulus and throw on mismatch (defense-in-depth vs faults/regressions).
**Fix:** after signing, `verify()` the signature against the public modulus; throw `ArweaveException` on mismatch. Add a test.

### F3 — `ArweaveClient` HTTP error paths have zero offline unit coverage
`src/ArweaveClient.php:84-126`. Only the happy path is tested offline; the safety branches (404→null, ≥400→typed throw, transport exception → wrapped + **credential-scrubbed** message) are only touched by `ArLocalTest`, which auto-skips in CI.
**Fix:** add Unit cases with a canned PSR-18 client returning 404 / 500 / throwing — assert `getData()` returns null on 404, `ArweaveException` on ≥400, and the thrown message does **not** contain the gateway URL.

### F4 — No CI workflow (raised by both spec + maint)
No `.github/workflows/`. The byte-parity gate runs nowhere automatically.
**Fix:** minimal GH Actions: `composer install` → `vendor/bin/pest --testsuite Unit` on PHP 8.2/8.3/8.4 matrix. **PR-only** per project policy (no schedule, conserve Actions minutes).

### F5 — No `.gitattributes export-ignore`
Every `composer require` ships `tests/`, `tools/`, fixtures, `phpunit.xml` into consumers' `vendor/`.
**Fix:** add `.gitattributes` with `export-ignore` for `tests/`, `tools/`, `phpunit.xml`, `.github/`, `docs/`, `.gitignore`, `.gitattributes`.

### F6 — Stray `logs` file not git-ignored
`.gitignore` only has `/tools/logs`; running integration from root writes a root `logs` CSV → `?? logs`. A careless `git add -A` commits an ArLocal request log.
**Fix:** add `/logs` to `.gitignore`; delete the stray file.

### F7 — No CHANGELOG / version tag path
0 git tags → documented `composer require` can't resolve a stable version on Packagist.
**Fix:** seed `CHANGELOG.md` with the initial release. (Actual `v0.1.0` tag + Packagist publish stays a Cooper decision — note it, don't tag.)

---

## APPLY (cheap SUGGESTIONs — fold in)

- **S1** README "or plain phpunit" claim is unrunnable (pest hijacks the phpunit binary) — drop/qualify the wording. (`README.md:139-142`)
- **S2** `Transaction::$chunks` typed `?array` but always assigned; dead `??` fallback at `:163` — make non-nullable, drop fallback. (`src/Transaction.php:32,163`)
- **S3** `RsaPss::configure()` PublicKey branch is dead (only `sign()` calls it, with PrivateKey) — narrow to `PrivateKey` **or** route `verify()` through it for DRY. (`src/Crypto/RsaPss.php:105-118`)
- **S4** `getData()` duplicates `send()`'s transport-wrap — optional private `dispatch()` helper. (`src/ArweaveClient.php:84-126`)
- **S5** Add brief `CONTRIBUTING.md` (test cmd, golden regen, ArLocal how-to, "don't hand-edit golden.json"). Pairs with F7.
- **S6** Cheap one-liner tests for `signatureMessage()` "Owner must be set" guard + `Merkle::dataRoot()` wrapper. (`discoverFactory()` throw acceptable to leave — document why.)
- **S7** README "pre-extraction" note leaks monorepo context — clean it for the standalone repo.
- **S8** `ext-openssl` hard-required but never called directly in `src/` (phpseclib has pure-PHP fallback) — drop or justify in composer.json.
- **S9** Inverted same-typed params: `signatureMessage($lastTx,$reward)` vs `sign(...,$reward,$lastTx)` — align order to reduce caller foot-guns.
- **S10** Add `SECURITY.md` (matters for a signing lib) — disclosure contact + scope.
- **S11** `RsaPss` public ctor doesn't enforce `e==AQAB` (Wallet does, but ctor is public API) — enforce or document.
- **S12** Pin the exact arweave-js version of the trust anchor in `tools/package.json` / golden generator + README.
- **S13** `getData()` interpolates caller-supplied id into the URL unvalidated — validate base64url id shape.
- **S14** RSA key size (4096-bit) not enforced on load — enforce min modulus bits or document.
- **S15** Document data-only / zero-value (no target/quantity) scope explicitly in README.
- **S16** Note the 64-bit int assumption for Merkle offsets.

---

## Verified GOOD (no action)
- Crypto correct byte-for-byte vs arweave-js 1.15.5: DeepHash SHA-384, v2 field order + decoded `last_tx`, RSA-PSS salt32/MGF1-SHA256, `data_root` Merkle (all boundaries + proof bytes), id=base64url(sha256(sig)). GATE 3 (real arweave-js sig verifies against our message) = true cryptographic message-identity proof.
- Multi-chunk `data:""` zeroing regression fixed (`SignedTransaction.php:72`) and pinned by `GatewayBodyParityTest`.
- Zero framework coupling in `src/`; Guzzle/Nyholm dev-only behind `class_exists()` guards. Custody clean — no secrets/env/files/logs in `src/`. Golden fixtures public-modulus only, no private key material.
- The brief's stated deep-hash order was **inaccurate**; code matches the real arweave-js order — not a bug.

---

## Process
- Single writer only (shared-worktree hazard). Verify HEAD before commit.
- No PR, no Copilot (agentimprint-family policy applies to this repo too).
- After fixes: commit + push `main`, then R2 (gpt-5.5) on the same three lenses, writing to `r2-*.md`.
