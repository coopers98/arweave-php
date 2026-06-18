<?php

declare(strict_types=1);

use AgentImprint\Arweave\Crypto\Merkle;
use AgentImprint\Arweave\Util\Base64Url;

test('data_root matches arweave-js golden vectors across chunk boundaries', function () {
    foreach (golden()['dataRoot'] as $vector) {
        $data = patternBytes($vector['size']);
        $result = Merkle::generateTransactionChunks($data);

        expect(Base64Url::encode($result['data_root']))->toBe($vector['data_root'], $vector['label'])
            ->and(count($result['chunks']))->toBe($vector['chunk_count'], $vector['label'].' chunk count')
            ->and(count($result['proofs']))->toBe($vector['proof_count'], $vector['label'].' proof count');
    }
});

test('multi-chunk data produces one proof per chunk with monotonic offsets', function () {
    $result = Merkle::generateTransactionChunks(patternBytes(600 * 1024));

    expect(count($result['chunks']))->toBeGreaterThan(1)
        ->and(count($result['proofs']))->toBe(count($result['chunks']));

    $lastOffset = -1;
    foreach ($result['proofs'] as $i => $proof) {
        expect($proof['offset'])->toBe($result['chunks'][$i]['maxByteRange'] - 1)
            ->and($proof['offset'])->toBeGreaterThan($lastOffset);
        $lastOffset = $proof['offset'];
    }
});

test('chunks below 256 KiB stay single-chunk', function () {
    $result = Merkle::generateTransactionChunks(patternBytes(100 * 1024));
    expect(count($result['chunks']))->toBe(1);
});
