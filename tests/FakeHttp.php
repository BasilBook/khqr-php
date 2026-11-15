<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** A PSR-18 client answering what the test says, in turn, remembering what it was asked. Never Bakong. */
final class FakeHttp implements ClientInterface
{
    /** @var list<array{url: string, body: array<string, mixed>, raw: string, headers: array<string, list<string>>}> */
    public array $calls = [];

    /** @var list<array{0: array<string, mixed>|string|null, 1: int}|null> Each answer in turn (null: no answer); the last one repeats. */
    private array $answers = [];

    /** @param array<string, mixed>|string|null $json */
    public static function answering(array|string|null $json, int $status = 200): self
    {
        $fake = new self();
        $fake->answers = [[$json, $status]];
        return $fake;
    }

    /** @param array{0: array<string, mixed>|string|null, 1: int}|null ...$answers */
    public static function inTurn(?array ...$answers): self
    {
        $fake = new self();
        $fake->answers = array_values($answers);
        return $fake;
    }

    public static function offline(): self
    {
        $fake = new self();
        $fake->answers = [null];
        return $fake;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $raw = (string) $request->getBody();
        $this->calls[] = [
            'url' => (string) $request->getUri(),
            'body' => (array) json_decode($raw, true),
            'raw' => $raw,
            'headers' => $request->getHeaders(),
        ];
        $answer = count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
        if ($answer === null) {
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
        [$json, $status] = $answer;
        $body = is_string($json) ? $json : ($json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR));
        return new Response($status, ['content-type' => 'application/json'], $body);
    }
}
