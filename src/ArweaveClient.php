<?php

declare(strict_types=1);

namespace AgentImprint\Arweave;

use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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
        // The id is interpolated straight into the request URL — validate its shape
        // (unpadded base64url, as Arweave ids are) so a caller-supplied value can't
        // smuggle path/query segments into the gateway request.
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            throw new ArweaveException('Invalid Arweave transaction id.');
        }

        $response = $this->dispatch($this->requests->createRequest('GET', "{$this->gateway}/{$id}"));

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
        $response = $this->dispatch($request);

        $status = $response->getStatusCode();
        if ($status >= 400) {
            $method = $request->getMethod();
            $path = $request->getUri()->getPath();
            throw new ArweaveException("Arweave gateway returned HTTP {$status} for {$method} {$path}.");
        }

        return (string) $response->getBody();
    }

    /**
     * Send a request, wrapping any PSR-18 transport failure in an {@see ArweaveException}
     * whose public message carries no gateway URL — the underlying message (which can
     * embed creds in the host/path/query for an authenticated gateway) stays only on the
     * chained `previous` exception.
     */
    private function dispatch(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ArweaveException('Arweave gateway request failed.', 0, $e);
        }
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
