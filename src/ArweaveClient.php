<?php

declare(strict_types=1);

namespace AgentImprint\Arweave;

use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Thin Arweave gateway transport over a caller-injected PSR-18 client (no Guzzle
 * hard-dependency in the core). Returns plain values/DTOs; throws {@see ArweaveException}
 * on transport or HTTP errors. The PSR-17 request/stream factories are injected too,
 * or discovered from a common implementation (Guzzle/Nyholm) when omitted.
 */
final class ArweaveClient
{
    private readonly string $gateway;

    private readonly RequestFactoryInterface $requests;

    private readonly StreamFactoryInterface $streams;

    public function __construct(
        private readonly ClientInterface $http,
        string $gateway,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->gateway = rtrim($gateway, '/');
        $this->requests = $requestFactory ?? self::discoverFactory();
        $this->streams = $streamFactory ?? self::discoverFactory();
    }

    /** Quoted winston reward for storing `$bytes` bytes (`GET /price/{bytes}`). */
    public function price(int $bytes): string
    {
        return trim($this->send($this->requests->createRequest('GET', "{$this->gateway}/price/{$bytes}")));
    }

    /** A recent transaction anchor (`GET /tx_anchor`) — the `last_tx` for a new tx. */
    public function anchor(): string
    {
        return trim($this->send($this->requests->createRequest('GET', "{$this->gateway}/tx_anchor")));
    }

    /**
     * Submit a signed transaction (`POST /tx`). Returns the transaction id.
     *
     * @param  array<string, mixed>  $txJson  the {@see SignedTransaction::toGatewayJson()} body
     */
    public function submit(array $txJson): string
    {
        $request = $this->requests->createRequest('POST', "{$this->gateway}/tx")
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($txJson)));

        $this->send($request);

        return (string) ($txJson['id'] ?? '');
    }

    /**
     * Upload data chunks for a multi-chunk transaction (`POST /chunk`).
     *
     * @param  list<array<string, mixed>>  $proofs  the {@see SignedTransaction::chunkProofs()} bodies
     */
    public function postChunks(array $proofs): void
    {
        foreach ($proofs as $proof) {
            $request = $this->requests->createRequest('POST', "{$this->gateway}/chunk")
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream((string) json_encode($proof)));

            $this->send($request);
        }
    }

    /** Fetch published bytes by id (`GET /{id}`), or null if the gateway has no such object. */
    public function getData(string $id): ?string
    {
        $request = $this->requests->createRequest('GET', "{$this->gateway}/{$id}");

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // The transport message can carry the full gateway URL (creds in path/query
            // for authenticated gateways); keep it only on the chained previous exception.
            throw new ArweaveException('Arweave gateway request failed.', 0, $e);
        }

        if ($response->getStatusCode() === 404) {
            return null;
        }

        if ($response->getStatusCode() >= 400) {
            throw new ArweaveException("Arweave gateway returned HTTP {$response->getStatusCode()} for GET /{$id}.");
        }

        return (string) $response->getBody();
    }

    private function send(RequestInterface $request): string
    {
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // Keep the transport detail (possibly a creds-bearing URL) on the chained
            // previous exception only — never in the public message.
            throw new ArweaveException('Arweave gateway request failed.', 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $method = $request->getMethod();
            $path = $request->getUri()->getPath();
            throw new ArweaveException("Arweave gateway returned HTTP {$status} for {$method} {$path}.");
        }

        return (string) $response->getBody();
    }

    private static function discoverFactory(): RequestFactoryInterface&StreamFactoryInterface
    {
        if (class_exists(HttpFactory::class)) {
            return new HttpFactory;
        }

        if (class_exists(Psr17Factory::class)) {
            return new Psr17Factory;
        }

        throw new ArweaveException(
            'No PSR-17 HTTP factory found. Pass a RequestFactoryInterface and StreamFactoryInterface '.
            'to ArweaveClient, or install guzzlehttp/guzzle or nyholm/psr7.'
        );
    }
}
