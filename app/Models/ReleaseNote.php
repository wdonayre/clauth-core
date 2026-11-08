<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** "What's new" for one app (or every app when app_id is null), shown on the Clauth bar's bell. */
class ReleaseNote extends Model
{
    public const WINDOW_DAYS = 180;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'app_id')->withTrashed();
    }

    /** Notes the bar of $app shows: its own + every-app notes, published, recent. */
    public function scopeForApp(Builder $q, Application $app): Builder
    {
        return $q->where(fn ($w) => $w->where('app_id', $app->id)->orWhereNull('app_id'))
            ->where('published_at', '<=', now())
            ->where('published_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->latest('published_at');
    }

    public static function readIds(User $user, array $noteIds): array
    {
        return DB::table('release_note_reads')->where('user_id', $user->id)->whereIn('release_note_id', $noteIds)
            ->pluck('release_note_id')->map(fn ($v) => (int) $v)->all();
    }

    public static function markRead(User $user, array $noteIds): void
    {
        $rows = collect($noteIds)->unique()->map(fn ($id) => ['user_id' => $user->id, 'release_note_id' => (int) $id, 'read_at' => now()])->all();
        if ($rows) {
            DB::table('release_note_reads')->insertOrIgnore($rows);
        }
    }
}
