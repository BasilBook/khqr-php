<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Bakong;

/**
 * What the Bakong client needs: your Bakong Open API token, and optionally the email it was made
 * for (a refused token is renewed for it). Passed in, never read from the environment. Never log it.
 * The PHP twin of `BakongConfig` in `@basilbook/khqr/bakong`.
 */
final class BakongConfig
{
    /**
     * @param string                                $token          Your Bakong Open API token. Empty: nothing is asked (`configured()` is false).
     * @param string|null                           $email          The email the token was made for: a refused token is renewed for it.
     * @param string|null                           $baseUrl        Bakong's address; NBC's production API (`BakongClient::URL`) unless given.
     * @param int                                   $timeoutMs      How long one call may take, in milliseconds (the default Guzzle client only).
     * @param (\Closure(string): void)|null         $onTokenRenewed Told the new token after a renewal, to keep it.
     * @param (\Closure(string, string): void)|null $log            Told what happened (`info`, `warn` or `error`, then the words), without a secret in it.
     */
    public function __construct(
        public readonly string $token,
        public readonly ?string $email = null,
        public readonly ?string $baseUrl = null,
        public readonly int $timeoutMs = 8000,
        public readonly ?\Closure $onTokenRenewed = null,
        public readonly ?\Closure $log = null,
    ) {
    }

    /**
     * The same from an array with these keys: `token`, and optionally `email`, `baseUrl`,
     * `timeoutMs`, `onTokenRenewed`, `log`.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            token: (string) ($config['token'] ?? ''),
            email: isset($config['email']) && $config['email'] !== '' ? (string) $config['email'] : null,
            baseUrl: isset($config['baseUrl']) && $config['baseUrl'] !== '' ? (string) $config['baseUrl'] : null,
            timeoutMs: (int) ($config['timeoutMs'] ?? 8000),
            onTokenRenewed: isset($config['onTokenRenewed']) ? \Closure::fromCallable($config['onTokenRenewed']) : null,
            log: isset($config['log']) ? \Closure::fromCallable($config['log']) : null,
        );
    }

    /** Never print the token. */
    public function __debugInfo(): array
    {
        return ['email' => $this->email, 'baseUrl' => $this->baseUrl];
    }
}
