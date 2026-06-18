<?php

declare(strict_types=1);

namespace AgentImprint\Arweave;

/**
 * A transaction signer. The native-L1 implementation is RSA-PSS over an Arweave
 * RSA JWK; future ANS-104 work may add ed25519/secp256k1 signers behind this same
 * interface without disturbing the core.
 */
interface SignerInterface
{
    /** Sign the (already deep-hashed) message. RSA-PSS: sha256, salt 32, mgf1-sha256. */
    public function sign(string $message): string;

    /** The raw "owner" bytes that identify the signing key (RSA modulus for native L1). */
    public function owner(): string;
}
