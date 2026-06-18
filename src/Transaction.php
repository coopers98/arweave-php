<?php

declare(strict_types=1);

namespace AgentImprint\Arweave;

use AgentImprint\Arweave\Crypto\DeepHash;
use AgentImprint\Arweave\Crypto\Merkle;
use AgentImprint\Arweave\Util\Base64Url;

/**
 * A native Arweave v2 (format 2) transaction. Holds the data, tags, and the
 * computed `data_root`; produces the canonical signature message (the arweave-js
 * deep-hash byte-parity target) and, once signed, a {@see SignedTransaction}.
 *
 * `owner`, `reward`, and `last_tx` are injected at sign time — the library never
 * fetches price/anchor itself (that is the caller's {@see ArweaveClient}).
 */
final class Transaction
{
    private const FORMAT = 2;

    private const TARGET = '';

    private const QUANTITY = '0';

    private string $owner = '';

    private string $dataRoot;

    /** @var array{data_root: string, chunks: list<array{minByteRange:int, maxByteRange:int}>, proofs: list<array{offset:int, proof:string}>}|null */
    private ?array $chunks;

    /**
     * @param  string  $data  raw bytes to publish
     * @param  list<array{name:string, value:string}>  $tags  raw (un-encoded) tag name/value pairs
     */
    private function __construct(
        private readonly string $data,
        private readonly array $tags,
    ) {
        if ($data === '') {
            $this->dataRoot = '';
            $this->chunks = ['data_root' => '', 'chunks' => [], 'proofs' => []];

            return;
        }

        $this->chunks = Merkle::generateTransactionChunks($data);
        $this->dataRoot = $this->chunks['data_root'];
    }

    /**
     * @param  array<int, array{name:string, value:string}>  $tags  tags: [['name'=>.., 'value'=>..]]
     */
    public static function create(string $data, array $tags = []): self
    {
        $normalized = [];
        foreach ($tags as $tag) {
            if (! isset($tag['name'], $tag['value'])) {
                throw new ArweaveException('Each tag must have a "name" and a "value".');
            }
            // Tags are deep-hashed and base64url-encoded as raw bytes; a non-string
            // (array/object) would cast to "Array"/throw and silently corrupt the
            // signature message. Require strings (or Stringable) explicitly.
            foreach (['name', 'value'] as $field) {
                if (! is_string($tag[$field]) && ! $tag[$field] instanceof \Stringable) {
                    throw new ArweaveException("Tag \"{$field}\" must be a string.");
                }
            }
            $normalized[] = ['name' => (string) $tag['name'], 'value' => (string) $tag['value']];
        }

        return new self($data, $normalized);
    }

    /**
     * Assign the owner (raw RSA modulus) before computing the signature message.
     * `sign()` calls this for you; it is part of the public API only so the byte-parity
     * suite can set an arweave-js golden vector's public owner and assert
     * {@see signatureMessage()} without holding that vector's private key.
     */
    public function setOwner(string $rawOwner): self
    {
        $this->owner = $rawOwner;

        return $this;
    }

    /**
     * The canonical v2 signature message: deep-hash over the arweave-js field order
     * `[ "2", owner, target, quantity, reward, last_tx, tags[[name,value]], data_size, data_root ]`.
     * `$lastTx` is the base64url anchor; `$reward` is the winston string.
     */
    public function signatureMessage(string $lastTx, string $reward): string
    {
        if ($this->owner === '') {
            throw new ArweaveException('Owner must be set before computing the signature message.');
        }

        $tagList = array_map(
            fn (array $tag) => [$tag['name'], $tag['value']],
            $this->tags
        );

        return DeepHash::hash([
            (string) self::FORMAT,
            $this->owner,
            self::TARGET,
            self::QUANTITY,
            $reward,
            $lastTx === '' ? '' : Base64Url::decode($lastTx),
            $tagList,
            (string) strlen($this->data),
            $this->dataRoot,
        ]);
    }

    /** Raw 32-byte `data_root` (empty string for empty data), for callers/tests. */
    public function dataRoot(): string
    {
        return $this->dataRoot;
    }

    /**
     * Sign the transaction. `$reward` is the winston price (from the gateway) and
     * `$lastTx` is the base64url anchor (from the gateway).
     */
    public function sign(Wallet $wallet, string $reward, string $lastTx): SignedTransaction
    {
        $this->setOwner($wallet->owner());

        $message = $this->signatureMessage($lastTx, $reward);
        $signature = $wallet->signer()->sign($message);

        return $this->assemble($this->owner, $signature, $reward, $lastTx);
    }

    /**
     * Attach an externally-produced RSA-PSS signature (e.g. from an HSM/remote signer
     * holding the key). `$rawOwner` is the raw modulus, `$signature` the raw PSS signature
     * over {@see signatureMessage()}. Used by the byte-parity suite to assemble against
     * arweave-js's own signature without holding a private key.
     */
    public function attachSignature(string $rawOwner, string $signature, string $reward, string $lastTx): SignedTransaction
    {
        return $this->assemble($rawOwner, $signature, $reward, $lastTx);
    }

    private function assemble(string $owner, string $signature, string $reward, string $lastTx): SignedTransaction
    {
        return new SignedTransaction(
            owner: $owner,
            target: self::TARGET,
            quantity: self::QUANTITY,
            reward: $reward,
            lastTx: $lastTx,
            tags: $this->tags,
            data: $this->data,
            dataSize: (string) strlen($this->data),
            dataRoot: $this->dataRoot,
            signature: $signature,
            chunks: $this->chunks ?? ['data_root' => $this->dataRoot, 'chunks' => [], 'proofs' => []],
        );
    }
}
