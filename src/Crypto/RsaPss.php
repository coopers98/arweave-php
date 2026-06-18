<?php

declare(strict_types=1);

namespace AgentImprint\Arweave\Crypto;

use AgentImprint\Arweave\ArweaveException;
use AgentImprint\Arweave\Util\Base64Url;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\Crypt\RSA\PublicKey;
use Throwable;

/**
 * RSA-PSS over an Arweave RSA JWK, matching arweave-js's node driver exactly:
 * PSS padding, SHA-256 message + MGF1 hash, salt length 32.
 *
 * ext-openssl's high-level API does not expose PSS, so phpseclib does the EMSA-PSS
 * encoding. The JWK is supplied by value (decoded array) and only ever lives on the
 * stack of this object — it is never read from env/disk and never logged.
 */
final class RsaPss
{
    private const SALT_LENGTH = 32;

    private PrivateKey $key;

    /** @param array<string, mixed> $jwk decoded Arweave RSA JWK ({kty,n,e,d,p,q,dp,dq,qi}) */
    public function __construct(array $jwk)
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || ! isset($jwk['n'], $jwk['d'])) {
            throw new ArweaveException('Wallet JWK is not a usable RSA private key.');
        }

        try {
            $key = RSA::loadFormat('JWK', (string) json_encode($jwk));
        } catch (Throwable $e) {
            throw new ArweaveException('Failed to load RSA JWK into phpseclib: '.$e->getMessage(), 0, $e);
        }

        if (! $key instanceof PrivateKey) {
            throw new ArweaveException('Wallet JWK did not yield an RSA private key.');
        }

        $this->key = $key;
    }

    /** Sign a message with RSA-PSS (sha256, mgf1-sha256, salt 32). */
    public function sign(string $message): string
    {
        return $this->configure($this->key)->sign($message);
    }

    /**
     * Verify a PSS signature against a raw modulus (the public exponent is Arweave's
     * fixed 65537). arweave-js signs with the maximum salt length but verifies with
     * several candidates, so — to remain compatible with any arweave-produced
     * signature — this tries salt length 32 (our own), 0, then the key's maximum.
     */
    public static function verify(string $modulus, string $message, string $signature): bool
    {
        try {
            $public = RSA::loadFormat('JWK', (string) json_encode([
                'kty' => 'RSA',
                'n' => Base64Url::encode($modulus),
                'e' => Base64Url::encode("\x01\x00\x01"),
            ]));

            if (! $public instanceof PublicKey) {
                return false;
            }

            foreach ([self::SALT_LENGTH, 0, self::maxSaltLength($modulus)] as $saltLength) {
                $verified = $public
                    ->withHash('sha256')
                    ->withMGFHash('sha256')
                    ->withPadding(RSA::SIGNATURE_PSS)
                    ->withSaltLength($saltLength)
                    ->verify($message, $signature);

                if ($verified) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /** Largest EMSA-PSS salt for this modulus with SHA-256: emLen - hLen - 2. */
    private static function maxSaltLength(string $modulus): int
    {
        $bits = (strlen($modulus) - 1) * 8;
        for ($top = ord($modulus[0]); $top > 0; $top >>= 1) {
            $bits++;
        }

        $emLen = intdiv(($bits - 1) + 7, 8); // ceil((modBits - 1) / 8)

        return max(0, $emLen - 32 - 2);
    }

    /**
     * @template T of PrivateKey|PublicKey
     *
     * @param  T  $key
     * @return T
     */
    private static function configure(PrivateKey|PublicKey $key): PrivateKey|PublicKey
    {
        return $key
            ->withHash('sha256')
            ->withMGFHash('sha256')
            ->withPadding(RSA::SIGNATURE_PSS)
            ->withSaltLength(self::SALT_LENGTH);
    }
}
