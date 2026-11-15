<?php

declare(strict_types=1);

namespace BasilBook\Khqr\Exception;

/**
 * The input cannot make a KHQR a bank app would take. `problem` says which: `currency` (not riel or
 * US dollars), `account` (not a Bakong account ID), `amount` (not a positive whole number of minor
 * units) or `lifetime` (it expires before it is made). `KhqrInputError` in the TypeScript.
 */
final class KhqrInputException extends KhqrException
{
    /** @param 'currency'|'account'|'amount'|'lifetime' $problem */
    public function __construct(public readonly string $problem, string $message)
    {
        parent::__construct($message);
    }
}
