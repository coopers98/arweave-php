<?php

declare(strict_types=1);

use AgentImprint\Arweave\Transaction;
use AgentImprint\Arweave\Util\Base64Url;

/**
 * OFFLINE POST /tx body gate (r2-consolidated CRITICAL #1). arweave-js's uploader posts
 * a multi-chunk transaction with `data:""` (the bytes travel over POST /chunk); inlining
 * the full data gets the tx gateway-rejected, so it never persists — silently breaking
 * the perpetuity guarantee. That bug shipped through a green offline suite because nothing
 * pinned the serialized body bytes.
 *
 * Here we assert SignedTransaction::toGatewayJson() reproduces arweave-js's posted body
 * byte-for-byte: `data` is "" for every multi-chunk vector (and inline for the single-chunk
 * one), while data_size / data_root still describe the full data. No network, no ArLocal,
 * no private key — assembled from a golden public owner + signature via attachSignature().
 */
test('toGatewayJson() reproduces arweave-js POST /tx body bytes (multi-chunk data zeroed)', function () {
    $vectors = golden()['gatewayBodies'] ?? [];
    expect($vectors)->not->toBeEmpty('golden.json is missing the gatewayBodies vectors');

    $sawMultiChunk = false;
    $sawSingleChunk = false;

    foreach ($vectors as $v) {
        $label = $v['label'];
        $data = patternBytes($v['size']);
        $owner = Base64Url::decode($v['owner_b64url']);
        $signature = Base64Url::decode($v['signature_b64url']);

        $tags = array_map(fn ($t) => ['name' => $t['name'], 'value' => $t['value']], $v['tags']);
        $signed = Transaction::create($data, $tags)
            ->setOwner($owner)
            ->attachSignature($owner, $signature, $v['reward'], $v['last_tx']);

        expect($signed->isMultiChunk())->toBe($v['is_multi_chunk'], "{$label}: isMultiChunk()");
        expect($signed->id())->toBe($v['id'], "{$label}: id");

        $body = $signed->toGatewayJson();

        // The headline assertion: a multi-chunk body MUST zero its inline data.
        if ($v['is_multi_chunk']) {
            expect($body['data'])->toBe('', "{$label}: multi-chunk /tx body must carry data:\"\"");
            $sawMultiChunk = true;
        } else {
            expect($body['data'])->not->toBe('', "{$label}: single-chunk /tx body must inline data");
            $sawSingleChunk = true;
        }

        // data_size / data_root still describe the full data even when data is zeroed.
        expect($body['data_size'])->toBe($v['data_size'], "{$label}: data_size");
        expect($body['data_root'])->toBe($v['data_root'], "{$label}: data_root");

        // Full byte-for-byte parity with arweave-js's serialized posted body.
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        expect($json)->toBe($v['post_body'], "{$label}: serialized POST /tx body");
    }

    // Guard the gate itself: it must actually exercise both paths.
    expect($sawMultiChunk)->toBeTrue('expected at least one multi-chunk gatewayBodies vector');
    expect($sawSingleChunk)->toBeTrue('expected at least one single-chunk gatewayBodies vector');
});
