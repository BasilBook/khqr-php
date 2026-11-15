<?php

declare(strict_types=1);

namespace BasilBook\Khqr;

use BasilBook\Khqr\Exception\KhqrDecodeException;
use BasilBook\Khqr\Exception\KhqrImageException;
use BasilBook\Khqr\Exception\KhqrInputException;
use BasilBook\Khqr\Internal\Image;

/**
 * KHQR, Cambodia's payment QR: the text a bank app scans. The layout is the one NBC's own
 * `bakong-khqr` SDK writes (EMVCo merchant-presented QR): every field is `tag` + two-digit length
 * + value, the amount and a lifetime are on it, and a CRC-16 closes it. A bank app refuses a code
 * whose checksum, lifetime or layout is off, so the tests check this against the SDK's own strings.
 *
 * Pure: no clock, no network. The caller gives the time and the lifetime. Only `image()` loads the
 * QR library. The PHP twin of `@basilbook/khqr`: the same calls (the package word dropped:
 * `encodeKhqr` is `Khqr::encode`), the same strings.
 */
final class Khqr
{
    /** ISO 4217 numbers KHQR takes: riel and US dollars only. */
    public const CURRENCY = ['KHR' => '116', 'USD' => '840'];

    /** The other way: the number on a KHQR to its money's code. */
    public const CURRENCY_CODE = ['116' => 'KHR', '840' => 'USD'];

    /** The longest values a KHQR field holds (the SDK's limits). */
    public const MAX = ['account' => 32, 'name' => 25, 'city' => 15, 'bill' => 25, 'store' => 25];

    /** Minor units in one major unit, for the money KHQR takes. */
    private const DECIMALS = ['KHR' => 0, 'USD' => 2];

    /** What `decode` trims from both ends: ASCII whitespace, as PHP's `trim` does. */
    private const TRIM = " \t\n\r\0\x0B";

    private function __construct()
    {
    }

    /**
     * The KHQR text for one payment: dynamic (the amount is on it), individual account (tag 29).
     *
     * @param KhqrInput|array<string, mixed> $input
     *
     * @throws KhqrInputException `problem`: currency, account, amount or lifetime.
     */
    public static function encode(KhqrInput|array $input): string
    {
        if (is_array($input)) {
            $input = KhqrInput::fromArray($input);
        }
        $currency = self::CURRENCY[$input->currency] ?? null;
        if ($currency === null) {
            throw new KhqrInputException('currency', "KHQR takes riel and US dollars, not {$input->currency}");
        }
        $accountId = trim($input->accountId);
        if (!self::isBakongAccountId($accountId)) {
            throw new KhqrInputException('account', 'That is not a Bakong account ID');
        }
        if (!($input->expiresAt > $input->createdAt)) {
            throw new KhqrInputException('lifetime', 'A KHQR must expire after it is made');
        }
        $name = Latin::field($input->merchantName, self::MAX['name']);
        $name = $name !== '' ? $name : 'Shop';
        $city = Latin::field($input->merchantCity ?? '', self::MAX['city']);
        $city = $city !== '' ? $city : 'Phnom Penh';

        $bill = $input->billNumber ? Latin::field($input->billNumber, self::MAX['bill']) : '';
        $store = $input->storeLabel ? Latin::field($input->storeLabel, self::MAX['store']) : '';
        $additional = ($bill !== '' ? self::field('01', $bill) : '') . ($store !== '' ? self::field('03', $store) : '');

        $body = self::field('00', '01')
            . self::field('01', '12')
            . self::field('29', self::field('00', $accountId))
            . self::field('52', '5999')
            . self::field('53', $currency)
            . self::field('54', self::amount($input->amount, $input->currency))
            . self::field('58', 'KH')
            . self::field('59', $name)
            . self::field('60', $city)
            . ($additional !== '' ? self::field('62', $additional) : '')
            . self::field('99', self::field('00', self::ms($input->createdAt)) . self::field('01', self::ms($input->expiresAt)))
            . '6304';
        return $body . self::crc16($body);
    }

    /**
     * Reads a KHQR back: every field, the account, the amount in minor units, the lifetime, and
     * the checksum verified first.
     *
     * @throws KhqrDecodeException `problem`: format (the layout is broken), crc (the checksum does
     *                             not match), field (a field holds what it may not).
     */
    public static function decode(string $text): KhqrDecoded
    {
        $qr = trim($text, self::TRIM);
        $tags = self::readFields($qr);
        $keys = array_map('strval', array_keys($tags));
        $crc = $tags['63'] ?? null;
        if (end($keys) !== '63' || !is_string($crc) || preg_match('/^[0-9A-Fa-f]{4}$/D', $crc) !== 1) {
            throw new KhqrDecodeException('format', 'A KHQR ends with its checksum (tag 63)');
        }
        $signed = substr($qr, 0, -4);
        $crc = strtoupper($crc);
        if (self::crc16($signed) !== $crc && !(preg_match('/[^\x00-\x7F]/', $signed) === 1 && self::unitCrc16($signed) === $crc)) {
            throw new KhqrDecodeException('crc', 'The KHQR checksum does not match its fields');
        }
        if (($tags['00'] ?? null) !== '01') {
            throw new KhqrDecodeException('format', 'A KHQR starts with payload format 01');
        }
        $accountTag = isset($tags['29']) ? '29' : (isset($tags['30']) ? '30' : null);
        if ($accountTag === null) {
            throw new KhqrDecodeException('format', 'A KHQR names a Bakong account (tag 29 or 30)');
        }
        $account = self::readFields($tags[$accountTag]);
        $accountId = $account['00'] ?? '';
        if ($accountId === '') {
            throw new KhqrDecodeException('format', 'A KHQR names a Bakong account (tag 29 or 30, field 00)');
        }
        $currency = self::CURRENCY_CODE[$tags['53'] ?? ''] ?? null;
        if ($currency === null) {
            throw new KhqrDecodeException('field', 'KHQR takes riel (116) and US dollars (840), not ' . ($tags['53'] ?? 'no money'));
        }
        $amount = null;
        if (isset($tags['54'])) {
            $amount = self::minor($tags['54'], $currency);
            if ($amount === null) {
                throw new KhqrDecodeException('field', "The KHQR amount {$tags['54']} is not a {$currency} amount (" . self::DECIMALS[$currency] . ' decimals)');
            }
        }
        $additional = isset($tags['62']) ? self::readFields($tags['62']) : [];
        $language = isset($tags['64']) ? self::readFields($tags['64']) : [];
        $times = isset($tags['99']) ? self::readFields($tags['99']) : [];
        foreach (['00', '01'] as $t) {
            if (isset($times[$t]) && preg_match('/^\d{1,16}$/D', $times[$t]) !== 1) {
                throw new KhqrDecodeException('field', 'A KHQR time (tag 99) is milliseconds since the epoch');
            }
        }

        return new KhqrDecoded(
            tags: $tags,
            pointOfInitiation: $tags['01'] ?? null,
            accountType: $accountTag === '29' ? 'individual' : 'merchant',
            accountId: $accountId,
            account: $account,
            acquiringBank: $account['02'] ?? null,
            merchantId: $accountTag === '30' ? ($account['01'] ?? null) : null,
            merchantCategoryCode: $tags['52'] ?? null,
            merchantName: $tags['59'] ?? null,
            merchantCity: $tags['60'] ?? null,
            countryCode: $tags['58'] ?? null,
            currency: $currency,
            amount: $amount,
            billNumber: $additional['01'] ?? null,
            mobileNumber: $additional['02'] ?? null,
            storeLabel: $additional['03'] ?? null,
            terminalLabel: $additional['07'] ?? null,
            purpose: $additional['08'] ?? null,
            additional: $additional,
            alternateLanguage: $language,
            createdAt: isset($times['00']) ? (int) $times['00'] : null,
            expiresAt: isset($times['01']) ? (int) $times['01'] : null,
            crc: $crc,
        );
    }

    /**
     * Fields `tag` + two-digit length + value, one after another, into a map (tag to value, in
     * the order written). Lengths count characters (Unicode code points), as the SDK counts them.
     * PHP keeps a tag such as `'29'` as the integer key 29; `$fields['29']` reads it either way.
     *
     * @return array<string, string>
     *
     * @throws KhqrDecodeException `format`: empty, a broken field, one running past the end, or a tag written twice.
     */
    public static function readFields(string $text): array
    {
        $chars = $text === '' ? [] : preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new KhqrDecodeException('format', 'A KHQR is text (UTF-8)');
        }
        $n = count($chars);
        if ($n === 0) {
            throw new KhqrDecodeException('format', 'A KHQR field list is empty');
        }
        $fields = [];
        $pos = 0;
        while ($pos < $n) {
            $head = implode('', array_slice($chars, $pos, 4));
            if (preg_match('/^(\d{2})(\d{2})$/D', $head, $m) !== 1) {
                throw new KhqrDecodeException('format', "A KHQR field at {$pos} has no tag and length");
            }
            [, $tag, $length] = $m;
            $length = (int) $length;
            if ($pos + 4 + $length > $n) {
                throw new KhqrDecodeException('format', "KHQR field {$tag} is shorter than its length");
            }
            if (isset($fields[$tag])) {
                throw new KhqrDecodeException('format', "KHQR field {$tag} is written twice");
            }
            $fields[$tag] = implode('', array_slice($chars, $pos + 4, $length));
            $pos += 4 + $length;
        }
        return $fields;
    }

    /** One field: tag, length in two digits, value. */
    public static function field(string $tag, string $value): string
    {
        $length = preg_match('//u', $value) === 1 ? mb_strlen($value, 'UTF-8') : strlen($value);
        return $tag . str_pad((string) $length, 2, '0', STR_PAD_LEFT) . $value;
    }

    /**
     * The amount as KHQR writes it: riel whole; dollars plain when whole, else with two decimals.
     * 20900 riel is "20900", 500 cents is "5", 450 cents is "4.50".
     *
     * @throws KhqrInputException `amount` when `$minor` is not above zero; `currency` when not riel or dollars.
     */
    public static function amount(int $minor, string $currency): string
    {
        if ($minor <= 0) {
            throw new KhqrInputException('amount', 'A KHQR amount is a positive whole number of minor units');
        }
        $decimals = self::decimals($currency);
        $unit = 10 ** $decimals;
        if ($minor % $unit === 0) {
            return (string) intdiv($minor, $unit);
        }
        return intdiv($minor, $unit) . '.' . str_pad((string) ($minor % $unit), $decimals, '0', STR_PAD_LEFT);
    }

    /**
     * A KHQR amount read back into minor units, without floating point: "4.5" dollars is 450
     * cents, "20900" riel is 20900. Null when it is not riel or dollars, not a plain positive
     * decimal, or has more places than the money has.
     */
    public static function minor(string $amount, string $currency): ?int
    {
        $decimals = self::DECIMALS[$currency] ?? null;
        if ($decimals === null || preg_match('/^(\d{1,13})(?:\.(\d+))?$/D', $amount, $m) !== 1) {
            return null;
        }
        $fraction = rtrim($m[2] ?? '', '0');
        if (strlen($fraction) > $decimals) {
            return null;
        }
        $minor = (int) $m[1] * 10 ** $decimals + (int) str_pad($fraction, $decimals, '0');
        return $minor > 0 ? $minor : null;
    }

    /**
     * How many decimals a KHQR money is written with: riel 0, dollars 2.
     *
     * @throws KhqrInputException `currency` for any other money.
     */
    public static function decimals(string $currency): int
    {
        $decimals = self::DECIMALS[$currency] ?? null;
        if ($decimals === null) {
            throw new KhqrInputException('currency', "KHQR takes riel and US dollars, not {$currency}");
        }
        return $decimals;
    }

    /** KHQR takes this money: `KHR` or `USD` (upper case, as ISO 4217 writes it). */
    public static function takes(string $currency): bool
    {
        return isset(self::CURRENCY[$currency]);
    }

    /** A Bakong account ID as KHQR carries it: `name@bank`, no spaces, up to 32 characters. */
    public static function isBakongAccountId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+$/D', $id) === 1 && strlen($id) <= self::MAX['account'];
    }

    /**
     * The md5 Bakong knows a KHQR by: of its whole text (UTF-8), lower-case hex. What Bakong's
     * `check_transaction_by_md5` is asked with. For this lookup only; never for passwords.
     */
    public static function md5(string $text): string
    {
        return md5($text);
    }

    /**
     * CRC-16/CCITT-FALSE (poly 0x1021, start 0xFFFF) over the text's UTF-8 bytes, as four
     * upper-case hex digits. Every field `encode` writes is printable ASCII, where a character is its byte.
     */
    public static function crc16(string $text): string
    {
        $crc = 0xFFFF;
        $length = strlen($text);
        for ($k = 0; $k < $length; $k++) {
            $crc = self::crcByte($crc, ord($text[$k]));
        }
        return sprintf('%04X', $crc);
    }

    /**
     * A KHQR as an image, drawn from its text (or from what makes the text). The image always
     * carries exactly the text, black on white with its quiet zone.
     *
     * @param string|KhqrInput|array<string, mixed> $source          The KHQR text, or what to make one from (`encode`).
     * @param 'png'|'svg'                           $type            PNG needs ext-gd; SVG needs nothing.
     * @param 'dataUrl'|'bytes'                     $as              A `data:` URL for an `<img src>`, or the file itself.
     * @param int                                   $size            The image's width and height, in pixels (PNG) or user units (SVG).
     * @param int                                   $margin          The quiet zone around the code, in modules.
     * @param 'L'|'M'|'Q'|'H'                       $errorCorrection How much of the code may be lost and still read.
     *
     * @throws KhqrImageException An unknown type, form, size or level; nothing to draw; no GD for a PNG.
     */
    public static function image(
        string|KhqrInput|array $source,
        string $type = 'png',
        string $as = 'dataUrl',
        int $size = 512,
        int $margin = 4,
        string $errorCorrection = 'M',
    ): string {
        $text = is_string($source) ? $source : self::encode($source);
        return Image::draw($text, $type, $as, $size, $margin, $errorCorrection);
    }

    private static function ms(int|float $ms): string
    {
        return is_int($ms) ? (string) $ms : sprintf('%.0F', $ms < 0 ? ceil($ms) : floor($ms));
    }

    private static function crcByte(int $crc, int $byte): int
    {
        $crc ^= $byte << 8;
        for ($i = 0; $i < 8; $i++) {
            $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
        }
        return $crc;
    }

    /**
     * The checksum as a JavaScript encoder counts a text with non-ASCII in it: each UTF-16 unit's
     * low byte (`charCodeAt(k) & 0xff`). Some KHQRs carry Khmer (tag 64) made that way.
     */
    private static function unitCrc16(string $text): string
    {
        $crc = 0xFFFF;
        $units = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        $length = strlen($units);
        for ($k = 1; $k < $length; $k += 2) {
            $crc = self::crcByte($crc, ord($units[$k]));
        }
        return sprintf('%04X', $crc);
    }
}
