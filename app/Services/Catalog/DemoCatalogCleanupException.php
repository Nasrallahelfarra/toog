<?php

namespace App\Services\Catalog;

use RuntimeException;

class DemoCatalogCleanupException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
