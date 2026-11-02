<?php

namespace App\Services\Zid;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use RuntimeException;

class ZidApiException extends RuntimeException
{
    /** Zid's own explanation, e.g. the field a validation error names. */
    public readonly ?string $detail;

    public function __construct(public readonly int $httpStatus, string $path, RequestException $previous)
    {
        $body = $previous->response->json();
        $detail = data_get($body, 'message.description') ?? data_get($body, 'message');
        $this->detail = is_string($detail) && trim($detail) !== '' ? Str::limit(trim($detail), 300) : null;

        parent::__construct("Zid request to {$path} failed (HTTP {$httpStatus})".($this->detail ? ": {$this->detail}" : '.'), $httpStatus, $previous);
    }
}
