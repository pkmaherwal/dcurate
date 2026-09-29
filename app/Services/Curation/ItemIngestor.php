<?php

namespace App\Services\Curation;

use App\Enums\AiStatus;
use App\Enums\ItemStatus;
use App\Models\Item;
use App\Models\Source;
use App\Services\Dedupe\StoryClusterer;
use App\Sources\RawItem;
use App\Sources\Support\UrlCanonicalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Stores new raw items for a source: canonicalizes URLs, skips duplicates and stale items, clusters stories.
 */
class ItemIngestor
{
    public function __construct(protected UrlCanonicalizer $urls, protected StoryClusterer $clusterer)
    {
        //
    }

    /**
     * @param  list<RawItem>  $rawItems
     * @return Collection<int, Item> The newly created items.
     */
    public function ingest(Source $source, array $rawItems): Collection
    {
        $campaign = $source->campaign;
        $cutoff = now()->subDays($campaign->freshness_days);
        $limit = (int) config('curation.fetch.max_items_per_fetch', 50);

        $candidates = collect($rawItems)
            ->take($limit)
            ->map(function (RawItem $raw) {
                $canonical = $this->urls->canonicalize($raw->url);

                return ['raw' => $raw, 'canonical' => $canonical, 'hash' => hash('sha256', $canonical)];
            })
            ->unique('hash');

        $existing = Item::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('url_hash', $candidates->pluck('hash'))
            ->pluck('url_hash')
            ->flip();

        $created = new Collection;

        foreach ($candidates as $candidate) {
            /** @var RawItem $raw */
            $raw = $candidate['raw'];

            if ($existing->has($candidate['hash']) || ($raw->publishedAt !== null && $raw->publishedAt->lt($cutoff))) {
                continue;
            }

            try {
                $item = Item::create([
                    'campaign_id' => $campaign->id,
                    'source_id' => $source->id,
                    'external_id' => $raw->externalId ? mb_substr($raw->externalId, 0, 255) : null,
                    'url' => $raw->url,
                    'canonical_url' => $candidate['canonical'],
                    'url_hash' => $candidate['hash'],
                    'title' => mb_substr($raw->title, 0, 500),
                    'excerpt' => $raw->excerpt,
                    'content_text' => $raw->content,
                    'media' => $raw->media ?: null,
                    'author' => $raw->author ? mb_substr($raw->author, 0, 255) : null,
                    'published_at' => $raw->publishedAt,
                    'fetched_at' => now(),
                    'ai_status' => AiStatus::Pending,
                    'status' => ItemStatus::Pending,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue; // Stored by a concurrent fetch.
            }

            $this->clusterer->assign($item);

            if ($this->joinsReviewedStory($item)) {
                continue;
            }

            $created->push($item);
        }

        return $created;
    }

    /**
     * A new report of a story that was already approved or rejected needs no review of its own.
     */
    protected function joinsReviewedStory(Item $item): bool
    {
        if ($item->isClusterPrimary()) {
            return false;
        }

        $primary = Item::find($item->cluster_id);

        if ($primary === null || $primary->status === ItemStatus::Pending) {
            return false;
        }

        $item->forceFill([
            'status' => ItemStatus::Rejected,
            'reviewed_at' => now(),
            'reject_reason' => $primary->duplicateReason(),
            'ai_status' => AiStatus::Skipped,
        ])->save();

        return true;
    }
}
