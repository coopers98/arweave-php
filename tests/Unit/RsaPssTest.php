<?php

declare(strict_types=1);

use AgentImprint\Arweave\ArweaveException;
use AgentImprint\Arweave\Crypto\RsaPss;
use AgentImprint\Arweave\Util\Base64Url;
use phpseclib3\Crypt\RSA;

/** A throwaway 2048-bit RSA JWK (fast for tests; production wallets are 4096-bit). */
function testJwk(): array
{
    static $jwk = null;
    if ($jwk === null) {
        $key = RSA::createKey(2048);
        $exported = json_decode($key->toString('JWK'), true, 512, JSON_THROW_ON_ERROR);
        // phpseclib exports a JWK Set ({"keys":[...]}); Arweave wallets are a flat JWK.
        $jwk = $exported['keys'][0] ?? $exported;
    }

    return $jwk;
}

test('signs and verifies a round-trip under the same modulus', function () {
    $rsa = new RsaPss(testJwk());
    $message = hash('sha384', 'arweave signature message', true);
    $signature = $rsa->sign($message);
    $modulus = Base64Url::decode(testJwk()['n']);

    expect(RsaPss::verify($modulus, $message, $signature))->toBeTrue();
});

test('verification fails for a tampered message', function () {
    $rsa = new RsaPss(testJwk());
    $signature = $rsa->sign('original message');
    $modulus = Base64Url::decode(testJwk()['n']);

    expect(RsaPss::verify($modulus, 'tampered message', $signature))->toBeFalse();
});

test('PSS salt randomization yields distinct signatures that both verify', function () {
    $rsa = new RsaPss(testJwk());
    $message = 'salted';
    $a = $rsa->sign($message);
    $b = $rsa->sign($message);
    $modulus = Base64Url::decode(testJwk()['n']);

    expect($a)->not->toBe($b)
        ->and(RsaPss::verify($modulus, $message, $a))->toBeTrue()
        ->and(RsaPss::verify($modulus, $message, $b))->toBeTrue();
});

test('rejects a non-RSA JWK', function () {
    new RsaPss(['kty' => 'EC', 'crv' => 'P-256']);
})->throws(ArweaveException::class);
