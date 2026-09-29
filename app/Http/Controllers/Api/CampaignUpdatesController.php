<?php

namespace App\Http\Controllers\Api;

use App\Enums\ItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ItemResource;
use App\Models\Campaign;
use App\Models\Item;
use App\Support\CampaignFeedCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Read-only API for the website: approved items of the token's workspace.
 */
class CampaignUpdatesController extends Controller
{
    /**
     * GET /api/v1/campaigns
     */
    public function campaigns(): JsonResponse
    {
        $campaigns = Campaign::query()
            ->withCount(['items as approved_count' => fn (Builder $query) => $query->where('status', ItemStatus::Approved->value)])
            ->orderBy('name')
            ->get()
            ->map(fn (Campaign $campaign) => [
                'name' => $campaign->name,
                'slug' => $campaign->slug,
                'description' => $campaign->description,
                'categories' => $campaign->categories ?? [],
                'fields' => collect($campaign->extraction_fields ?? [])->map(fn (array $field) => ['key' => $field['key'], 'label' => $field['label'], 'type' => $field['type']])->all(),
                'approved_count' => $campaign->getAttribute('approved_count'),
                'updates_url' => route('api.campaigns.updates', $campaign->slug),
            ]);

        return response()->json(['data' => $campaigns]);
    }

    /**
     * GET /api/v1/campaigns/{slug}/updates?since=&category=&featured=&per_page=&page=
     */
    public function index(Request $request, string $slug): JsonResponse
    {
        $campaign = Campaign::where('slug', $slug)->firstOrFail();
        $filters = $this->filters($request);

        $payload = CampaignFeedCache::remember($campaign->id, 'json', $filters, function () use ($campaign, $filters, $request) {
            $items = $this->approvedItems($campaign, $filters)
                ->paginate($filters['per_page'], page: $filters['page'])
                ->withPath($request->url())
                ->appends(array_filter($request->only(['since', 'category', 'featured', 'per_page'])));

            return ItemResource::collection($items)
                ->additional(['campaign' => ['name' => $campaign->name, 'slug' => $campaign->slug]])
                ->response()
                ->getData(true);
        });

        return response()->json($payload);
    }

    /**
     * GET /api/v1/campaigns/{slug}/feed.rss
     */
    public function rss(Request $request, string $slug): Response
    {
        $campaign = Campaign::where('slug', $slug)->firstOrFail();
        $filters = [...$this->filters($request), 'page' => 1, 'per_page' => 50];

        $xml = CampaignFeedCache::remember($campaign->id, 'rss', $filters, fn () => view('api.feed', [
            'campaign' => $campaign,
            'items' => $this->approvedItems($campaign, $filters)->limit(50)->get(),
        ])->render());

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /**
     * @param  array{since: string|null, category: string|null, featured: bool|null, per_page: int, page: int}  $filters
     * @return Builder<Item>
     */
    protected function approvedItems(Campaign $campaign, array $filters): Builder
    {
        return Item::query()
            ->where('campaign_id', $campaign->id)
            ->with('source')
            ->where('status', ItemStatus::Approved->value)
            ->when($filters['since'], fn (Builder $query, string $since) => $query->where('reviewed_at', '>=', Carbon::parse($since)))
            ->when($filters['category'], fn (Builder $query, string $category) => $query->where('ai_category', $category))
            ->when($filters['featured'] !== null, fn (Builder $query) => $query->where('is_featured', $filters['featured']))
            ->orderByDesc('is_featured')
            ->orderByRaw('coalesce(published_at, fetched_at) desc')
            ->orderByDesc('id');
    }

    /**
     * @return array{since: string|null, category: string|null, featured: bool|null, per_page: int, page: int}
     */
    protected function filters(Request $request): array
    {
        $max = (int) config('curation.api.max_per_page', 100);

        // An unencoded "+05:30" offset arrives as " 05:30"; restore it so plain ISO dates just work.
        if (is_string($since = $request->query('since'))) {
            $request->merge(['since' => str_replace(' ', '+', $since)]);
        }

        $validated = $request->validate([
            'since' => ['nullable', 'date'],
            'category' => ['nullable', 'string', 'max:100'],
            'featured' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', "max:{$max}"],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'since' => $validated['since'] ?? null,
            'category' => $validated['category'] ?? null,
            'featured' => isset($validated['featured']) ? $request->boolean('featured') : null,
            'per_page' => (int) ($validated['per_page'] ?? config('curation.api.per_page', 20)),
            'page' => (int) ($validated['page'] ?? 1),
        ];
    }
}
