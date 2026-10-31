<?php

namespace App\Jobs;

use App\Models\DesignRequest;
use App\Services\Design\DesignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Generates every variant of a design request, then auto-evaluates them.
 */
class GenerateDesigns implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $designRequestId) {}

    public function handle(DesignService $service): void
    {
        $request = DesignRequest::query()->find($this->designRequestId);

        if ($request && in_array($request->status, ['pending', 'processing'], true)) {
            $service->generate($request);
        }
    }

    public function failed(?Throwable $e): void
    {
        DesignRequest::query()->whereKey($this->designRequestId)->update([
            'status' => 'failed',
            'error' => $e?->getMessage(),
        ]);
    }
}
