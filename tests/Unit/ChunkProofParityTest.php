<?php

declare(strict_types=1);

use AgentImprint\Arweave\Transaction;
use AgentImprint\Arweave\Util\Base64Url;

/**
 * OFFLINE multi-chunk proof gate. The production multi-chunk upload path (POST /chunk,
 * default bundles up to ~5 MB ≈ 20 chunks) is only end-to-end exercised by ArLocalTest,
 * which auto-skips when no node is reachable — i.e. never in offline CI. A subtle
 * data_path/proof bug would therefore ship un-caught and only surface as gateway-rejected
 * chunk uploads (data never persists → breaks the perpetuity guarantee).
 *
 * Here we pin the Merkle proof bytes: for every chunk of each multi-chunk vector, assert
 * that SignedTransaction::chunkProofs() reproduces arweave-js's own `tx.getChunk(i)` wire
 * fields (data_root, data_size, data_path, offset) byte-for-byte. The golden values were
 * captured from arweave-js by tools/generate-golden.cjs. No network, no ArLocal, no key.
 */
test('chunkProofs() reproduces arweave-js POST /chunk proof bytes byte-for-byte', function () {
    $vectors = golden()['chunkUploads'];
    expect($vectors)->not->toBeEmpty('golden.json is missing the chunkUploads vectors');

    foreach ($vectors as $v) {
        $label = $v['label'];
        $data = patternBytes($v['size']);

        // chunkProofs() depends only on data / data_root / chunks — no private key or
        // signature is needed, so assemble via the documented attachSignature() path.
        $signed = Transaction::create($data)->attachSignature('', '', '0', '');

        expect($signed->isMultiChunk())->toBeTrue("{$label}: expected a multi-chunk transaction");

        $proofs = $signed->chunkProofs();
        expect($proofs)->toHaveCount($v['chunk_count'], "{$label}: chunk count");
        expect(count($proofs))->toBeGreaterThan(1, "{$label}: must span more than one chunk");

        foreach ($v['uploads'] as $i => $expected) {
            expect($proofs[$i]['data_root'])->toBe($expected['data_root'], "{$label} chunk {$i}: data_root");
            expect($proofs[$i]['data_size'])->toBe($expected['data_size'], "{$label} chunk {$i}: data_size");
            expect($proofs[$i]['offset'])->toBe($expected['offset'], "{$label} chunk {$i}: offset");
            // The hard part: the Merkle data_path (proof) bytes, byte-for-byte.
            expect($proofs[$i]['data_path'])->toBe($expected['data_path'], "{$label} chunk {$i}: data_path (proof bytes)");
        }

        // Sanity-check the `chunk` payload (omitted from the lean fixture): it must be the
        // exact deterministic slice of the data for each chunk's byte range.
        $offset = 0;
        foreach ($proofs as $i => $proof) {
            $decoded = Base64Url::decode($proof['chunk']);
            expect(substr($data, $offset, strlen($decoded)))->toBe($decoded, "{$label} chunk {$i}: chunk payload slice");
            $offset += strlen($decoded);
        }
        expect($offset)->toBe($v['size'], "{$label}: chunk payloads cover the full data");
    }
});
