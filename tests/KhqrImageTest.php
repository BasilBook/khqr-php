<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Tests;

use BasilBook\Khqr\Exception\KhqrImageException;
use BasilBook\Khqr\Khqr;
use BasilBook\Khqr\KhqrInput;
use chillerlan\QRCode\QRCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KhqrImageTest extends TestCase
{
    private const QR = '00020101021229180014vandoeurn@devb52045999530384054031015802KH5909Vandoeurn6010Phnom Penh9934001317272493649740113172724948497463047BD0';

    private static function needGd(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is not loaded');
        }
    }

    public function testDrawsAPngDataUrlOf512PixelsByDefault(): void
    {
        self::needGd();
        $url = Khqr::image(self::QR);
        self::assertStringStartsWith('data:image/png;base64,', $url);
        $png = (string) base64_decode(substr($url, strlen('data:image/png;base64,')), true);
        self::assertSame([512, 512], array_slice((array) getimagesizefromstring($png), 0, 2));
        // Scanned back with the QR library's own reader: the picture carries the very text.
        self::assertSame(self::QR, (string) (new QRCode())->readFromBlob($png));
    }

    public function testDrawsThePngAtTheSizeAsked(): void
    {
        self::needGd();
        $png = Khqr::image(self::QR, as: 'bytes', size: 300, margin: 2);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        self::assertSame([300, 300], array_slice((array) getimagesizefromstring($png), 0, 2));
        self::assertSame(self::QR, (string) (new QRCode())->readFromBlob($png));
        self::assertSame($png, Khqr::image(self::QR, as: 'bytes', size: 300, margin: 2));
    }

    public function testDrawsAnSvgOnWhiteAtTheSizeAsked(): void
    {
        $svg = Khqr::image(self::QR, type: 'svg', as: 'bytes', size: 240);
        self::assertStringContainsString('<svg width="240" height="240"', $svg);
        self::assertStringContainsString('fill="#fff"', $svg);
        $url = Khqr::image(self::QR, type: 'svg', size: 240);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $url);
        self::assertSame($svg, base64_decode(substr($url, strlen('data:image/svg+xml;base64,')), true));
    }

    public function testDrawsFromAnInputToo(): void
    {
        $input = new KhqrInput('vandoeurn@devb', 'Vandoeurn', 10100, 'USD', 1727249364974, 1727249484974);
        self::assertSame(Khqr::image(self::QR, type: 'svg'), Khqr::image($input, type: 'svg'));
        self::assertSame(Khqr::image(self::QR, type: 'svg'), Khqr::image(['accountId' => 'vandoeurn@devb', 'merchantName' => 'Vandoeurn', 'amount' => 10100, 'currency' => 'USD', 'createdAt' => 1727249364974, 'expiresAt' => 1727249484974], type: 'svg'));
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function refusals(): iterable
    {
        yield 'nothing to draw' => ['', []];
        yield 'another type' => [self::QR, ['type' => 'gif']];
        yield 'another form' => [self::QR, ['as' => 'file']];
        yield 'no size' => [self::QR, ['size' => 0]];
        yield 'a margin under zero' => [self::QR, ['margin' => -1]];
        yield 'another level' => [self::QR, ['errorCorrection' => 'X']];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('refusals')]
    public function testRefusesWhatCannotBeDrawn(string $text, array $options): void
    {
        $this->expectException(KhqrImageException::class);
        Khqr::image($text, ...($options + ['type' => 'svg']));
    }
}
