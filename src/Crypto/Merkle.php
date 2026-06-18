<?php

declare(strict_types=1);

namespace AgentImprint\Arweave\Crypto;

/**
 * Arweave chunk Merkle tree → `data_root`, matching arweave-js `merkle.js`
 * (which mirrors the gateway's `ar_merkle.erl`). SHA-256 throughout, 32-byte
 * nodes and 32-byte big-endian offset "notes".
 *
 *  - data is split into ≤256 KiB chunks; if the final chunk would fall below
 *    32 KiB, the second-to-last chunk is rebalanced to ceil(rest/2).
 *  - leaf id   = sha256( sha256(sha256(chunk)) . sha256(note(maxByteRange)) )
 *  - branch id = sha256( sha256(leftId) . sha256(rightId) . sha256(note(leftMax)) )
 *  - odd node at a layer is promoted unchanged.
 *
 * `data_root` is computed over every chunk (including a trailing zero-length
 * chunk when the data is an exact multiple of 256 KiB); that zero-length chunk
 * and its proof are then discarded from the upload set — exactly as arweave-js does.
 */
final class Merkle
{
    public const MAX_CHUNK_SIZE = 262144;   // 256 * 1024

    public const MIN_CHUNK_SIZE = 32768;    // 32 * 1024

    private const NOTE_SIZE = 32;

    /**
     * @return array{data_root: string, chunks: list<array{minByteRange:int, maxByteRange:int}>, proofs: list<array{offset:int, proof:string}>}
     */
    public static function generateTransactionChunks(string $data): array
    {
        $chunks = self::chunkData($data);
        $leaves = self::generateLeaves($chunks);
        $root = self::buildLayers($leaves);
        $proofs = self::generateProofs($root);

        // Discard the last chunk & proof if it is zero-length (it carries no data).
        $last = $chunks[count($chunks) - 1];
        if ($last['maxByteRange'] - $last['minByteRange'] === 0) {
            array_pop($chunks);
            array_pop($proofs);
        }

        return [
            'data_root' => $root['id'],
            'chunks' => array_map(
                fn (array $c) => ['minByteRange' => $c['minByteRange'], 'maxByteRange' => $c['maxByteRange']],
                $chunks
            ),
            'proofs' => $proofs,
        ];
    }

    /** Convenience: just the raw 32-byte data_root. */
    public static function dataRoot(string $data): string
    {
        return self::generateTransactionChunks($data)['data_root'];
    }

    /**
     * @return list<array{dataHash:string, minByteRange:int, maxByteRange:int}>
     */
    private static function chunkData(string $data): array
    {
        $chunks = [];
        $rest = $data;
        $cursor = 0;

        while (strlen($rest) >= self::MAX_CHUNK_SIZE) {
            $chunkSize = self::MAX_CHUNK_SIZE;

            // If the remaining bytes would leave a final chunk below MIN_CHUNK_SIZE,
            // rebalance this (second-to-last) chunk to roughly half the remainder.
            $nextChunkSize = strlen($rest) - self::MAX_CHUNK_SIZE;
            if ($nextChunkSize > 0 && $nextChunkSize < self::MIN_CHUNK_SIZE) {
                $chunkSize = (int) ceil(strlen($rest) / 2);
            }

            $chunk = substr($rest, 0, $chunkSize);
            $cursor += strlen($chunk);
            $chunks[] = [
                'dataHash' => hash('sha256', $chunk, true),
                'minByteRange' => $cursor - strlen($chunk),
                'maxByteRange' => $cursor,
            ];
            $rest = substr($rest, $chunkSize);
        }

        $chunks[] = [
            'dataHash' => hash('sha256', $rest, true),
            'minByteRange' => $cursor,
            'maxByteRange' => $cursor + strlen($rest),
        ];

        return $chunks;
    }

    /**
     * @param  list<array{dataHash:string, minByteRange:int, maxByteRange:int}>  $chunks
     * @return list<array{type:string, id:string, dataHash:string, minByteRange:int, maxByteRange:int}>
     */
    private static function generateLeaves(array $chunks): array
    {
        return array_map(fn (array $c) => [
            'type' => 'leaf',
            'id' => self::hash(self::hash($c['dataHash']).self::hash(self::intToBuffer($c['maxByteRange']))),
            'dataHash' => $c['dataHash'],
            'minByteRange' => $c['minByteRange'],
            'maxByteRange' => $c['maxByteRange'],
        ], $chunks);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    private static function buildLayers(array $nodes): array
    {
        if (count($nodes) < 2) {
            return $nodes[0];
        }

        $nextLayer = [];
        for ($i = 0; $i < count($nodes); $i += 2) {
            $nextLayer[] = self::hashBranch($nodes[$i], $nodes[$i + 1] ?? null);
        }

        return self::buildLayers($nextLayer);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>|null  $right
     * @return array<string, mixed>
     */
    private static function hashBranch(array $left, ?array $right): array
    {
        if ($right === null) {
            return $left;
        }

        return [
            'type' => 'branch',
            'id' => self::hash(
                self::hash($left['id']).
                self::hash($right['id']).
                self::hash(self::intToBuffer($left['maxByteRange']))
            ),
            'byteRange' => $left['maxByteRange'],
            'maxByteRange' => $right['maxByteRange'],
            'leftChild' => $left,
            'rightChild' => $right,
        ];
    }

    /**
     * @param  array<string, mixed>  $root
     * @return list<array{offset:int, proof:string}>
     */
    private static function generateProofs(array $root): array
    {
        $proofs = self::resolveBranchProofs($root, '');

        // resolveBranchProofs returns either a single leaf proof or a nested
        // array of them; flatten to a left-to-right ordered list.
        return self::flatten(is_array($proofs) && isset($proofs['offset']) ? [$proofs] : $proofs);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{offset:int, proof:string}|list<mixed>
     */
    private static function resolveBranchProofs(array $node, string $proof): array
    {
        if ($node['type'] === 'leaf') {
            return [
                'offset' => $node['maxByteRange'] - 1,
                'proof' => $proof.$node['dataHash'].self::intToBuffer($node['maxByteRange']),
            ];
        }

        $partialProof = $proof.$node['leftChild']['id'].$node['rightChild']['id'].self::intToBuffer($node['byteRange']);

        return [
            self::resolveBranchProofs($node['leftChild'], $partialProof),
            self::resolveBranchProofs($node['rightChild'], $partialProof),
        ];
    }

    /**
     * @param  list<mixed>  $input
     * @return list<array{offset:int, proof:string}>
     */
    private static function flatten(array $input): array
    {
        $flat = [];
        foreach ($input as $item) {
            if (is_array($item) && ! isset($item['offset'])) {
                $flat = array_merge($flat, self::flatten($item));
            } else {
                $flat[] = $item;
            }
        }

        return $flat;
    }

    private static function hash(string $data): string
    {
        return hash('sha256', $data, true);
    }

    /**
     * 32-byte big-endian representation of a byte offset (arweave-js `intToBuffer`).
     *
     * Offsets are native PHP ints, so this assumes a 64-bit platform (PHP_INT_SIZE === 8).
     * That bounds offsets at ~9.2e18, far below the 32-byte note's capacity and far above
     * any practical Arweave data size — a 32-bit build would silently truncate large offsets.
     */
    private static function intToBuffer(int $note): string
    {
        $buffer = array_fill(0, self::NOTE_SIZE, 0);
        for ($i = self::NOTE_SIZE - 1; $i >= 0; $i--) {
            $byte = $note % 256;
            $buffer[$i] = $byte;
            $note = intdiv($note - $byte, 256);
        }

        return pack('C*', ...$buffer);
    }
}
