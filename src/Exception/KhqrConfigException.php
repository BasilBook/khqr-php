<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Exception;

/**
 * The Bakong client has no way to send anything: no PSR-18 HTTP client was given and Guzzle is not
 * installed (PHP only; the TypeScript uses `fetch`). Nothing was sent.
 */
final class KhqrConfigException extends KhqrException
{
}
