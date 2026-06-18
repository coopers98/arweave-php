<?php

declare(strict_types=1);

use AgentImprint\Arweave\ArweaveException;
use AgentImprint\Arweave\Transaction;

/**
 * Tag inputs are deep-hashed into the signature message and base64url-encoded as raw
 * bytes. A non-string name/value (array/object) would previously cast to "Array" (with a
 * warning) and silently corrupt the signed message, so Transaction::create() now rejects
 * anything that is not a string or Stringable (r2-consolidated #9).
 */
test('rejects a tag with a missing name or value', function (array $tag) {
    Transaction::create('payload', [$tag]);
})->throws(ArweaveException::class)->with([
    'no value' => [['name' => 'App']],
    'no name' => [['value' => 'AgentImprint']],
]);

test('rejects a tag whose name or value is not a string', function (array $tag) {
    Transaction::create('payload', [$tag]);
})->throws(ArweaveException::class)->with([
    'array value' => [['name' => 'App', 'value' => ['nested']]],
    'array name' => [['name' => ['nested'], 'value' => 'x']],
    'int value' => [['name' => 'App', 'value' => 123]],
    'bool value' => [['name' => 'App', 'value' => true]],
    'object value' => [['name' => 'App', 'value' => new stdClass]],
]);

test('accepts string and Stringable tag values', function () {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'stringable-value';
        }
    };

    $tx = Transaction::create('payload', [
        ['name' => 'App', 'value' => 'AgentImprint'],
        ['name' => 'Vault', 'value' => $stringable],
    ]);

    // No throw, and a usable transaction is produced.
    expect($tx)->toBeInstanceOf(Transaction::class)
        ->and($tx->dataRoot())->not->toBe('');
});
