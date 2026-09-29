<?php

namespace App\Models;

use App\Enums\FetchRunStatus;
use App\Enums\SourceType;
use App\Tenancy\BelongsToWorkspace;
use App\Tenancy\WorkspaceScope;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A place new items come from: a feed, a page, a channel, an account or a search.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $campaign_id
 * @property SourceType $type
 * @property string|null $driver
 * @property string $name
 * @property string $target
 * @property array<string, mixed>|null $config
 * @property int $fetch_interval_minutes
 * @property Carbon|null $next_fetch_at
 * @property Carbon|null $last_fetched_at
 * @property string|null $last_status
 * @property string|null $last_error
 * @property int $consecutive_errors
 * @property string|null $etag
 * @property string|null $last_modified
 * @property string|null $content_hash
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Campaign $campaign
 */
#[Fillable([
    'campaign_id', 'type', 'driver', 'name', 'target', 'config', 'fetch_interval_minutes',
    'next_fetch_at', 'is_active',
])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use BelongsToWorkspace, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'config' => 'array',
            'fetch_interval_minutes' => 'integer',
            'next_fetch_at' => 'datetime',
            'last_fetched_at' => 'datetime',
            'consecutive_errors' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @return HasMany<FetchRun, $this>
     */
    public function fetchRuns(): HasMany
    {
        return $this->hasMany(FetchRun::class);
    }

    /**
     * Sources that are active, in an active campaign, and due for a fetch.
     *
     * @param  Builder<Source>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('next_fetch_at')->orWhere('next_fetch_at', '<=', now()))
            ->whereHas('campaign', fn (Builder $q) => $q->withoutGlobalScope(WorkspaceScope::class)->where('status', 'active'))
            ->whereHas('workspace', fn (Builder $q) => $q->where('status', 'active'));
    }

    /**
     * Read a value from the source's config column.
     */
    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $key, $default);
    }

    /**
     * Merge values into the source's config column.
     *
     * @param  array<string, mixed>  $values
     */
    public function mergeConfig(array $values): void
    {
        $this->config = array_merge($this->config ?? [], $values);
    }

    /**
     * Record a successful fetch and schedule the next one.
     */
    public function markSucceeded(FetchRunStatus $status = FetchRunStatus::Success): void
    {
        $this->forceFill([
            'last_fetched_at' => now(),
            'last_status' => $status->value,
            'last_error' => null,
            'consecutive_errors' => 0,
            'next_fetch_at' => now()->addMinutes($this->fetch_interval_minutes),
        ])->save();
    }

    /**
     * Record a failed fetch, back off exponentially, and pause the source after too many errors.
     *
     * @return bool Whether the source was paused by this failure.
     */
    public function markFailed(string $error): bool
    {
        $errors = $this->consecutive_errors + 1;
        $backoff = min($this->fetch_interval_minutes * (2 ** ($errors - 1)), 24 * 60);
        $pause = $errors >= (int) config('curation.fetch.max_consecutive_errors', 5);

        $this->forceFill([
            'last_fetched_at' => now(),
            'last_status' => FetchRunStatus::Failed->value,
            'last_error' => Str::limit($error, 2000),
            'consecutive_errors' => $errors,
            'next_fetch_at' => now()->addMinutes($backoff),
            'is_active' => $pause ? false : $this->is_active,
        ])->save();

        return $pause;
    }

    /**
     * A public link to the source itself, for attribution.
     */
    public function publicUrl(): ?string
    {
        return match ($this->type) {
            SourceType::YouTube => $this->option('channel_id') ? 'https://www.youtube.com/channel/'.$this->option('channel_id') : $this->target,
            SourceType::Instagram => 'https://www.instagram.com/'.ltrim($this->target, '@').'/',
            SourceType::Rss, SourceType::Website => ($host = parse_url($this->target, PHP_URL_HOST)) ? 'https://'.$host : null,
            default => null,
        };
    }

    /**
     * The host of the source's URL target, used for per-domain rate limiting.
     */
    public function host(): string
    {
        return parse_url($this->option('feed_url', $this->target), PHP_URL_HOST) ?: $this->type->value;
    }
}
