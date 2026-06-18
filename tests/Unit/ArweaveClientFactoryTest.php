<?php

declare(strict_types=1);

use AgentImprint\Arweave\ArweaveClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Covers ArweaveClient's PSR-17 factory auto-discovery (the branch that resolves
 * Guzzle/Nyholm factories when none are injected) so a consumer can construct the
 * client with just a PSR-18 client. nyholm/psr7 is a require-dev so the Nyholm
 * fallback target is exercisable here without a network.
 */

/** Minimal canned PSR-18 client — records nothing, just returns a fixed response. */
function fakeHttpClient(string $body): ClientInterface
{
    return new class($body) implements ClientInterface
    {
        public function __construct(private string $body) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            return (new Psr17Factory)->createResponse(200)->withBody(
                (new Psr17Factory)->createStream($this->body)
            );
        }
    };
}

test('resolves request/stream factories when none are injected', function () {
    // No factory args → discoverFactory() must find one (Guzzle or Nyholm) and the
    // request path must work end-to-end against a canned PSR-18 client.
    $client = new ArweaveClient(fakeHttpClient('123456'), 'https://arweave.net');

    expect($client->price(1024))->toBe('123456');
});

test('works with the Nyholm PSR-17 factory (the discovery fallback target)', function () {
    $nyholm = new Psr17Factory;
    $client = new ArweaveClient(fakeHttpClient('anchor-xyz'), 'https://arweave.net', $nyholm, $nyholm);

    expect($client->anchor())->toBe('anchor-xyz');
});

test('the Nyholm fallback factory satisfies both PSR-17 interfaces', function () {
    // Guarantees discoverFactory()'s Nyholm branch would return a usable factory.
    $nyholm = new Psr17Factory;

    expect($nyholm)->toBeInstanceOf(RequestFactoryInterface::class)
        ->and($nyholm)->toBeInstanceOf(StreamFactoryInterface::class);
});
