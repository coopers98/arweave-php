# coopers98/arweave-php

Pure-PHP **native Arweave L1** transaction signer, encoder, and transport. Given an
Arweave RSA JWK and some bytes, it produces and posts a **wire-correct native
(format 2) Arweave transaction** — directly to a gateway, with no Node, no bundler,
and no third party in the publish path.

It exists because there is **no maintained PHP Arweave library** (the official
`arweave/arweave-sdk` is abandoned and pre-dates Arweave 2.0 deep-hash / `data_root`).

- **Framework-free core.** No Laravel, no facades, no `config()`, no Illuminate.
  The only runtime deps are `phpseclib/phpseclib` (RSA-PSS — stock `openssl_sign`
  has no PSS) and PSR HTTP interfaces.
- **Loads no secrets.** The wallet JWK enters **by value** via `Wallet`; the library
  never reads env/files and never logs secrets. (Key material does live in object
  state while a `Wallet`/`RsaPss` instance signs — it just never originates here.)
- **Byte-for-byte parity with `arweave-js`** is the correctness gate (see below).

```
PHP 8.2+ · ext-openssl · ext-hash · phpseclib ^3.0 · a PSR-18 client · MIT
```

## Install

> **NOTE:** Not published to Packagist yet — the `composer require` line below applies
> once the first version is tagged and published. The PHP namespace is `AgentImprint\Arweave\`
> (namespace ≠ Packagist name).

```bash
composer require coopers98/arweave-php
```

A PSR-18 HTTP client and PSR-17 factories are needed for `ArweaveClient`. Guzzle
works out of the box:

```bash
composer require guzzlehttp/guzzle
```

## Usage

```php
use AgentImprint\Arweave\{Wallet, Transaction, ArweaveClient};
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

// The host app loads the secret and decodes it to a JWK array — never the library.
$wallet = new Wallet($decodedJwkArray);          // {kty:"RSA", n,e,d,p,q,dp,dq,qi}

$factory = new HttpFactory;
$client  = new ArweaveClient(new Client, 'https://arweave.net', $factory, $factory);

$data = '{"ciphertext":"…"}';
$tx = Transaction::create($data, [
    ['name' => 'App', 'value' => 'AgentImprint'],
    ['name' => 'Content-Type', 'value' => 'application/json'],
]);

$signed = $tx->sign($wallet, $client->price(strlen($data)), $client->anchor());
$id = $client->submit($signed->toGatewayJson());     // POST /tx
if ($signed->isMultiChunk()) {                        // data > 256 KiB
    $client->postChunks($signed->chunkProofs());      // POST /chunk
}

echo "ar://{$id}";
echo $client->getData($id);                           // GET /{id}
```

## Public API

```php
namespace AgentImprint\Arweave;

final class Wallet implements SignerInterface {
    public function __construct(array $jwk);   // decoded Arweave RSA JWK
    public function owner(): string;           // raw modulus (tx "owner")
    public function address(): string;         // base64url(sha256(modulus))
    public function signer(): SignerInterface;
    public function sign(string $message): string;
}

interface SignerInterface {
    public function sign(string $message): string;   // RSA-PSS(sha256, salt32, mgf1-sha256)
    public function owner(): string;
}

final class Transaction {
    public static function create(string $data, array $tags = []): self;          // [['name'=>..,'value'=>..]]
    public function setOwner(string $rawOwner): self;                             // raw modulus; exposed for golden-vector parity (sign() sets it for you)
    public function signatureMessage(string $reward, string $lastTx): string;     // deep-hash; exposed for golden vectors (reward, last_tx order matches sign())
    public function sign(Wallet $w, string $reward, string $lastTx): SignedTransaction;
    public function dataRoot(): string;                                           // raw 32-byte data_root ('' for empty)
    // attachSignature(): assemble against an externally-produced signature (HSM / parity tests)
}

final class SignedTransaction {
    public function id(): string;            // base64url(sha256(signature))
    public function toGatewayJson(): array;  // POST /tx body (byte-parity with arweave-js toJSON)
    public function chunkProofs(): array;    // POST /chunk bodies
    public function isMultiChunk(): bool;
}

final class ArweaveClient {                  // thin PSR-18 transport; throws ArweaveException
    public function __construct(ClientInterface $http, string $gateway, ?RequestFactoryInterface $rf = null, ?StreamFactoryInterface $sf = null);
    public function price(int $bytes): string;       // GET /price/{bytes}
    public function anchor(): string;                // GET /tx_anchor
    public function submit(array $txJson): string;   // POST /tx → id
    public function postChunks(array $proofs): void; // POST /chunk
    public function getData(string $id): ?string;    // GET /{id}
}
// internal (unit-tested, not stable): Crypto\DeepHash, Crypto\Merkle, Crypto\RsaPss, Util\Base64Url
```

### Custody (the hard rule)

The core never reads env/files/secrets, never logs, and has no framework coupling.
The wallet JWK enters by value; **the host app owns secret loading** (e.g.
`SOVEREIGN_WALLET_SECRET`). HTTP is a PSR-18 client injected by the caller.

## Crypto

- **DeepHash** — recursive SHA-384 (the signature-message construction).
- **Merkle** — `data_root` over ≤256 KiB SHA-256 chunks with 32-byte big-endian
  offset notes, including the final-chunk rebalance and zero-length-chunk discard.
- **RsaPss** — RSA-PSS via phpseclib: SHA-256, MGF1-SHA256, salt length 32. The
  gateway verifies with auto salt detection, so salt-32 signatures are accepted;
  `verify()` tries salt lengths 32 / 0 / max to validate any arweave-produced
  signature.

## Testing & the parity gate

```bash
composer install
./vendor/bin/pest --testsuite Unit          # canonical: offline unit + byte-parity gate
```

The shared parity helpers (`golden()`, `patternBytes()`) load via the phpunit bootstrap
(`tests/bootstrap.php`) **and** Composer `autoload-dev.files`, so the suite runs identically
under the package's own `vendor/bin/pest` or any outer Pest binary — there is no
binary-specific bootstrap that can silently skip the gate. (Pest replaces the `phpunit`
binary, so always run the suite via `pest`, not a bare `phpunit` invocation.)

The **parity suite** asserts — byte-for-byte against `arweave-js` — the signature
message, `data_root` (across chunk boundaries incl. multi-chunk), the transaction
id, and the full serialized `POST /tx` JSON, and that an `arweave-js` signature
validates against the message this library computes. Fixtures are committed and
regenerated once with the dev-only Node script (Node is never a runtime dep). The
trust anchor is pinned in `tools/package.json` (`arweave` **1.15.5**, exact) so the
vectors are reproducible:

```bash
cd tools && npm install && node generate-golden.cjs > ../tests/fixtures/golden.json
```

Live round-trip against **ArLocal v1.1.66** (mint → build+sign → `POST /tx` → mine
→ `GET`, single- and multi-chunk):

```bash
cd tools && node node_modules/arlocal/bin/index.js 1984 &
./vendor/bin/pest --testsuite Integration
```

## Scope

Signing / encoding / transport only — no wallet generation, no key storage, no
funding. Transactions are **data-only**: `target` is always `""` and `quantity` is
always `"0"` (no value transfer / no AR payments) — the library publishes bytes to
the permaweb, it does not send AR between addresses. **Future scope** (the interfaces
already allow it): an `Ans104DataItem` plus ed25519/secp256k1 `SignerInterface`
implementations and a `Bundle` assembler, without disturbing the native-L1 core.

## License

MIT.
