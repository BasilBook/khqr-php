<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Internal;

use BasilBook\Khqr\Exception\KhqrImageException;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * What draws `Khqr::image()`: a KHQR text as PNG or SVG, black on white with its quiet zone,
 * `size` pixels (or user units) wide and high, with chillerlan/php-qrcode. Only this class loads
 * the QR library.
 *
 * @internal
 */
final class Image
{
    private const LEVELS = ['L' => EccLevel::L, 'M' => EccLevel::M, 'Q' => EccLevel::Q, 'H' => EccLevel::H];

    private function __construct()
    {
    }

    public static function draw(string $text, string $type, string $as, int $size, int $margin, string $errorCorrection): string
    {
        if ($type !== 'png' && $type !== 'svg') {
            throw new KhqrImageException("A KHQR image is a 'png' or an 'svg', not '{$type}'");
        }
        if ($as !== 'dataUrl' && $as !== 'bytes') {
            throw new KhqrImageException("A KHQR image is given as 'dataUrl' or 'bytes', not '{$as}'");
        }
        if ($size < 1 || $margin < 0) {
            throw new KhqrImageException('A KHQR image needs a size of 1 or more and a margin of 0 or more');
        }
        $level = self::LEVELS[$errorCorrection] ?? null;
        if ($level === null) {
            throw new KhqrImageException("A KHQR image's error correction is L, M, Q or H, not '{$errorCorrection}'");
        }
        if ($text === '') {
            throw new KhqrImageException('There is no KHQR text to draw');
        }
        if ($type === 'png' && !extension_loaded('gd')) {
            throw new KhqrImageException('A PNG KHQR needs the PHP GD extension (ext-gd); draw an SVG instead, or install GD');
        }

        try {
            $options = new QROptions([
                'outputType' => QROutputInterface::CUSTOM,
                'outputInterface' => $type === 'png' ? QRGdImagePNG::class : QRMarkupSVG::class,
                'outputBase64' => false,
                'returnResource' => $type === 'png',
                'eccLevel' => $level,
                'scale' => 1,
                'addQuietzone' => $margin > 0,
                'quietzoneSize' => $margin,
                'imageTransparent' => false,
                'drawLightModules' => $type === 'png',
                'svgAddXmlHeader' => true,
                'connectPaths' => true,
            ]);
            $drawn = (new QRCode($options))->render($text);
        } catch (\Throwable $e) {
            throw new KhqrImageException('The KHQR could not be drawn: ' . $e->getMessage(), 0, $e);
        }

        if ($type === 'svg') {
            $bytes = self::svgOnWhite((string) $drawn, $size);
            return $as === 'bytes' ? $bytes : 'data:image/svg+xml;base64,' . base64_encode($bytes);
        }
        $bytes = self::png($drawn, $size);
        return $as === 'bytes' ? $bytes : 'data:image/png;base64,' . base64_encode($bytes);
    }

    /** The one-pixel-a-module drawing, scaled to `size` with no smoothing (squares stay sharp). */
    private static function png(mixed $image, int $size): string
    {
        if (!$image instanceof \GdImage) {
            throw new KhqrImageException('The KHQR could not be drawn as a PNG');
        }
        $scaled = imagescale($image, $size, $size, IMG_NEAREST_NEIGHBOUR);
        if ($scaled === false) {
            throw new KhqrImageException('The KHQR could not be drawn at that size');
        }
        ob_start();
        imagepng($scaled);
        return (string) ob_get_clean();
    }

    /**
     * The SVG's light squares are left out; a white background keeps it scannable on any page.
     * Its width and height are `size`, its view box the squares, so it has a size without CSS.
     */
    private static function svgOnWhite(string $svg, int $size): string
    {
        $svg = (string) preg_replace('/<svg\b/', sprintf('<svg width="%d" height="%d"', $size, $size), $svg, 1);
        return (string) preg_replace('/(<svg\b[^>]*>)/', '$1<rect width="100%" height="100%" fill="#fff"/>', $svg, 1);
    }
}
