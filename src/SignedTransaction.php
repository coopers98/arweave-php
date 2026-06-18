<?php

declare(strict_types=1);

namespace AgentImprint\Arweave;

use AgentImprint\Arweave\Util\Base64Url;

/**
 * A signed native Arweave v2 transaction, ready to post. `toGatewayJson()` is the
 * `POST /tx` body, key-ordered and base64url-encoded exactly like arweave-js's
 * `Transaction.toJSON()` (the byte-parity target). `chunkProofs()` yields the
 * `POST /chunk` bodies for a multi-chunk upload.
 */
final class SignedTransaction
{
    private const FORMAT = 2;

    private readonly string $id;

    /**
     * @param  list<array{name:string, value:string}>  $tags  raw tag pairs
     * @param  array{data_root: string, chunks: list<array{minByteRange:int, maxByteRange:int}>, proofs: list<array{offset:int, proof:string}>}  $chunks
     */
    public function __construct(
        private readonly string $owner,
        private readonly string $target,
        private readonly string $quantity,
        private readonly string $reward,
        private readonly string $lastTx,
        private readonly array $tags,
        private readonly string $data,
        private readonly string $dataSize,
        private readonly string $dataRoot,
        private readonly string $signature,
        private readonly array $chunks,
    ) {
        // Arweave transaction id is base64url(sha256(signature)).
        $this->id = Base64Url::encode(hash('sha256', $this->signature, true));
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * The `POST /tx` body — same fields, key order, and base64url encoding as
     * arweave-js `Transaction.toJSON()`, including the data-inlining rule its
     * TransactionUploader applies: only a single-chunk tx carries its `data`
     * inline; a multi-chunk tx posts `data:""` and uploads the bytes via
     * `POST /chunk` ({@see chunkProofs()}). Inlining a multi-chunk body gets the
     * tx gateway-rejected, so the data never persists — breaking the perpetuity
     * guarantee. Mirrors arweave-js `MAX_CHUNKS_IN_BODY = 1`.
     *
     * @return array<string, mixed>
     */
    public function toGatewayJson(): array
    {
        return [
            'format' => self::FORMAT,
            'id' => $this->id,
            'last_tx' => $this->lastTx,
            'owner' => Base64Url::encode($this->owner),
            'tags' => array_map(fn (array $t) => [
                'name' => Base64Url::encode($t['name']),
                'value' => Base64Url::encode($t['value']),
            ], $this->tags),
            'target' => $this->target,
            'quantity' => $this->quantity,
            // Multi-chunk: the bytes travel over POST /chunk, so the /tx body zeroes data.
            'data' => $this->isMultiChunk() ? '' : Base64Url::encode($this->data),
            'data_size' => $this->dataSize,
            'data_root' => $this->dataRoot === '' ? '' : Base64Url::encode($this->dataRoot),
            'reward' => $this->reward,
            'signature' => Base64Url::encode($this->signature),
        ];
    }

    /**
     * `POST /chunk` bodies for each data chunk (only needed for multi-chunk data;
     * a single-chunk tx posts its data inline in `toGatewayJson`, a multi-chunk
     * tx zeroes the inline `data` there and uploads every chunk here).
     *
     * @return list<array{data_root:string, data_size:string, data_path:string, offset:string, chunk:string}>
     */
    public function chunkProofs(): array
    {
        $bodies = [];
        $dataRoot = $this->dataRoot === '' ? '' : Base64Url::encode($this->dataRoot);

        foreach ($this->chunks['chunks'] as $i => $chunk) {
            $proof = $this->chunks['proofs'][$i];
            $bodies[] = [
                'data_root' => $dataRoot,
                'data_size' => $this->dataSize,
                'data_path' => Base64Url::encode($proof['proof']),
                'offset' => (string) $proof['offset'],
                'chunk' => Base64Url::encode(substr($this->data, $chunk['minByteRange'], $chunk['maxByteRange'] - $chunk['minByteRange'])),
            ];
        }

        return $bodies;
    }

    /** True when the data spans more than one chunk and requires `POST /chunk`. */
    public function isMultiChunk(): bool
    {
        return count($this->chunks['chunks']) > 1;
    }
}
