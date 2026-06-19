# 3x3 Round 2 - SPEC/API & PACKAGING Review

Reviewer: GPT-5.5 spec/API & packaging pass
Branch/HEAD: main @ 15d966cc7c7964005765999e04d3e35e3a10d056
Mode: read-only review; this file is the only workspace change.

## Verdict

PASS

No CRITICAL or IMPORTANT findings. The package is coherent for a first public OSS/Packagist release; only small documentation polish remains.

## Findings

### CRITICAL

None.

### IMPORTANT

None.

### SUGGESTION

1. README public API block should spell out the full `attachSignature()` signature.
   - `README.md:93` currently lists only a comment for `attachSignature()`.
   - `src/Transaction.php:146` exposes `public function attachSignature(string $rawOwner, string $signature, string $reward, string $lastTx): SignedTransaction`.
   - Since the section is titled "Public API" and the task asks for 1:1 signature coverage, adding the full signature would make the docs mechanically exact. This is not a release blocker because the method is at least named in the README and the primary API paths are exact.

2. README custody prose still uses a product-specific env var example.
   - `README.md:118` names `SOVEREIGN_WALLET_SECRET`.
   - The library does not read this env var, and the surrounding text correctly says the host app owns secret loading. Still, for public OSS polish, a generic example such as `ARWEAVE_WALLET_JWK` would avoid carrying product-specific vocabulary into the shipped README.

## Verification Notes

- Composer metadata is valid: `composer validate --strict` passed. `composer.json:2-5` declares `coopers98/arweave-php`, `library`, MIT; `composer.json:7-19` keeps runtime package deps to phpseclib plus PSR interfaces, with Guzzle/Nyholm in `require-dev`; `composer.json:25-28` maps `AgentImprint\Arweave\` to `src/`.
- Guzzle/Nyholm are runtime-optional in code: `src/ArweaveClient.php:7-8` imports the dev-only factory classes, and `src/ArweaveClient.php:137-150` only instantiates them behind `class_exists()` guards with a clear fallback exception.
- README signatures match actual primary API signatures for `Wallet`, `SignerInterface`, `Transaction::create`, `setOwner`, `signatureMessage`, `sign`, `dataRoot`, `SignedTransaction`, and `ArweaveClient`. The S9 swap is consistently applied: `README.md:90-91`, `src/Transaction.php:96`, `src/Transaction.php:130`, `src/Transaction.php:134`, `tests/Unit/TransactionParityTest.php:29`, `tests/Unit/TransactionParityTest.php:63`, and `tests/Unit/TransactionParityTest.php:80-81` all use `reward, lastTx`.
- `.gitattributes` export hygiene is correct for Packagist/GitHub source archives. `git archive HEAD | tar -tf - | sort` exports only `CHANGELOG.md`, `CONTRIBUTING.md`, `LICENSE`, `README.md`, `SECURITY.md`, `composer.json`, and `src/`. `tests/`, `tools/`, `docs/`, `.github/`, `phpunit.xml`, `.gitignore`, and `.gitattributes` are excluded by `.gitattributes:3-9`.
- CI is PR-only and runs the offline parity gate on PHP 8.2, 8.3, and 8.4: `.github/workflows/ci.yml:5-7`, `.github/workflows/ci.yml:17-18`, `.github/workflows/ci.yml:30-37`. The referenced command, `vendor/bin/pest --testsuite Unit`, maps to `phpunit.xml:7-9`. I also ran `./vendor/bin/pest --testsuite Unit` locally: 57 tests passed, 222 assertions.
- `CHANGELOG.md` is present and coherent for the deferred first release: `CHANGELOG.md:7-14` separates Unreleased from pending `0.1.0` and explicitly says no tag/publish exists yet. `git tag --list` returned no tags.
- Shipped-file reference scan found no monorepo/path-repository/Turbo references. The remaining hits are intentional or non-blocking: README "no bundler" positioning (`README.md:5`), framework-free wording (`README.md:11`), future ANS-104/Bundle roadmap prose (`README.md:169-170`), and the same future-signer docblock in `src/SignerInterface.php:8-10`.

Verdict: PASS
