<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\ItemStatus;
use App\Tenancy\BelongsToWorkspace;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A niche being curated, e.g. "Football tournaments in India".
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $ai_brief
 * @property string|null $template
 * @property list<string>|null $categories
 * @property list<array{key: string, label: string, type: string, description?: string}>|null $extraction_fields
 * @property list<string>|null $keywords
 * @property string $region
 * @property string $language
 * @property int $freshness_days
 * @property CampaignStatus $status
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name', 'slug', 'description', 'ai_brief', 'template', 'categories', 'extraction_fields',
    'keywords', 'region', 'language', 'freshness_days', 'status', 'created_by',
])]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use BelongsToWorkspace, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'extraction_fields' => 'array',
            'keywords' => 'array',
            'freshness_days' => 'integer',
            'status' => CampaignStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign): void {
            $campaign->slug ??= static::uniqueSlug($campaign->name, $campaign->workspace_id);
        });
    }

    /**
     * URLs use the slug; lookups are scoped to the current workspace, where slugs are unique.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Generate a slug that is unique within the workspace.
     */
    public static function uniqueSlug(string $name, ?int $workspaceId): string
    {
        $base = Str::slug($name) ?: 'campaign';
        $slug = $base;
        $i = 2;

        while (static::acrossWorkspaces()->where('workspace_id', $workspaceId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    /**
     * Build the attributes for a new campaign from a template in config/campaign_templates.php.
     *
     * @return array<string, mixed>
     */
    public static function templateAttributes(string $template): array
    {
        $config = config("campaign_templates.{$template}", config('campaign_templates.custom'));

        return [
            'template' => $template,
            'ai_brief' => $config['ai_brief'] ?? null,
            'categories' => $config['categories'] ?? [],
            'extraction_fields' => $config['extraction_fields'] ?? [],
        ];
    }

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === CampaignStatus::Active;
    }

    /**
     * Count items per review status, e.g. ['pending' => 12, 'approved' => 30].
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = $this->items()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(ItemStatus::cases())
            ->mapWithKeys(fn (ItemStatus $status) => [$status->value => (int) ($counts[$status->value] ?? 0)])
            ->all();
    }
}
