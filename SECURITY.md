# Security Policy

`coopers98/arweave-php` signs native Arweave L1 transactions with an RSA wallet key.
Signing is an **irreversible funds path**, so security reports are taken seriously.

## Reporting a vulnerability

Please **do not** open a public GitHub issue for a security vulnerability.

Report privately via GitHub's [private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)
on this repository ("Security" → "Report a vulnerability"). Include a description, affected
versions, and a reproduction if possible. We aim to acknowledge within a few business days.

## Scope

In scope — anything that could:

- cause an **incorrect or malleable signature**, wrong `data_root`, or a transaction that
  does not match `arweave-js` byte-for-byte;
- **leak wallet key material** (the JWK / private exponent) into logs, exception messages,
  stack traces, or any other output;
- leak gateway credentials embedded in a gateway URL into thrown messages or logs;
- enable request smuggling / injection via caller-supplied values (e.g. transaction ids).

Out of scope:

- Vulnerabilities in dependencies (report those upstream — e.g. `phpseclib`).
- The **host application's** secret loading. By design this library never reads secrets
  from env/disk; the wallet JWK is passed in by value and custody stays with the host.
- Denial of service from passing intentionally pathological inputs in a trusted context.

## Hardening guarantees

- The core never reads env/files/secrets and never logs.
- JWK load failures throw a static-message exception with **no chained cause**, so key
  material in a phpseclib stack frame cannot propagate into a trace logger.
- Signatures are **self-verified** against the wallet's own public key before being returned.
- Gateway transport failures are wrapped in a generic message; any URL (which may carry
  credentials) stays only on the chained `previous` exception, never in the public message.
