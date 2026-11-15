<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The fake bank of `tests/fixtures/ts-vectors.json`: it answers in order with the very bytes the
 * TypeScript was answered with (null: no answer at all), and remembers each request as the
 * TypeScript's were recorded (URL, JSON body, authorization header).
 */
final class VectorHttp implements ClientInterface
{
    /** @var list<array{url: string, body: mixed, authorization: string|null}> */
    public array $requests = [];

    /** @param list<array{status: int, body: string}|null> $answers */
    public function __construct(private array $answers)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $auth = $request->getHeaderLine('authorization');
        $this->requests[] = [
            'url' => (string) $request->getUri(),
            'body' => json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR),
            'authorization' => $auth === '' ? null : $auth,
        ];
        $next = array_shift($this->answers);
        if ($next === null) {
            throw new class ('offline', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private readonly RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            };
        }
        return new Response($next['status'], ['content-type' => 'application/json'], $next['body']);
    }
}
