<?php

declare(strict_types=1);

use AgentImprint\Arweave\ArweaveClient;
use AgentImprint\Arweave\ArweaveException;
use AgentImprint\Arweave\Transaction;
use AgentImprint\Arweave\Wallet;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use phpseclib3\Crypt\RSA;

/**
 * Live round-trip against ArLocal (v1.1.66): mint → build+sign → POST /tx → mine →
 * GET → assert byte-identical. Single-chunk runs fully end to end. For multi-chunk,
 * ArLocal v1.1.66 rejects the canonical empty-data /tx body (so does arweave-js's own
 * uploader against it), so that test instead pins the zeroed body + accepted POST /chunk
 * uploads; full multi-chunk byte-parity is gated offline (GatewayBodyParityTest +
 * ChunkProofParityTest). Skipped automatically unless an ArLocal node is reachable, so it
 * never runs (or needs a wallet/network) in offline CI.
 *
 *   cd tools && node node_modules/arlocal/bin/index.js 1984 &
 *   ./vendor/bin/pest --testsuite Integration
 */
function arlocalUrl(): string
{
    return rtrim((string) (getenv('ARWEAVE_ARLOCAL_URL') ?: 'http://localhost:1984'), '/');
}

function arlocalReachable(): bool
{
    static $reachable = null;
    if ($reachable !== null) {
        return $reachable;
    }

    try {
        $info = (new GuzzleClient(['timeout' => 3]))->get(arlocalUrl().'/info');

        return $reachable = $info->getStatusCode() === 200;
    } catch (Throwable) {
        return $reachable = false;
    }
}

/** A throwaway 2048-bit Arweave RSA JWK (fast; size does not affect wire correctness). */
function devJwk(): array
{
    static $jwk = null;
    if ($jwk === null) {
        $exported = json_decode(RSA::createKey(2048)->toString('JWK'), true, 512, JSON_THROW_ON_ERROR);
        $jwk = $exported['keys'][0] ?? $exported;
    }

    return $jwk;
}

function arlocalGuzzle(): GuzzleClient
{
    return new GuzzleClient(['timeout' => 15, 'http_errors' => false]);
}

function arlocalClient(): ArweaveClient
{
    $factory = new HttpFactory;

    return new ArweaveClient(arlocalGuzzle(), arlocalUrl(), $factory, $factory);
}

function mintAndMine(Wallet $wallet): void
{
    $http = arlocalGuzzle();
    $http->get(arlocalUrl().'/mint/'.$wallet->address().'/100000000000000');
    $http->get(arlocalUrl().'/mine');
}

beforeEach(function () {
    if (! arlocalReachable()) {
        $this->markTestSkipped('ArLocal not reachable at '.arlocalUrl().' — start it with `node tools/node_modules/arlocal/bin/index.js 1984`.');
    }
});

test('single-chunk bundle round-trips byte-for-byte through ArLocal', function () {
    $wallet = new Wallet(devJwk());
    mintAndMine($wallet);
    $client = arlocalClient();

    $bundle = json_encode(['ciphertext' => base64_encode(random_bytes(64)), 'iv' => '00', 'tag' => 'ff']);

    $tx = Transaction::create($bundle, [
        ['name' => 'App', 'value' => 'AgentImprint'],
        ['name' => 'Vault', 'value' => 'vault-itest-1'],
        ['name' => 'Content-Type', 'value' => 'application/json'],
        ['name' => 'Encrypted', 'value' => 'true'],
    ]);

    $signed = $tx->sign($wallet, $client->price(strlen($bundle)), $client->anchor());
    $id = $client->submit($signed->toGatewayJson());

    expect($id)->toBe($signed->id())->and(strlen($id))->toBe(43);

    arlocalGuzzle()->get(arlocalUrl().'/mine');

    expect($client->getData($id))->toBe($bundle);
});

test('multi-chunk tx zeroes its /tx body data and ArLocal accepts every POST /chunk', function () {
    $wallet = new Wallet(devJwk());
    mintAndMine($wallet);
    $client = arlocalClient();
    $http = arlocalGuzzle();

    // 600 KiB → 3 chunks; deterministic so a mismatch is debuggable.
    $data = patternBytes(600 * 1024);

    $tx = Transaction::create($data, [['name' => 'App', 'value' => 'AgentImprint']]);
    $signed = $tx->sign($wallet, $client->price(strlen($data)), $client->anchor());

    expect($signed->isMultiChunk())->toBeTrue();

    // The fix: a multi-chunk POST /tx body carries data:"" (bytes go via POST /chunk),
    // byte-identical to arweave-js's TransactionUploader. Byte-for-byte parity of this
    // body and the chunk proofs is the OFFLINE gate (GatewayBodyParityTest +
    // ChunkProofParityTest); here we confirm ArLocal accepts the chunk wire bodies.
    $body = $signed->toGatewayJson();
    expect($body['data'])->toBe('');

    foreach ($signed->chunkProofs() as $i => $proof) {
        $resp = $http->post(arlocalUrl().'/chunk', ['json' => $proof]);
        expect($resp->getStatusCode())->toBe(200, "ArLocal rejected chunk {$i}");
    }

    // NOTE: ArLocal v1.1.66 returns 400 to a multi-chunk POST /tx (data:""), and so does
    // arweave-js's own uploader against the same node — i.e. ArLocal does not implement the
    // mainnet "empty-data tx + POST /chunk" reassembly path, so a full multi-chunk GET
    // round-trip is not assertable here. (Real arweave.net gateways accept it; single-chunk
    // round-trips fully above.) We pin that the body the gateway would reject-or-accept is
    // byte-correct, and that ArLocal accepts our chunk uploads.
    $rejects = $http->post(arlocalUrl().'/tx', ['json' => $body]);
    expect($rejects->getStatusCode())->toBe(400);
});

test('a tx from an unfunded wallet is rejected with a typed exception', function () {
    // Fresh wallet, never minted — ArLocal rejects the tx (insufficient balance).
    $exported = json_decode(RSA::createKey(2048)->toString('JWK'), true, 512, JSON_THROW_ON_ERROR);
    $wallet = new Wallet($exported['keys'][0] ?? $exported);
    $client = arlocalClient();

    $data = 'unfunded-payload';
    $signed = Transaction::create($data, [])->sign($wallet, $client->price(strlen($data)), $client->anchor());

    $client->submit($signed->toGatewayJson());
})->throws(ArweaveException::class);
