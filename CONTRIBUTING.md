# Contributing

Thanks for helping improve `coopers98/arweave-php`. This is a small, security-sensitive
library (it signs Arweave transactions — an **irreversible funds path**), so the bar for
changes touching crypto is deliberately high.

## Setup

```bash
composer install
```

## Running the tests

```bash
./vendor/bin/pest --testsuite Unit          # offline unit + byte-parity gate (the canonical gate)
./vendor/bin/pest                           # everything, incl. the ArLocal integration round-trip
```

> Note: the package uses [Pest](https://pestphp.com/), which replaces the `phpunit`
> binary with its own. Run the suite via `./vendor/bin/pest` — there is no separate
> plain-`phpunit` entry point.

The **`Unit` suite is the correctness gate**: it asserts byte-for-byte parity with
`arweave-js` (signature message, `data_root`, transaction id, serialized `POST /tx`
JSON). It runs fully offline and must stay green on PHP 8.2 / 8.3 / 8.4.

## The golden fixtures (do not hand-edit)

`tests/fixtures/golden.json` is generated from the reference `arweave-js` implementation.
**Never edit it by hand** — regenerate it from the pinned trust anchor:

```bash
cd tools && npm install && node generate-golden.cjs > ../tests/fixtures/golden.json
```

`tools/package.json` pins the exact `arweave` and `arlocal` versions used to produce the
vectors; bump them deliberately (and review the resulting diff) rather than floating them.

## ArLocal integration round-trip

The `Integration` suite mints, signs, posts, mines, and reads back against a local
[ArLocal](https://github.com/textury/arlocal) gateway:

```bash
cd tools && node node_modules/arlocal/bin/index.js 1984 &
./vendor/bin/pest --testsuite Integration
```

It auto-skips when no gateway is reachable, so it never blocks the offline gate.

## Crypto changes — read this first

Any change that could alter **signed bytes** — DeepHash, Merkle, the v2 signature-message
field order, the RSA-PSS parameters (salt 32 / MGF1-SHA-256 / SHA-256), or id derivation —
must keep the parity suite byte-for-byte green. If your change moves those bytes, it is a
correctness regression, not a refactor. Call it out explicitly in the PR.

## Conventions

- Keep the core framework-free: no Laravel/Illuminate, no `config()`, no global state.
- The library never reads secrets from env/disk and never logs them. Don't add I/O of
  secrets or anything that could write key material to logs.
- Match the existing code style (strict types, typed properties, short focused methods).
