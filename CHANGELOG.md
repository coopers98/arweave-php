# Changelog

All notable changes to `coopers98/arweave-php` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

_No unreleased changes yet._

## [0.1.0] — pending initial release

> **Not yet tagged or published.** This is the planned content of the first release;
> the actual `v0.1.0` git tag and Packagist publication are a maintainer decision.

### Added
- Native Arweave **v2 (format 2)** transaction signing and encoding in pure PHP:
  `Wallet`, `Transaction`, `SignedTransaction`, `SignerInterface`, and `ArweaveException`.
- `Crypto\DeepHash` — recursive SHA-384 signature-message construction.
- `Crypto\Merkle` — `data_root` over ≤256 KiB SHA-256 chunks, including the
  final-chunk rebalance and zero-length-chunk discard.
- `Crypto\RsaPss` — RSA-PSS (SHA-256, MGF1-SHA-256, salt length 32) via phpseclib,
  with self-verification after signing.
- `ArweaveClient` — thin PSR-18 gateway transport (`price`, `anchor`, `submit`,
  `postChunks`, `getData`) with PSR-17 factory auto-discovery.
- Byte-for-byte parity suite against `arweave-js` 1.15.5 (signature message,
  `data_root`, transaction id, serialized `POST /tx` JSON) plus an ArLocal
  integration round-trip.

[Unreleased]: https://github.com/coopers98/arweave-php/compare/main...HEAD
