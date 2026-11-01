<?php

namespace App\Services\Zid;

use RuntimeException;

class ZidRateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Zid rate limit reached (HTTP 429).');
    }
}
