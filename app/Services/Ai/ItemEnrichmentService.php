<?php

namespace App\Services\Ai;

use App\Ai\Agents\ItemEnricher;
use App\Ai\AiRouting;
use App\Ai\CampaignSchemaBuilder;
use App\Ai\StructuredOutput;
use App\Enums\AiStatus;
use App\Enums\ItemStatus;
use App\Enums\SourceType;
use App\Models\Item;
use App\Models\Workspace;
use App\Services\Extractor\ExtractorClient;
use App\Sources\Support\Text;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Files;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Runs the AI over an item: optional cheap relevance pre-filter, then full enrichment.
 */
class ItemEnrichmentService
{
    /**
     * Items with less text than this get their full article fetched first.
     */
    protected const THIN_TEXT = 400;

    public function __construct(protected AiBudget $budget, protected ExtractorClient $extractor)
    {
        //
    }

    /**
     * @return bool False when the workspace's daily AI budget is used up (the item stays pending).
     */
    public function enrich(Item $item): bool
    {
        $item->loadMissing(['campaign', 'source', 'workspace']);
        $workspace = $item->workspace;

        if (! $this->budget->allows($workspace)) {
            return false;
        }

        if (! AiRouting::configured($workspace)) {
            return true; // Stays pending; the sweeper enriches it once an API key is set.
        }

        $this->fillThinContent($item);

        if ($this->isOutdated($item)) {
            $item->forceFill([
                'ai_status' => AiStatus::Skipped,
                'status' => ItemStatus::Rejected,
                'reviewed_at' => now(),
                'reject_reason' => 'Outdated',
            ])->save();

            return true;
        }

        try {
            $response = (new ItemEnricher($item->campaign, $workspace))->prompt($this->prompt($item), attachments: $this->attachments($item));
            $this->track($workspace, $response, 'enrich');
            $this->apply($item, $response);
        } catch (Throwable $e) {
            Log::warning('AI enrichment failed', ['item_id' => $item->id, 'error' => $e->getMessage()]);

            $item->forceFill([
                'ai_status' => AiStatus::Failed,
                'ai_reason' => Str::limit(class_basename($e).': '.$e->getMessage(), 500),
            ])->save();
        }

        return true;
    }

    /**
     * Run the enricher without saving anything (the "Test AI" button). Usage is still recorded.
     *
     * @return array<string, mixed>
     */
    public function preview(Item $item): array
    {
        $item->loadMissing(['campaign', 'source', 'workspace']);

        $response = (new ItemEnricher($item->campaign, $item->workspace))->prompt($this->prompt($item), attachments: $this->attachments($item));
        $this->track($item->workspace, $response, 'test');

        return [...$this->structured($response), '_model' => $response->meta->model];
    }

    public function prompt(Item $item): string
    {
        $source = $item->source;
        $text = $item->content_text ?: $item->excerpt;

        return implode("\n", array_filter([
            'Source: '.($source ? "{$source->name} ({$source->type->shortLabel()})" : 'unknown'),
            ($publisher = data_get($item->media, 'publisher.name')) ? "Publisher: {$publisher}" : null,
            "URL: {$item->url}",
            'Published: '.($item->published_at?->toDateString() ?? 'unknown'),
            "Title: {$item->title}",
            '',
            'Text:',
            $text ? Str::limit($text, (int) config('curation.ai.max_content_chars', 6000)) : '(no text; judge from the title'.($this->attachments($item) ? ' and the attached image' : '').')',
        ], fn ($line) => $line !== null));
    }

    /**
     * Web search and website items only get a real date once the article is read; drop the old ones for free.
     */
    protected function isOutdated(Item $item): bool
    {
        return $item->published_at !== null
            && $item->published_at->lt(now()->subDays($item->campaign->freshness_days));
    }

    protected function apply(Item $item, AgentResponse $response): void
    {
        $data = $this->structured($response);
        $campaign = $item->campaign;

        $category = in_array($data['category'] ?? null, CampaignSchemaBuilder::categories($campaign), true) ? $data['category'] : null;
        $fieldKeys = array_column($campaign->extraction_fields ?? [], 'key');
        $fields = array_filter(
            array_intersect_key((array) ($data['fields'] ?? []), array_flip($fieldKeys)),
            fn ($value) => filled($value),
        );

        $item->forceFill([
            'ai_status' => AiStatus::Done,
            'ai_title' => Str::limit(Text::title((string) ($data['title'] ?? '')), 480, '') ?: null,
            'ai_summary' => Text::clean((string) ($data['summary'] ?? '')),
            'ai_category' => $category,
            'ai_fields' => $fields ?: null,
            'ai_relevance' => max(0, min(100, (int) ($data['relevance'] ?? 0))),
            'ai_reason' => Str::limit((string) ($data['reason'] ?? ''), 1000),
            'ai_model' => $response->meta->model,
            'enriched_at' => now(),
        ])->save();
    }

    /**
     * Fetch the full article for items that only have a title or a snippet (via the extractor sidecar).
     */
    protected function fillThinContent(Item $item): void
    {
        $text = (string) ($item->content_text ?: $item->excerpt);
        $type = $item->source?->type;

        if (mb_strlen($text) >= self::THIN_TEXT || ! in_array($type, [SourceType::Rss, SourceType::Website, SourceType::WebSearch, SourceType::GoogleNews], true)
            || ! $this->extractor->enabled()) {
            return;
        }

        try {
            $article = $this->extractor->extract($item->url, 'article', renderJs: $type === SourceType::GoogleNews);
        } catch (Throwable $e) {
            Log::info('Could not extract article text', ['item_id' => $item->id, 'error' => $e->getMessage()]);

            return;
        }

        $item->forceFill(array_filter([
            'content_text' => Text::clean($article['text'], 20000),
            'published_at' => $item->published_at ?? (filled($article['published_at']) ? Carbon::parse($article['published_at']) : null),
            'media' => $item->imageUrl() === null && filled($article['image']) ? [...($item->media ?? []), 'image' => $article['image']] : null,
        ], fn ($value) => $value !== null))->save();
    }

    /**
     * Attach the image when the item is mostly visual (Instagram posts, posters) or has little text.
     *
     * @return list<Image|Files\File>
     */
    protected function attachments(Item $item): array
    {
        $image = $item->imageUrl();
        $stored = data_get($item->media, 'image_path');
        $textLength = mb_strlen((string) ($item->content_text ?: $item->excerpt));

        if ($stored === null && $image === null) {
            return [];
        }

        if ($item->source?->type !== SourceType::Instagram && $textLength >= 200) {
            return [];
        }

        return [$stored ? Image::fromStorage($stored, 'public') : Image::fromUrl((string) $image)];
    }

    protected function track(Workspace $workspace, AgentResponse $response, string $purpose): void
    {
        $this->budget->record(
            $workspace,
            (string) ($response->meta->provider ?? 'unknown'),
            (string) ($response->meta->model ?? 'unknown'),
            $purpose,
            $response->usage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function structured(AgentResponse $response): array
    {
        return StructuredOutput::from($response);
    }
}
