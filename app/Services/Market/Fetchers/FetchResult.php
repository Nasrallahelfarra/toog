<?php

namespace App\Services\Market\Fetchers;

final class FetchResult
{
    public const OK = 'ok';

    public const UNSUPPORTED = 'unsupported';

    public const FAILED = 'failed';

    /**
     * @param  string  $status  ok | unsupported | failed
     * @param  string|null  $reason  machine key for the UI (needs_provider, needs_api_access, http_error, ...)
     */
    public function __construct(
        public string $status,
        public int $added = 0,
        public int $updated = 0,
        public int $priceChanges = 0,
        public ?string $reason = null,
        public ?string $message = null,
    ) {}

    public static function unsupported(string $reason): self
    {
        return new self(self::UNSUPPORTED, reason: $reason);
    }

    public static function failed(string $message, string $reason = 'http_error'): self
    {
        return new self(self::FAILED, reason: $reason, message: $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'added' => $this->added,
            'updated' => $this->updated,
            'price_changes' => $this->priceChanges,
            'reason' => $this->reason,
            'message' => $this->message,
        ];
    }
}
