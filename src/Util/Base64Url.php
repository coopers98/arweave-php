<?php

declare(strict_types=1);

namespace AgentImprint\Arweave\Util;

use AgentImprint\Arweave\ArweaveException;

/**
 * URL-safe, unpadded base64 codec — the encoding Arweave uses for every binary
 * field on the wire (ids, owner, signatures, tags, data, data_root, anchors).
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        // Reject anything that is not strictly URL-safe, unpadded base64url. Without
        // this, standard-base64 input (`+`, `/`) and padding (`=`) silently decode,
        // so a wire value that is NOT what Arweave produced would round-trip as valid.
        if ($value !== '' && preg_match('#^[A-Za-z0-9_-]+$#', $value) !== 1) {
            throw new ArweaveException('Invalid base64url string.');
        }

        $b64 = strtr($value, '-_', '+/');
        $remainder = strlen($b64) % 4;
        if ($remainder !== 0) {
            $b64 .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            // ArweaveException (not InvalidArgumentException) so a consumer catching the
            // package's single exception type also catches malformed-input errors.
            throw new ArweaveException('Invalid base64url string.');
        }

        return $decoded;
    }
}
