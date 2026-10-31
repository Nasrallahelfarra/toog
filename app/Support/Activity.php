<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the audit trail (FR-6.7): uploads, decisions, overrides, settings
 * changes, and every admin action on users and stores.
 */
class Activity
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function log(string $action, ?Model $subject = null, array $properties = [], ?int $storeId = null): ActivityLog
    {
        $request = request();
        $user = $request->user();
        $current = app(CurrentStore::class);

        return ActivityLog::query()->create([
            'user_id' => $user?->id,
            'store_id' => $storeId ?? $current->id() ?? ($subject?->getAttribute('store_id')),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'acting_as_admin' => $user instanceof User && $user->isAdmin(),
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
