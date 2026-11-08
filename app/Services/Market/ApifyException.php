<?php

namespace App\Services\Market;

use RuntimeException;

/** An Apify call failed; reason is the machine key shown to the merchant. */
class ApifyException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }
}
