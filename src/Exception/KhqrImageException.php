<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Exception;

/**
 * A KHQR image could not be drawn: an unknown type, form, size or level, no text, no GD for a PNG,
 * or the QR library refused the text. `KhqrImageError` in the TypeScript.
 */
final class KhqrImageException extends KhqrException
{
}
