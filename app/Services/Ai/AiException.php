<?php

namespace App\Services\Ai;

use RuntimeException;

class AiException extends RuntimeException
{
    public static function budgetExceeded(): self
    {
        return new self(__('mojo.ai_budget_exceeded'));
    }
}
