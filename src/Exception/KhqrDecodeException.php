<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Exception;

/**
 * The text is not a KHQR a bank app would take. `problem` says why: `format` (its fields do not
 * read as tag, length, value, or one it must carry is missing), `crc` (its checksum is not the one
 * its fields make), `field` (a field holds what it may not: another money, a bad amount or time).
 * `KhqrDecodeError` in the TypeScript.
 */
final class KhqrDecodeException extends KhqrException
{
    /** @param 'format'|'crc'|'field' $problem */
    public function __construct(public readonly string $problem, string $message)
    {
        parent::__construct($message);
    }
}
