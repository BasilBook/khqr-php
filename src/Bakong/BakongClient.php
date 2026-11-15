<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Bakong;

use BasilBook\Khqr\Currency;
use BasilBook\Khqr\Exception\KhqrConfigException;
use BasilBook\Khqr\Internal\Transport;
use BasilBook\Khqr\Khqr;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * NBC's Bakong Open API, asked whether a KHQR was paid. You make the KHQR yourself
 * (`Khqr::encode`); Bakong knows it by the md5 of its text (`Khqr::md5`). Asking never throws: a
 * dropped connection or a refusal is "pending", and the caller asks again.
 *
 * Bakong's token is made for an email at api-bakong.nbc.gov.kh. When Bakong refuses it as run out
 * (HTTP 401) and an email is given, it is renewed once, kept by the client, and handed to
 * `onTokenRenewed` so it can be stored for the next start.
 *
 * The PHP twin of `BakongClient` in `@basilbook/khqr/bakong`: the same calls, the same answers.
 */
final class BakongClient
{
    /** NBC's production Bakong Open API. */
    public const URL = 'https://api-bakong.nbc.gov.kh';

    private readonly BakongConfig $config;
    private readonly Transport $http;
    private string $tokenNow;

    /**
     * @param BakongConfig|array<string, mixed> $config   The token and its email (see `BakongConfig`).
     * @param ClientInterface|null              $http     A PSR-18 client; Guzzle when null (install guzzlehttp/guzzle).
     * @param RequestFactoryInterface|null      $requests PSR-17; Guzzle's when null.
     * @param StreamFactoryInterface|null       $streams  PSR-17; Guzzle's when null.
     *
     * @throws KhqrConfigException When no HTTP client is given and Guzzle is not installed.
     */
    public function __construct(
        BakongConfig|array $config,
        ?ClientInterface $http = null,
        ?RequestFactoryInterface $requests = null,
        ?StreamFactoryInterface $streams = null,
    ) {
        $this->config = is_array($config) ? BakongConfig::fromArray($config) : $config;
        $this->http = Transport::make($http, $requests, $streams, $this->config->timeoutMs);
        $this->tokenNow = $this->config->token;
    }

    /** A token is held: Bakong can be asked. */
    public function configured(): bool
    {
        return $this->tokenNow !== '';
    }

    /** The token in use: the one given, or the one a renewal gave. */
    public function token(): string
    {
        return $this->tokenNow;
    }

    /**
     * Paid, failed or not yet — and paid only when the account, the amount and the money match
     * what the QR asked for. Real money that does not match is never "paid": it is "pending", and
     * a person confirms it. Never throws.
     *
     * @param string $md5       `Khqr::md5` of the KHQR text.
     * @param string $accountId The Bakong account the QR asked the money for.
     * @param int    $amount    Minor units the QR asked for: riel whole, dollars in cents.
     * @param string $currency  `KHR` or `USD`.
     *
     * @return 'pending'|'paid'|'failed'
     */
    public function check(string $md5, string $accountId, int $amount, string $currency): string
    {
        if (!$this->configured() || !Khqr::takes($currency)) {
            return 'pending';
        }
        $res = $this->authorized('/v1/check_transaction_by_md5', ['md5' => $md5]);
        if ($res === null || !self::ok($res['status'])) {
            if ($res !== null) {
                $this->log('warn', "Bakong check answered {$res['status']}");
            }
            return 'pending';
        }
        $body = $res['json'];
        if (!is_array($body)) {
            return 'pending';
        }
        $data = $body['data'] ?? null;
        if (($body['responseCode'] ?? null) === 0 && is_array($data)) {
            $major = $amount / 10 ** Khqr::decimals($currency);
            $paid = self::jsNumber($data['amount'] ?? null);
            $matches = strtolower(self::text($data['toAccountId'] ?? null)) === strtolower($accountId)
                && strtoupper(self::text($data['currency'] ?? null)) === $currency
                && $paid !== null && abs($paid - $major) < 0.005;
            if (!$matches) {
                $this->log('error', sprintf(
                    'Bakong %s: paid to %s %s %s, not what the QR asked',
                    $md5,
                    self::shown($data['toAccountId'] ?? null),
                    self::shown($data['amount'] ?? null),
                    self::shown($data['currency'] ?? null),
                ));
                return 'pending';
            }
            return 'paid';
        }
        if (($body['errorCode'] ?? null) === 3) {
            return 'failed';
        }
        return 'pending';
    }

    /**
     * Is `name@bank` a Bakong account? Bakong's `check_bakong_account`. It says only yes or no:
     * Bakong gives no account holder's name. True: it exists; false: Bakong said there is no such
     * account; null: it could not be asked (no token, no answer, an answer not read). Never throws.
     */
    public function accountExists(string $accountId): ?bool
    {
        if (!$this->configured()) {
            return null;
        }
        $res = $this->authorized('/v1/check_bakong_account', ['accountId' => $accountId]);
        if ($res === null || !self::ok($res['status'])) {
            if ($res !== null) {
                $this->log('warn', "Bakong account check answered {$res['status']}");
            }
            return null;
        }
        $body = $res['json'];
        if (!is_array($body)) {
            return null;
        }
        $code = $body['responseCode'] ?? null;
        if ($code === 0) {
            return true;
        }
        // "No such account" is a refusal with its reason as an error code; anything else is not understood.
        if ($code === 1 && ($body['errorCode'] ?? null) !== null) {
            return false;
        }
        return null;
    }

    /** A fresh token for the email. False when there is no email or Bakong would not give one. Never throws. */
    public function renewToken(): bool
    {
        if ($this->config->email === null || $this->config->email === '') {
            return false;
        }
        $res = $this->post('/v1/renew_token', ['email' => $this->config->email], false);
        $body = $res !== null && self::ok($res['status']) && is_array($res['json']) ? $res['json'] : null;
        $token = is_array($body['data'] ?? null) ? ($body['data']['token'] ?? null) : null;
        if (!is_string($token) || $token === '') {
            $this->log('error', 'Bakong refused the token and did not renew it: set a new token');
            return false;
        }
        $this->tokenNow = $token;
        $this->log('info', 'Bakong token renewed');
        if ($this->config->onTokenRenewed !== null) {
            ($this->config->onTokenRenewed)($token);
        }
        return true;
    }

    /**
     * One call with the token; a 401 renews it once and asks again.
     *
     * @param array<string, mixed> $body
     *
     * @return array{status: int, json: mixed}|null
     */
    private function authorized(string $path, array $body): ?array
    {
        $res = $this->post($path, $body);
        if ($res !== null && $res['status'] === 401 && $this->renewToken()) {
            return $this->post($path, $body);
        }
        return $res;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{status: int, json: mixed}|null
     */
    private function post(string $path, array $body, bool $auth = true): ?array
    {
        $base = rtrim($this->config->baseUrl ?? self::URL, '/');
        $res = $this->http->postJson($base . $path, $body, $auth ? ['authorization' => "Bearer {$this->tokenNow}"] : []);
        if ($res === null) {
            $this->log('warn', "Bakong {$path} did not answer: " . ($this->http->lastError ?? 'no answer'));
        }
        return $res;
    }

    private static function ok(int $status): bool
    {
        return $status >= 200 && $status <= 299;
    }

    /** A value as JavaScript's `Number(x)` reads it; null for NaN. */
    private static function jsNumber(mixed $value): int|float|null
    {
        return match (true) {
            $value === null, $value === false => 0,
            $value === true => 1,
            is_int($value), is_float($value) => $value,
            is_string($value) => trim($value) === '' ? 0 : (is_numeric(trim($value)) ? trim($value) + 0 : null),
            default => null,
        };
    }

    /** A value as `(x ?? '')` reads it, for a comparison. */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? Currency::jsNumber($value) : '');
    }

    /** A value as a template string shows it, for a log line. */
    private static function shown(mixed $value): string
    {
        return match (true) {
            $value === null => 'undefined',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => Currency::jsNumber($value),
            is_string($value) => $value,
            default => '[object Object]',
        };
    }

    private function log(string $level, string $message): void
    {
        if ($this->config->log !== null) {
            ($this->config->log)($level, $message);
        }
    }
}
