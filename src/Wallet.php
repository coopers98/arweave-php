<?php

declare(strict_types=1);

namespace AgentImprint\Arweave;

use AgentImprint\Arweave\Crypto\RsaPss;
use AgentImprint\Arweave\Util\Base64Url;

/**
 * An Arweave RSA wallet, constructed from a decoded JWK array handed in by the
 * caller. It never reads env/files and never logs — secret loading stays in the
 * host application. The owner field on a transaction is the raw modulus; the
 * address is base64url(sha256(modulus)).
 */
final class Wallet implements SignerInterface
{
    private string $owner;

    private RsaPss $rsa;

    /**
     * The Arweave protocol fixes the RSA public exponent at 65537, whose base64url
     * (unpadded) encoding is "AQAB". A JWK declaring any other exponent is not an
     * Arweave wallet and must be rejected before we ever sign with it.
     */
    private const ARWEAVE_PUBLIC_EXPONENT = 'AQAB';

    /** Every CRT parameter an Arweave RSA private-key JWK must carry, as base64url strings. */
    private const REQUIRED_JWK_FIELDS = ['n', 'e', 'd', 'p', 'q', 'dp', 'dq', 'qi'];

    /** @param array<string, mixed> $jwk decoded Arweave RSA JWK ({kty:"RSA", n,e,d,p,q,dp,dq,qi}) */
    public function __construct(#[\SensitiveParameter] array $jwk)
    {
        if (($jwk['kty'] ?? null) !== 'RSA') {
            throw new ArweaveException('Wallet JWK must declare "kty":"RSA".');
        }

        foreach (self::REQUIRED_JWK_FIELDS as $field) {
            if (! isset($jwk[$field]) || ! is_string($jwk[$field]) || $jwk[$field] === '') {
                throw new ArweaveException("Wallet JWK is missing the RSA parameter \"{$field}\".");
            }
        }

        if ($jwk['e'] !== self::ARWEAVE_PUBLIC_EXPONENT) {
            throw new ArweaveException('Wallet JWK has a non-Arweave RSA public exponent (must be 65537 / "AQAB").');
        }

        $this->owner = Base64Url::decode($jwk['n']);

        // Arweave production wallets are 4096-bit; enforce a 2048-bit floor to reject
        // trivially weak moduli. (Test fixtures use 2048-bit keys for speed, so the
        // floor is set there rather than at the production 4096.)
        if (strlen($this->owner) * 8 < 2048) {
            throw new ArweaveException('Wallet RSA modulus is too small (minimum 2048 bits; Arweave wallets are 4096-bit).');
        }

        $this->rsa = new RsaPss($jwk);
    }

    /** Raw modulus bytes — the transaction "owner" field. */
    public function owner(): string
    {
        return $this->owner;
    }

    /** Wallet address: base64url(sha256(raw modulus)). */
    public function address(): string
    {
        return Base64Url::encode(hash('sha256', $this->owner, true));
    }

    public function signer(): SignerInterface
    {
        return $this;
    }

    public function sign(string $message): string
    {
        return $this->rsa->sign($message);
    }
}
