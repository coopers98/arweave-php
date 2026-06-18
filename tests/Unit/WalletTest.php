<?php

declare(strict_types=1);

use AgentImprint\Arweave\ArweaveException;
use AgentImprint\Arweave\Util\Base64Url;
use AgentImprint\Arweave\Wallet;
use phpseclib3\Crypt\RSA;

/**
 * A valid Arweave RSA JWK (kty:RSA, e:"AQAB" / 65537, full CRT params). 2048-bit keeps
 * the suite fast; the validated fields are identical at 4096-bit. Cached across cases.
 */
function validArweaveJwk(): array
{
    static $jwk = null;
    if ($jwk === null) {
        $exported = json_decode(RSA::createKey(2048)->toString('JWK'), true);
        $jwk = $exported['keys'][0] ?? $exported;
    }

    return $jwk;
}

test('constructs from a valid Arweave RSA JWK and exposes owner/address', function () {
    $jwk = validArweaveJwk();
    $wallet = new Wallet($jwk);

    expect($wallet->owner())->toBe(Base64Url::decode($jwk['n']))
        ->and($wallet->address())->toBe(Base64Url::encode(hash('sha256', $wallet->owner(), true)));
});

test('rejects a JWK whose public exponent is not Arweave\'s 65537 (AQAB)', function () {
    $jwk = validArweaveJwk();
    $jwk['e'] = Base64Url::encode("\x03"); // exponent 3 — not AQAB

    new Wallet($jwk);
})->throws(ArweaveException::class, 'non-Arweave RSA public exponent');

test('rejects a non-RSA key type', function () {
    $jwk = validArweaveJwk();
    $jwk['kty'] = 'EC';

    new Wallet($jwk);
})->throws(ArweaveException::class, '"kty":"RSA"');

test('rejects a JWK missing a required RSA parameter', function (string $field) {
    $jwk = validArweaveJwk();
    unset($jwk[$field]);

    new Wallet($jwk);
})->throws(ArweaveException::class)->with(['n', 'e', 'd', 'p', 'q', 'dp', 'dq', 'qi']);

test('rejects a JWK whose RSA parameter is not a string', function () {
    $jwk = validArweaveJwk();
    $jwk['d'] = ['not', 'a', 'string'];

    new Wallet($jwk);
})->throws(ArweaveException::class);
