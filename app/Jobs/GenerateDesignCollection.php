<?php

namespace App\Jobs;

use App\Models\DesignCollection;
use App\Services\Designer\CollectionDesigner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Plans an AI-designer collection and draws its concepts one by one, or
 * redraws a single concept when $only is set.
 */
class GenerateDesignCollection implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $collectionId, public ?int $only = null) {}

    public function handle(CollectionDesigner $designer): void
    {
        $collection = DesignCollection::query()->find($this->collectionId);

        if ($collection && $collection->isActive()) {
            $designer->run($collection, $this->only);
        }
    }

    public function failed(?Throwable $e): void
    {
        DesignCollection::query()->whereKey($this->collectionId)->update([
            'status' => 'failed',
            'error' => $e?->getMessage(),
        ]);
    }
}
