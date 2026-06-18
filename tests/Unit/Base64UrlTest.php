<?php

declare(strict_types=1);

use AgentImprint\Arweave\ArweaveException;
use AgentImprint\Arweave\Util\Base64Url;

test('round-trips arbitrary bytes without padding', function () {
    foreach (['', 'a', 'ab', 'abc', "\x00\xff\xfe", random_bytes(48)] as $bytes) {
        $encoded = Base64Url::encode($bytes);
        expect($encoded)->not->toContain('=')
            ->and($encoded)->not->toContain('+')
            ->and($encoded)->not->toContain('/')
            ->and(Base64Url::decode($encoded))->toBe($bytes);
    }
});

test('decodes unpadded url-safe alphabet', function () {
    // base64url of the two bytes 0xFB 0xFF is "-_8" (uses - and _).
    expect(Base64Url::encode("\xfb\xff"))->toBe('-_8')
        ->and(Base64Url::decode('-_8'))->toBe("\xfb\xff");
});

test('rejects invalid input', function () {
    Base64Url::decode('not valid base64!!');
})->throws(ArweaveException::class);

test('rejects standard-base64 and padded input (not strict base64url)', function (string $value) {
    Base64Url::decode($value);
})->throws(ArweaveException::class)->with([
    'plus' => ['++++'],          // standard-base64 '+' must not be silently accepted
    'slash' => ['////'],         // standard-base64 '/' must not be silently accepted
    'padded' => ['YWJj='],       // explicit '=' padding is not unpadded base64url
    'mixed' => ['ab+/cd'],
    'whitespace' => ['ab cd'],
]);

test('accepts an empty string as zero bytes', function () {
    expect(Base64Url::decode(''))->toBe('');
});
