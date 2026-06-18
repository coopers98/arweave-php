<?php

declare(strict_types=1);

use AgentImprint\Arweave\ArweaveException;
use AgentImprint\Arweave\Crypto\Merkle;
use AgentImprint\Arweave\Transaction;

/**
 * Small guard/wrapper coverage that the parity suite doesn't otherwise touch:
 * the "owner must be set" precondition on signatureMessage(), and the Merkle::dataRoot()
 * convenience wrapper agreeing with generateTransactionChunks().
 */

test('signatureMessage requires the owner to be set first', function () {
    // No setOwner()/sign() call, so the owner is still empty.
    Transaction::create('some data')->signatureMessage('1000000', '');
})->throws(ArweaveException::class, 'Owner must be set');

test('Merkle::dataRoot matches generateTransactionChunks data_root', function () {
    $data = str_repeat('arweave-merkle-wrapper', 4096); // multi-byte, comfortably one chunk

    expect(Merkle::dataRoot($data))
        ->toBe(Merkle::generateTransactionChunks($data)['data_root']);
});
