<?php

namespace App\Models;

use App\Enums\AiStatus;
use App\Enums\ItemStatus;
use App\Support\CampaignFeedCache;
use App\Tenancy\BelongsToWorkspace;
use Carbon\CarbonInterface;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A single piece of content found by a source, waiting for (or past) review.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $campaign_id
 * @property int|null $source_id
 * @property string|null $external_id
 * @property string $url
 * @property string $canonical_url
 * @property string $url_hash
 * @property string $title
 * @property string|null $excerpt
 * @property string|null $content_text
 * @property array<string, mixed>|null $media
 * @property string|null $author
 * @property Carbon|null $published_at
 * @property Carbon $fetched_at
 * @property AiStatus $ai_status
 * @property string|null $ai_title
 * @property string|null $ai_summary
 * @property string|null $ai_category
 * @property array<string, mixed>|null $ai_fields
 * @property int|null $ai_relevance
 * @property string|null $ai_reason
 * @property string|null $ai_model
 * @property Carbon|null $enriched_at
 * @property int|null $cluster_id
 * @property ItemStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $reject_reason
 * @property string|null $edited_title
 * @property string|null $edited_summary
 * @property bool $is_featured
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Campaign $campaign
 * @property-read Source|null $source
 */
#[Fillable([
    'campaign_id', 'source_id', 'external_id', 'url', 'canonical_url', 'url_hash', 'title', 'excerpt',
    'content_text', 'media', 'author', 'published_at', 'fetched_at', 'ai_status', 'ai_title', 'ai_summary',
    'ai_category', 'ai_fields', 'ai_relevance', 'ai_reason', 'ai_model', 'enriched_at', 'cluster_id',
    'status', 'edited_title', 'edited_summary', 'is_featured',
])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use BelongsToWorkspace, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media' => 'array',
            'published_at' => 'datetime',
            'fetched_at' => 'datetime',
            'ai_status' => AiStatus::class,
            'ai_fields' => 'array',
            'ai_relevance' => 'integer',
            'enriched_at' => 'datetime',
            'status' => ItemStatus::class,
            'reviewed_at' => 'datetime',
            'is_featured' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Anything that changes what the public API serves invalidates the campaign's cached responses.
        static::saved(function (Item $item): void {
            $published = $item->status === ItemStatus::Approved || $item->getOriginal('status') === ItemStatus::Approved;

            if ($published && $item->wasChanged(['status', 'edited_title', 'edited_summary', 'is_featured', 'ai_title', 'ai_summary', 'ai_category', 'ai_fields'])) {
                CampaignFeedCache::bump($item->campaign_id);
            }
        });

        static::deleted(fn (Item $item) => CampaignFeedCache::bump($item->campaign_id));
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Other items in the same story cluster.
     *
     * @return HasMany<Item, $this>
     */
    public function clusterSiblings(): HasMany
    {
        return $this->hasMany(Item::class, 'cluster_id', 'cluster_id');
    }

    /**
     * @param  Builder<Item>  $query
     */
    public function scopeWithStatus(Builder $query, ItemStatus $status): void
    {
        $query->where('status', $status->value);
    }

    /**
     * The title to show and publish: reviewer edit, then AI title, then original.
     */
    public function displayTitle(): string
    {
        return $this->edited_title ?: ($this->ai_title ?: $this->title);
    }

    /**
     * The summary to show and publish: reviewer edit, then AI summary, then excerpt.
     */
    public function displaySummary(): ?string
    {
        return $this->edited_summary ?: ($this->ai_summary ?: $this->excerpt);
    }

    public function imageUrl(): ?string
    {
        return data_get($this->media, 'image');
    }

    /**
     * The date used for ordering: published date, falling back to when we found it.
     */
    public function effectiveDate(): CarbonInterface
    {
        return $this->published_at ?? $this->fetched_at;
    }

    /**
     * Whether this item leads its story cluster (or has none).
     */
    public function isClusterPrimary(): bool
    {
        return $this->cluster_id === null || $this->cluster_id === $this->id;
    }

    public function approve(User $reviewer, ?string $title = null, ?string $summary = null): void
    {
        $this->forceFill([
            'status' => ItemStatus::Approved,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'reject_reason' => null,
            'edited_title' => filled($title) ? $title : $this->edited_title,
            'edited_summary' => filled($summary) ? $summary : $this->edited_summary,
        ])->save();

        $this->settleDuplicates($reviewer);
    }

    public function reject(User $reviewer, ?string $reason = null): void
    {
        $this->forceFill([
            'status' => ItemStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'reject_reason' => $reason,
        ])->save();

        $this->settleDuplicates($reviewer);
    }

    /**
     * Put the item back in the review queue (and its duplicates with it).
     */
    public function resetReview(): void
    {
        $this->forceFill([
            'status' => ItemStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'reject_reason' => null,
        ])->save();

        if ($this->cluster_id !== null) {
            static::query()
                ->where('cluster_id', $this->cluster_id)
                ->whereKeyNot($this->id)
                ->where('reject_reason', $this->duplicateReason())
                ->update(['status' => ItemStatus::Pending->value, 'reviewed_by' => null, 'reviewed_at' => null, 'reject_reason' => null]);
        }
    }

    /**
     * Reviewing the lead item of a story settles the other pending reports of the same story.
     */
    protected function settleDuplicates(?User $reviewer): void
    {
        if ($this->cluster_id === null) {
            return;
        }

        static::query()
            ->where('cluster_id', $this->cluster_id)
            ->whereKeyNot($this->id)
            ->where('status', ItemStatus::Pending->value)
            ->update([
                'status' => ItemStatus::Rejected->value,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
                'reject_reason' => $this->duplicateReason(),
            ]);
    }

    public function duplicateReason(): string
    {
        return 'Duplicate of #'.$this->id;
    }
}
