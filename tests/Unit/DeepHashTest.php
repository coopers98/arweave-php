<?php

declare(strict_types=1);

use AgentImprint\Arweave\Crypto\DeepHash;

test('deep-hash matches arweave-js golden vectors byte-for-byte', function () {
    foreach (golden()['deepHash'] as $vector) {
        $input = match ($vector['label']) {
            'blob_hello' => 'hello',
            'empty_blob' => '',
            'list_two' => ['abc', 'de'],
            'nested' => ['x', ['y', 'z']],
            default => throw new RuntimeException("Unknown deepHash vector {$vector['label']}"),
        };

        expect(bin2hex(DeepHash::hash($input)))->toBe($vector['out_hex'], $vector['label']);
    }
});

test('list and blob tags are distinguished', function () {
    // A single-element list must not collide with the bare blob.
    expect(DeepHash::hash(['x']))->not->toBe(DeepHash::hash('x'));
});
