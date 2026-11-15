# basilbook/khqr

KHQR, Cambodia's payment QR, for PHP. Make the text a bank app scans, read one back with its
checksum verified, find the md5 Bakong knows it by, draw it as a PNG or SVG, and ask NBC's Bakong
whether it was paid.

The encoder writes exactly what NBC's own `bakong-khqr` SDK writes (EMVCo merchant-presented QR:
tag, two-digit length, value; the amount and a lifetime on it; a CRC-16 to close it), and the tests
check it against the SDK's own strings. Making and reading a code is pure: no clock, no network.

Made by BasilBook for Bill&Go, and MIT licensed for anyone. The TypeScript twin is
[`@basilbook/khqr`](https://www.npmjs.com/package/@basilbook/khqr): **the same calls, with the same
names, the same results and the same errors**, checked against the same test vectors. The whole
family (KHQR, ABA PayWay, Canadia, ACLEDA, in PHP and TypeScript) is listed in
[packages/README.md](../../README.md).

- [Install](#install)
- [Quick start](#quick-start)
- [A string or an image](#a-string-or-an-image)
- [Has it been paid? (Bakong)](#has-it-been-paid-bakong)
- [Money](#money)
- [API reference](#api-reference)
- [The same calls as the TypeScript](#the-same-calls-as-the-typescript)
- [Requirements](#requirements)
- [Running the tests](#running-the-tests)
- [License](#license)

## Install

```bash
composer require basilbook/khqr
```

The image library ([chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode)) comes with
it, so images work out of the box; only `Khqr::image()` loads it. To ask Bakong, add Guzzle too
(`composer require guzzlehttp/guzzle`) or pass your own PSR-18 client.

## Quick start

```php
use BasilBook\Khqr\Khqr;
use BasilBook\Khqr\KhqrInput;

$now = (int) floor(microtime(true) * 1000);           // milliseconds since the epoch

$qr = Khqr::encode(new KhqrInput(
    accountId: 'lotus_lime@aclb',                      // the Bakong account the money lands in
    merchantName: 'Lotus & Lime',
    amount: 450,                                       // $4.50, in cents
    currency: 'USD',
    createdAt: $now,
    expiresAt: $now + 5 * 60 * 1000,                   // bank apps refuse it after five minutes
    merchantCity: 'Siem Reap',
    billNumber: '20261115-0007',                       // shown on the payer's receipt
));

Khqr::decode($qr)->amount;   // 450
Khqr::md5($qr);              // what Bakong's check_transaction_by_md5 takes
```

An array with the same keys works too: `Khqr::encode(['accountId' => ..., 'amount' => 450, ...])`.

## A string or an image

The text is the payment; the image is the text drawn as a QR code. Show either.

```php
$dataUrl = Khqr::image($qr);                                         // PNG data URL, 512 px: <img src="...">
$svg     = Khqr::image($qr, type: 'svg', as: 'bytes');               // the SVG file itself
$png     = Khqr::image($input, as: 'bytes', size: 300, margin: 2);   // made from the input first
```

Black on white with its quiet zone, error correction M unless asked. The tests scan each PNG back
and check it carries the very text. PNG needs ext-gd; SVG needs nothing.

## Has it been paid? (Bakong)

```php
use BasilBook\Khqr\Bakong\BakongClient;

$bakong = new BakongClient([
    'token' => $bakongToken,                           // from api-bakong.nbc.gov.kh
    'email' => 'you@example.com',                      // a refused token is renewed for it
    'onTokenRenewed' => fn (string $token) => saveSomewhere($token),
]);

$bakong->check(Khqr::md5($qr), accountId: 'lotus_lime@aclb', amount: 450, currency: 'USD');
// 'paid' | 'failed' | 'pending'
```

`check` says `paid` only when the account, the amount and the money all match what the QR asked
for; real money that does not match is `pending`, for a person to look at. It never throws: no
answer is `pending`, and you ask again. When Bakong refuses the token as run out (HTTP 401) and an
email is given, it is renewed once, kept, handed to `onTokenRenewed`, and the call asked again.

## Money

Every amount is **integer minor units plus a currency code**, never a float: riel is whole
(20900 is 20,900 riel), US dollars are cents (450 is $4.50). KHQR takes `KHR` and `USD` only.
The amount is written as KHQR wants it: riel whole, dollars plain when whole (`"5"`) and to the
cent otherwise (`"4.50"`).

## API reference

All classes are in `BasilBook\Khqr`. Every exception extends
`BasilBook\Khqr\Exception\KhqrException` (a `RuntimeException`).

### `Khqr` (static)

| Method | Returns | Throws |
|---|---|---|
| `encode(KhqrInput\|array $input)` | The KHQR text for one payment: dynamic (the amount on it), an individual's account (tag 29), a lifetime (tag 99), the CRC-16. | `KhqrInputException`, `problem`: `currency`, `account`, `amount` or `lifetime` |
| `decode(string $text)` | `KhqrDecoded`, the checksum verified first. | `KhqrDecodeException`, `problem`: `format`, `crc` or `field` |
| `readFields(string $text)` | Tag => value, in the order written. | `KhqrDecodeException` (`format`) |
| `field(string $tag, string $value)` | One field: tag, two-digit length, value. | |
| `amount(int $minor, string $currency)` | The amount as KHQR writes it: `"20900"`, `"5"`, `"4.50"`. | `KhqrInputException` (`amount`, then `currency`) |
| `minor(string $amount, string $currency)` | The other way, without floating point: `"4.5"` USD is 450; null when it is not a riel or dollar amount above zero. | |
| `decimals(string $currency)` | 0 for riel, 2 for dollars. | `KhqrInputException` (`currency`) for any other |
| `takes(string $currency)` | `true` for `KHR` and `USD`. | |
| `isBakongAccountId(string $id)` | `true` for `name@bank`, up to 32 characters. | |
| `md5(string $text)` | 32 lower-case hex digits: what Bakong knows a KHQR by. For this lookup only, never for passwords. | |
| `crc16(string $text)` | CRC-16/CCITT-FALSE over the text's UTF-8 bytes, four upper-case hex digits. | |
| `image(string\|KhqrInput\|array $source, string $type = 'png', string $as = 'dataUrl', int $size = 512, int $margin = 4, string $errorCorrection = 'M')` | A data URL (`data:image/png;base64,…`), or with `as: 'bytes'` the file itself. `size`: pixels wide and high; `margin`: the quiet zone in squares; `errorCorrection`: `L`, `M`, `Q` or `H`. | `KhqrImageException`: another type, form, size or level, nothing to draw, no GD for a PNG |

Constants: `Khqr::CURRENCY` (`KHR` => `116`, `USD` => `840`), `Khqr::CURRENCY_CODE` (the other
way), `Khqr::MAX` (the longest account, name, city, bill and store fields).

### `KhqrInput`

`new KhqrInput(accountId, merchantName, amount, currency, createdAt, expiresAt, merchantCity = null, billNumber = null, storeLabel = null)`,
or `KhqrInput::fromArray([...])` with the same names.

| Property | Type | What it is |
|---|---|---|
| `accountId` | `string` | Bakong account, `name@bank`, up to 32 characters. Trimmed. |
| `merchantName` | `string` | Who the payer pays. Latin only (accents folded, the rest dropped), cut to 25; `Shop` when nothing is left. |
| `amount` | `int` | Integer minor units, above zero. |
| `currency` | `string` | `KHR` or `USD`. |
| `createdAt`, `expiresAt` | `int\|float` | Epoch milliseconds; `expiresAt` after `createdAt`. |
| `merchantCity` | `?string` | Cut to 15; `Phnom Penh` when not given. |
| `billNumber` | `?string` | On the payer's receipt (tag 62, field 01), cut to 25. |
| `storeLabel` | `?string` | The store's name on the receipt (tag 62, field 03), cut to 25. |

### `KhqrDecoded`

Read-only properties, null when the code does not carry them: `tags` (every top-level field, tag to
value), `pointOfInitiation` (`12` amount on the code, `11` payer types it), `accountType`
(`individual` tag 29, `merchant` tag 30), `accountId`, `account` (the account tag's fields),
`acquiringBank`, `merchantId` (a merchant code's), `merchantCategoryCode`, `merchantName`,
`merchantCity`, `countryCode`, `currency` (`KHR` or `USD`), `amount` (minor units), `billNumber`,
`mobileNumber`, `storeLabel`, `terminalLabel`, `purpose`, `additional` (tag 62's fields),
`alternateLanguage` (tag 64's fields), `createdAt`, `expiresAt` (epoch ms), `crc`.

PHP keeps a tag such as `'29'` as the integer key 29: `$decoded->tags['29']` and `$decoded->tags[29]`
both read it.

### `Bakong\BakongClient`

`new BakongClient(BakongConfig|array $config, ?ClientInterface $http = null, ?RequestFactoryInterface $requests = null, ?StreamFactoryInterface $streams = null)`

| `BakongConfig` | |
|---|---|
| `token` | Bakong Open API token. Empty: nothing is asked |
| `email` | The email the token was made for; a 401 renews the token once |
| `baseUrl` | `https://api-bakong.nbc.gov.kh` (`BakongClient::URL`) unless given |
| `timeoutMs` | 8000 (the default Guzzle client only) |
| `onTokenRenewed` | `fn (string $token)`: told the new token, to keep it |
| `log` | `fn (string $level, string $message)`: `info`, `warn`, `error`; never a secret |

| Method | Returns | |
|---|---|---|
| `check(string $md5, string $accountId, int $amount, string $currency)` | `'paid'\|'failed'\|'pending'` | `amount` in minor units. Never throws |
| `accountExists(string $accountId)` | `?bool` | null when it could not be asked. Never throws |
| `renewToken()` | `bool` | Renews for `email`. Never throws |
| `configured()`, `token()` | `bool`, `string` | A token is held; the one in use |

No PSR-18 client given and Guzzle not installed: `KhqrConfigException`.

### Exceptions

| Class | When | Carries |
|---|---|---|
| `KhqrInputException` | `encode`, `amount`, `decimals` cannot make a code a bank app would take | `problem` |
| `KhqrDecodeException` | `decode`, `readFields` were given something that is not a KHQR, or its checksum is off | `problem` |
| `KhqrImageException` | `image` cannot draw it | |
| `KhqrConfigException` | `BakongClient` has no HTTP client (PHP only) | |

## The same calls as the TypeScript

| TypeScript (`@basilbook/khqr`) | PHP |
|---|---|
| `encodeKhqr(input)` | `Khqr::encode($input)` |
| `decodeKhqr(text)` | `Khqr::decode($text)` |
| `readKhqrFields(text)`, `khqrField(tag, value)` | `Khqr::readFields()`, `Khqr::field()` |
| `khqrAmount`, `khqrMinor`, `khqrDecimals`, `khqrTakes` | `Khqr::amount`, `minor`, `decimals`, `takes` |
| `isBakongAccountId`, `khqrMd5`, `crc16` | `Khqr::isBakongAccountId`, `md5`, `crc16` |
| `KHQR_CURRENCY`, `KHQR_CURRENCY_CODE`, `KHQR_MAX` | `Khqr::CURRENCY`, `CURRENCY_CODE`, `MAX` |
| `khqrImage(source, { type, as, size, margin, errorCorrection })` (`/image`) | `Khqr::image($source, type:, as:, size:, margin:, errorCorrection:)` |
| `new BakongClient(config)` (`/bakong`) | `new Bakong\BakongClient($config)` |
| `bakong.check(md5, { accountId, amount, currency })` | `$bakong->check($md5, accountId:, amount:, currency:)` |
| `bakong.configured`, `bakong.token` | `$bakong->configured()`, `$bakong->token()` |
| `KhqrInputError`, `KhqrDecodeError`, `KhqrImageError` | `KhqrInputException`, `KhqrDecodeException`, `KhqrImageException` |

What differs is only how each language says it: a TypeScript function named for the package
(`encodeKhqr`) is a static method on `Khqr` with the package word dropped (`Khqr::encode`); an
options object is named arguments with the same names; a getter is a method; `fetch` is a PSR-18
client; an `Error` is an `Exception`; `as: 'bytes'` is a `Uint8Array` there and a binary string
here; a promise is the value itself.

## Requirements

- PHP 8.1 or later, `ext-json`, `ext-mbstring`.
- `ext-gd` for PNG images only. SVG needs nothing.
- `ext-intl` is used when present to fold accented names (Unicode NFKD). Without it a built-in
  table, made from the same NFKD, folds the same Latin letters.
- For `BakongClient`: a PSR-18 client and PSR-17 factories, or `guzzlehttp/guzzle`.

## Running the tests

```bash
cd packages/php/khqr
composer install
composer test          # or: vendor/bin/phpunit
```

Nothing reaches Bakong: the Bakong tests use a fake PSR-18 client. `tests/fixtures/ts-vectors.json`
holds what the TypeScript package wrote and answered for the same inputs; the tests check PHP does
the same.

## License

MIT, copyright BasilBook. See [LICENSE](LICENSE).
