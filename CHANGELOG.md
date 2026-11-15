# Changelog

All notable changes to `basilbook/khqr`. Versions follow [Semantic Versioning](https://semver.org/)
and move in step with the npm twin, `@basilbook/khqr`: the same version for the same calls and the
same behavior.

## 0.1.0 (unreleased)

The first release: the same calls as `@basilbook/khqr` 0.1.0, checked against the TypeScript's test
vectors.

- `Khqr::encode()`: the KHQR text NBC's `bakong-khqr` SDK writes, checked against its test strings.
- `Khqr::decode()`: a KHQR read back into `KhqrDecoded`, its checksum verified (`KhqrDecodeException`, problem `format`, `crc` or `field`).
- `Khqr::readFields()`, `field()`, `amount()`, `minor()`, `decimals()`, `takes()`, `isBakongAccountId()`, `crc16()` (over UTF-8 bytes), `md5()` (Bakong's md5); constants `CURRENCY`, `CURRENCY_CODE`, `MAX`.
- `Khqr::image()`: a KHQR as PNG or SVG, a data URL or the file itself, at the size asked. chillerlan/php-qrcode is a dependency; only this call loads it.
- `Bakong\BakongClient`: whether a KHQR was paid (`check`), whether an account exists (`accountExists`), token renewal (`renewToken`), over PSR-18.
- Typed errors: `KhqrInputException` (with `problem`), `KhqrDecodeException`, `KhqrImageException`, `KhqrConfigException`.
