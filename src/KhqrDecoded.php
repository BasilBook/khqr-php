<?php

declare(strict_types=1);

namespace BasilBook\Khqr;

/**
 * A KHQR read back by `Khqr::decode()`, its checksum verified: every field as it was written, and
 * the ones people ask about by name. Money is integer minor units of `currency` (450 with `USD` is
 * $4.50). Times are milliseconds since the epoch. Fields a code does not carry are null.
 *
 * The arrays are tag to value. PHP keeps a tag such as `'29'` as the integer key 29; read a value
 * with either `$tags['29']` or `$tags[29]`. The PHP twin of `KhqrDecoded` in `@basilbook/khqr`.
 */
final class KhqrDecoded
{
    /**
     * @param array<int|string, string> $tags                 Every top-level field, in the order written (a template such as 29 or 62 still packed).
     * @param string|null               $pointOfInitiation    `12` when the amount is on the code (made for one payment), `11` when the payer types it.
     * @param 'individual'|'merchant'   $accountType          Tag 29 (an individual's account) or tag 30 (a merchant's).
     * @param string                    $accountId            The Bakong account the money lands in, e.g. `shop@aba`.
     * @param array<int|string, string> $account              Every field of the account (tag 29 or 30).
     * @param string|null               $acquiringBank        The account's bank, when the code names one (field 02 of tag 29 or 30).
     * @param string|null               $merchantId           A merchant code's merchant ID (field 01 of tag 30); null for an individual's.
     * @param 'KHR'|'USD'               $currency             The money.
     * @param int|null                  $amount               Minor units; null on a code with no amount on it.
     * @param array<int|string, string> $additional           Tag 62's fields (bill number 01, mobile 02, store 03, terminal 07, purpose 08, ...).
     * @param array<int|string, string> $alternateLanguage    Tag 64's fields (language 00, name 01, city 02).
     * @param string                    $crc                  The four hex digits that close the code, already checked.
     */
    public function __construct(
        public readonly array $tags,
        public readonly ?string $pointOfInitiation,
        public readonly string $accountType,
        public readonly string $accountId,
        public readonly array $account,
        public readonly ?string $acquiringBank,
        public readonly ?string $merchantId,
        public readonly ?string $merchantCategoryCode,
        public readonly ?string $merchantName,
        public readonly ?string $merchantCity,
        public readonly ?string $countryCode,
        public readonly string $currency,
        public readonly ?int $amount,
        public readonly ?string $billNumber,
        public readonly ?string $mobileNumber,
        public readonly ?string $storeLabel,
        public readonly ?string $terminalLabel,
        public readonly ?string $purpose,
        public readonly array $additional,
        public readonly array $alternateLanguage,
        public readonly ?int $createdAt,
        public readonly ?int $expiresAt,
        public readonly string $crc,
    ) {
    }
}
