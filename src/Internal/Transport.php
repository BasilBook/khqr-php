<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Internal;

use BasilBook\Khqr\Exception\KhqrConfigException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * One JSON POST through the PSR-18 client the caller gave (Guzzle when none was given). Never
 * logs: bodies carry tokens.
 *
 * @internal
 */
final class Transport
{
    /** Why the last call got no answer (the client's own words), for a log line without a secret. */
    public ?string $lastError = null;

    private function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public static function make(?ClientInterface $client, ?RequestFactoryInterface $requests, ?StreamFactoryInterface $streams, int $timeoutMs): self
    {
        if ($client === null) {
            if (!class_exists(\GuzzleHttp\Client::class)) {
                throw new KhqrConfigException('Give the Bakong client a PSR-18 HTTP client, or install guzzlehttp/guzzle');
            }
            $seconds = $timeoutMs / 1000;
            $client = new \GuzzleHttp\Client(['timeout' => $seconds, 'connect_timeout' => $seconds, 'http_errors' => false]);
        }
        $factory = class_exists(\GuzzleHttp\Psr7\HttpFactory::class) ? new \GuzzleHttp\Psr7\HttpFactory() : null;
        $requests ??= $client instanceof RequestFactoryInterface ? $client : $factory;
        $streams ??= $client instanceof StreamFactoryInterface ? $client : $factory;
        if ($requests === null || $streams === null) {
            throw new KhqrConfigException('Give the Bakong client PSR-17 request and stream factories, or install guzzlehttp/psr7');
        }
        return new self($client, $requests, $streams);
    }

    /**
     * POSTs `$body` as JSON. Null when nothing came back (no connection, a timeout; `lastError`
     * says why); otherwise the HTTP status and the body read as JSON (null when it is not JSON).
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     *
     * @return array{status: int, json: mixed}|null
     */
    public function postJson(string $url, array $body, array $headers = []): ?array
    {
        $this->lastError = null;
        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('content-type', 'application/json')
            ->withBody($this->streams->createStream(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
        $text = (string) $response->getBody();
        try {
            $json = $text === '' ? null : json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $json = null;
        }
        return ['status' => $response->getStatusCode(), 'json' => $json];
    }
}
