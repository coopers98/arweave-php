<?php

declare(strict_types=1);

/*
 * Test helpers for the parity suite, loaded via BOTH composer `autoload-dev.files`
 * and the phpunit bootstrap (tests/bootstrap.php) so they are available no matter
 * which test binary runs the suite (the package's own `vendor/bin/pest`, the root
 * binary, or plain phpunit). Each definition is guarded so the double registration
 * is a no-op. See r2-consolidated finding #6.
 */

if (! function_exists('golden')) {
    /**
     * Load the committed golden vectors (generated once from arweave-js via
     * tools/generate-golden.cjs). The parity suite asserts byte-for-byte equality
     * against these — the package's correctness gate.
     *
     * @return array<string, mixed>
     */
    function golden(): array
    {
        static $golden = null;

        return $golden ??= json_decode((string) file_get_contents(__DIR__.'/fixtures/golden.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}

if (! function_exists('patternBytes')) {
    /** Deterministic byte pattern shared with the Node generator: byte[i] = i % 256. */
    function patternBytes(int $size): string
    {
        $out = '';
        for ($i = 0; $i < $size; $i++) {
            $out .= chr($i % 256);
        }

        return $out;
    }
}
