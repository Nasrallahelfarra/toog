<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A competitor post's numbers as read by one scan.
 *
 * @property int $id
 * @property int $social_post_id
 * @property Carbon $observed_at
 * @property int $likes
 * @property int $comments
 * @property int $shares
 * @property int $views
 * @property int|null $followers
 */
#[Fillable(['social_post_id', 'observed_at', 'likes', 'comments', 'shares', 'views', 'followers'])]
class SocialPostObservation extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['observed_at' => 'datetime'];
    }
}
