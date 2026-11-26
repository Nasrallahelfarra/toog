<?php

use App\Models\DesignRequestImage;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

/*
| Sketches and fabric photos uploaded for a look but never sent with a
| request are removed after a day, files included.
*/

Schedule::call(function (): void {
    DesignRequestImage::query()
        ->whereNull('design_request_id')
        ->where('created_at', '<', now()->subHours(DesignRequestImage::STAGED_HOURS))
        ->each(function (DesignRequestImage $image): void {
            Storage::disk('public')->delete($image->path);
            $image->delete();
        });
})->daily()->name('studio-prune-uploads')->withoutOverlapping();
