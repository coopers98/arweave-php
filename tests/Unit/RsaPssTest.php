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

test('rejects a JWK with a non-Arweave public exponent', function () {
    $jwk = testJwk();
    $jwk['e'] = 'AwAB'; // 196609, not Arweave's fixed 65537

    new RsaPss($jwk);
})->throws(ArweaveException::class);

test('a failed JWK load never leaks key material into the exception or its chain', function () {
    // Passes the shape gate (kty=RSA, n+d set, e=AQAB) but is not a loadable RSA key,
    // so phpseclib throws while the full private JWK is on its call stack. The thrown
    // ArweaveException (and any chained previous) must contain none of the JWK values.
    $jwk = [
        'kty' => 'RSA',
        'e' => 'AQAB',
        'n' => 'UNLOADABLE-MODULUS-VALUE-AAAA',
        'd' => 'SUPER-SECRET-PRIVATE-EXPONENT-DDDD',
        'p' => 'SECRET-PRIME-P-PPPP',
        'q' => 'SECRET-PRIME-Q-QQQQ',
    ];

    try {
        new RsaPss($jwk);
        $this->fail('expected RsaPss construction to throw');
    } catch (ArweaveException $e) {
        $haystack = $e->getMessage()."\n".$e->getTraceAsString();
        for ($prev = $e->getPrevious(); $prev !== null; $prev = $prev->getPrevious()) {
            $haystack .= "\n".$prev->getMessage()."\n".$prev->getTraceAsString();
        }

        expect($e->getPrevious())->toBeNull();
        foreach (['d', 'p', 'q', 'n'] as $field) {
            expect($haystack)->not->toContain($jwk[$field]);
        }
    }
});

test('a freshly produced signature self-verifies against its own modulus', function () {
    // sign() verifies its own output before returning; assert the produced signature
    // validates against the wallet's public modulus (the self-verify guard's happy path).
    $rsa = new RsaPss(testJwk());
    $message = hash('sha384', 'self-verify guard', true);
    $signature = $rsa->sign($message);
    $modulus = Base64Url::decode(testJwk()['n']);

    expect(RsaPss::verify($modulus, $message, $signature))->toBeTrue();
});
