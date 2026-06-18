<?php

declare(strict_types=1);

use AgentImprint\Arweave\Crypto\RsaPss;
use AgentImprint\Arweave\Transaction;
use AgentImprint\Arweave\Util\Base64Url;
use AgentImprint\Arweave\Wallet;
use phpseclib3\Crypt\RSA;

/**
 * THE CORRECTNESS GATE. For each arweave-js golden transaction, assert that this
 * package reproduces — byte-for-byte — the signature message (deep-hash), the
 * data_root, the transaction id, and the full serialized POST /tx JSON, and that
 * arweave-js's own signature validates against the message we computed (which proves
 * our signature message is byte-identical to theirs). Native-L1 crypto is the accepted
 * headline risk; nothing built on top of this is trustworthy until this suite is green.
 */
test('reproduces arweave-js transactions byte-for-byte', function () {
    foreach (golden()['transactions'] as $v) {
        $label = $v['label'];
        $owner = Base64Url::decode($v['owner_b64url']);
        $signature = Base64Url::decode($v['signature_b64url']);

        $tags = array_map(fn ($t) => ['name' => $t['name'], 'value' => $t['value']], $v['tags']);
        $tx = Transaction::create($v['data_utf8'], $tags)->setOwner($owner);

        // GATE 1 — signature message (deep-hash over the canonical v2 field order).
        $message = $tx->signatureMessage($v['reward'], $v['last_tx']);
        expect(bin2hex($message))->toBe($v['signatureMessage_hex'], "{$label}: signature message");

        // GATE 2 — data_root.
        $dataRoot = $tx->dataRoot() === '' ? '' : Base64Url::encode($tx->dataRoot());
        expect($dataRoot)->toBe($v['data_root'], "{$label}: data_root");

        // GATE 3 — arweave-js's signature validates against OUR message ⇒ identical message bytes.
        expect(RsaPss::verify($owner, $message, $signature))->toBeTrue("{$label}: arweave-js signature verifies");

        // GATE 4 — id derivation + full serialized JSON, byte-for-byte.
        $signed = $tx->attachSignature($owner, $signature, $v['reward'], $v['last_tx']);
        expect($signed->id())->toBe($v['id'], "{$label}: id");

        $json = json_encode($signed->toGatewayJson(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        expect($json)->toBe($v['toJSON'], "{$label}: serialized POST /tx JSON");
    }
});

test('our message matches the golden one for the same field inputs', function () {
    // We cannot sign with the golden vector's public owner (no private key), so confirm
    // our deep-hash signing-message construction is byte-identical to arweave-js's.
    $v = null;
    foreach (golden()['transactions'] as $tx) {
        if ($tx['label'] === 'four_tags') {
            $v = $tx;
            break;
        }
    }
    $owner = Base64Url::decode($v['owner_b64url']);

    $tags = array_map(fn ($t) => ['name' => $t['name'], 'value' => $t['value']], $v['tags']);
    $message = Transaction::create($v['data_utf8'], $tags)
        ->setOwner($owner)
        ->signatureMessage($v['reward'], $v['last_tx']);

    expect(bin2hex($message))->toBe($v['signatureMessage_hex']);
});

test('a transaction we sign ourselves produces a signature that validates', function () {
    // End-to-end with a locally-generated wallet: build → sign → the resulting
    // SignedTransaction's signature verifies against the message we computed, and its
    // id is base64url(sha256(signature)). 2048-bit keeps it fast; the path is identical.
    $exported = json_decode(RSA::createKey(2048)->toString('JWK'), true);
    $wallet = new Wallet($exported['keys'][0] ?? $exported);

    $tags = [['name' => 'App', 'value' => 'AgentImprint'], ['name' => 'Encrypted', 'value' => 'true']];
    $reward = '1000000';
    $lastTx = Base64Url::encode(str_repeat("\x01", 48));

    $tx = Transaction::create('locally-signed-bundle', $tags)->setOwner($wallet->owner());
    $message = $tx->signatureMessage($reward, $lastTx);
    $signed = $tx->sign($wallet, $reward, $lastTx);

    $sigBytes = Base64Url::decode($signed->toGatewayJson()['signature']);
    expect(RsaPss::verify($wallet->owner(), $message, $sigBytes))->toBeTrue()
        ->and($signed->id())->toBe(Base64Url::encode(hash('sha256', $sigBytes, true)));
});
