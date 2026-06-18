<?php

declare(strict_types=1);

namespace AgentImprint\Arweave\Crypto;

/**
 * Arweave deep-hash (recursive SHA-384), matching arweave-js `deepHash.js`.
 *
 *  - leaf (byte string): sha384( sha384("blob".len) . sha384(data) )
 *  - list (array):       acc = sha384("list".count);
 *                        for each element: acc = sha384(acc . deepHash(element))
 *
 * All SHA-384 values are the raw 48-byte digests; the length/count suffix is the
 * decimal byte count rendered as an ASCII string. This is the function arweave-js
 * hashes to produce a transaction's signature message, so its output is part of
 * the byte-parity contract.
 */
final class DeepHash
{
    /** @param string|array<int, string|array<int, mixed>> $data */
    public static function hash(string|array $data): string
    {
        if (is_array($data)) {
            $acc = self::sha384('list'.count($data));
            foreach ($data as $element) {
                $acc = self::sha384($acc.self::hash($element));
            }

            return $acc;
        }

        $taggedHash = self::sha384('blob'.strlen($data));

        return self::sha384($taggedHash.self::sha384($data));
    }

    private static function sha384(string $value): string
    {
        return hash('sha384', $value, true);
    }
}
