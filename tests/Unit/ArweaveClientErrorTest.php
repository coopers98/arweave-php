<?php

declare(strict_types=1);

use AgentImprint\Arweave\ArweaveClient;
use AgentImprint\Arweave\ArweaveException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Offline coverage for ArweaveClient's HTTP error branches — the safety paths that
 * ArLocalTest can only touch live (and which auto-skip in CI). A canned PSR-18 client
 * drives each branch: 404 → null, ≥400 → typed throw, transport failure → wrapped.
 * Every assertion also proves the gateway URL (which can embed credentials) never
 * appears in a thrown message.
 */

/** A canned PSR-18 client returning a fixed status + body for every request. */
function statusHttpClient(int $status, string $body = ''): ClientInterface
{
    return new class($status, $body) implements ClientInterface
    {
        public function __construct(private int $status, private string $body) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return (new Psr17Factory)->createResponse($this->status)->withBody(
                (new Psr17Factory)->createStream($this->body)
            );
        }
    };
}

/** A PSR-18 client that always fails transport with a creds-bearing message. */
function throwingHttpClient(): ClientInterface
{
    return new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new class('cURL error: could not connect to https://user:s3cr3t@vault.example/price/1') extends RuntimeException implements ClientExceptionInterface {};
        }
    };
}

/** The gateway whose host/userinfo must never surface in any thrown message. */
const SECRET_GATEWAY = 'https://user:s3cr3t@vault.example';

test('getData returns null on a 404', function () {
    $client = new ArweaveClient(statusHttpClient(404), SECRET_GATEWAY);

    expect($client->getData('abc123_-XYZ'))->toBeNull();
});

test('getData throws a typed exception on a 5xx without leaking the gateway', function () {
    $client = new ArweaveClient(statusHttpClient(500), SECRET_GATEWAY);

    try {
        $client->getData('abc123_-XYZ');
        $this->fail('expected ArweaveException on HTTP 500');
    } catch (ArweaveException $e) {
        expect($e->getMessage())->toContain('500')
            ->and($e->getMessage())->not->toContain('vault.example')
            ->and($e->getMessage())->not->toContain('s3cr3t');
    }
});

test('getData rejects an id with URL-control characters', function () {
    $client = new ArweaveClient(statusHttpClient(200, 'never reached'), SECRET_GATEWAY);

    $client->getData('../tx_anchor');
})->throws(ArweaveException::class);

test('getData wraps a transport failure with no gateway creds in the message', function () {
    $client = new ArweaveClient(throwingHttpClient(), SECRET_GATEWAY);

    try {
        $client->getData('abc123_-XYZ');
        $this->fail('expected ArweaveException on transport failure');
    } catch (ArweaveException $e) {
        expect($e->getMessage())->not->toContain('vault.example')
            ->and($e->getMessage())->not->toContain('s3cr3t')
            ->and($e->getMessage())->not->toContain('user:');
    }
});

test('a request path throws a typed exception on a 4xx without leaking the gateway', function () {
    $client = new ArweaveClient(statusHttpClient(503), SECRET_GATEWAY);

    try {
        $client->price(1024);
        $this->fail('expected ArweaveException on HTTP 503');
    } catch (ArweaveException $e) {
        expect($e->getMessage())->toContain('503')
            ->and($e->getMessage())->not->toContain('vault.example')
            ->and($e->getMessage())->not->toContain('s3cr3t');
    }
});

test('a request path wraps a transport failure with no gateway creds in the message', function () {
    $client = new ArweaveClient(throwingHttpClient(), SECRET_GATEWAY);

    try {
        $client->anchor();
        $this->fail('expected ArweaveException on transport failure');
    } catch (ArweaveException $e) {
        expect($e->getMessage())->not->toContain('vault.example')
            ->and($e->getMessage())->not->toContain('s3cr3t')
            ->and($e->getPrevious())->toBeInstanceOf(ClientExceptionInterface::class);
    }
});
