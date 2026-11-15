<?php

declare(strict_types=1);

namespace BasilBook\Khqr;

/**
 * Money as the bank packages send it: minor units to a bank's major units and back, and a number
 * written as JavaScript writes it, so PHP sends the very bytes the TypeScript does. Riel has no
 * decimals, US dollars two, any other money two, unless a client's `decimals` says otherwise.
 *
 * @internal Shared by basilbook/payway, basilbook/canadia and basilbook/acleda; not part of the public API.
 */
final class Currency
{
    private const DECIMALS = ['KHR' => 0, 'USD' => 2];

    private function __construct()
    {
    }

    /** Decimals of `$code` as the clients read them by default: riel 0, dollars 2, anything else 2. */
    public static function decimals(string $code): int
    {
        return self::DECIMALS[$code] ?? 2;
    }

    /**
     * Minor units to major units, as a number that JSON-encodes the way JavaScript writes it:
     * 450 cents is 4.5, 500 cents is 5 (an int, never `5.0`), 20900 riel is 20900.
     */
    public static function toMajor(int $minor, int $decimals): int|float
    {
        $unit = 10 ** $decimals;
        if ($minor % $unit === 0) {
            return intdiv($minor, $unit);
        }
        return $minor / $unit;
    }

    /** Major units back to minor units, rounded to the nearest minor unit: 4.5 dollars is 450. */
    public static function toMinor(int|float $major, int $decimals): int
    {
        return (int) round($major * 10 ** $decimals);
    }

    /** A number as JavaScript's `String(n)` writes it: 4.5, 5, 0.01, 20900. */
    public static function jsNumber(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }
        // Shortest text that reads back to the same float, as JavaScript writes it.
        $was = ini_set('serialize_precision', '-1');
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } finally {
            if ($was !== false) {
                ini_set('serialize_precision', $was);
            }
        }
    }

    /**
     * A bank's number as these packages read it: a JSON number as it is, a numeric string as its
     * number; anything else (a word, true, a list) is not a number. Null when it is none.
     */
    public static function number(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? $value : null;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value) + 0;
        }
        return null;
    }
}
