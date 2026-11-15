<?php

declare(strict_types=1);

namespace BasilBook\Khqr;

/**
 * What one KHQR carries. Money is integer minor units plus a currency code: riel whole, US
 * dollars in cents (450 is $4.50). Times are milliseconds since the epoch, as in Bakong's own SDK.
 */
final class KhqrInput
{
    /**
     * @param string      $accountId    The Bakong account the money lands in, e.g. `shop@aba`.
     * @param string      $merchantName Who the payer's app says they are paying; Latin letters only, cut to 25.
     * @param int         $amount       Integer minor units: riel whole, dollars in cents.
     * @param string      $currency     `KHR` or `USD`.
     * @param int|float   $createdAt    Milliseconds since the epoch: when the code is made.
     * @param int|float   $expiresAt    Milliseconds since the epoch: when bank apps stop taking it.
     * @param string|null $merchantCity Where; cut to 15. Phnom Penh when not given.
     * @param string|null $billNumber   Shown on the payer's receipt, e.g. the order's reference.
     * @param string|null $storeLabel   The store's name on the payer's receipt.
     */
    public function __construct(
        public readonly string $accountId,
        public readonly string $merchantName,
        public readonly int $amount,
        public readonly string $currency,
        public readonly int|float $createdAt,
        public readonly int|float $expiresAt,
        public readonly ?string $merchantCity = null,
        public readonly ?string $billNumber = null,
        public readonly ?string $storeLabel = null,
    ) {
    }

    /**
     * The same, from an array with the TypeScript's keys (`accountId`, `merchantName`, `amount`,
     * `currency`, `createdAt`, `expiresAt`, and optionally `merchantCity`, `billNumber`, `storeLabel`).
     *
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        // A missing key is the problem it would be: no money, no account, no amount, no lifetime.
        $problems = ['accountId' => 'account', 'merchantName' => 'account', 'amount' => 'amount', 'currency' => 'currency', 'createdAt' => 'lifetime', 'expiresAt' => 'lifetime'];
        foreach ($problems as $key => $problem) {
            if (!array_key_exists($key, $input)) {
                throw new Exception\KhqrInputException($problem, "A KHQR needs {$key}");
            }
        }
        if (!is_int($input['amount'])) {
            throw new Exception\KhqrInputException('amount', 'A KHQR amount is a positive whole number of minor units');
        }
        return new self(
            accountId: (string) $input['accountId'],
            merchantName: (string) $input['merchantName'],
            amount: $input['amount'],
            currency: (string) $input['currency'],
            createdAt: is_float($input['createdAt']) ? $input['createdAt'] : (int) $input['createdAt'],
            expiresAt: is_float($input['expiresAt']) ? $input['expiresAt'] : (int) $input['expiresAt'],
            merchantCity: isset($input['merchantCity']) ? (string) $input['merchantCity'] : null,
            billNumber: isset($input['billNumber']) ? (string) $input['billNumber'] : null,
            storeLabel: isset($input['storeLabel']) ? (string) $input['storeLabel'] : null,
        );
    }
}
