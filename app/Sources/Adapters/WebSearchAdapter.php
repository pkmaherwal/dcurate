<?php

namespace App\Sources\Adapters;

use App\Ai\Agents\WebSearchDiscoverer;
use App\Models\Source;
use App\Services\Ai\AiBudget;
use App\Sources\Contracts\SourceAdapter;
use App\Sources\FetchResult;
use App\Sources\RawItem;
use App\Sources\SourceException;
use App\Sources\Support\Text;
use App\Sources\Support\UrlCanonicalizer;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\UrlCitation;

/**
 * AI web search discovery ("what's new about X?") via OpenAI's web search tool.
 */
class WebSearchAdapter implements SourceAdapter
{
    /**
     * Pages that are never a single news item.
     */
    protected const SKIP_HOSTS = ['facebook.com', 'instagram.com', 'x.com', 'twitter.com', 'youtube.com', 'google.com', 'news.google.com', 'linkedin.com'];

    public function __construct(protected AiBudget $budget, protected UrlCanonicalizer $urls)
    {
        //
    }

    public function prepare(Source $source): void
    {
        $source->target = trim($source->target);

        if (mb_strlen($source->target) < 5) {
            throw new SourceException(__('Describe what to search for, e.g. "Newly announced football tournaments in Kerala".'));
        }

        if (blank(config('ai.providers.openai.key'))) {
            throw new SourceException(__('AI web search needs an OpenAI API key (OPENAI_API_KEY).'));
        }

        $source->name = $source->name ?: 'Web search: '.Str::limit($source->target, 60);
    }

    public function fetch(Source $source, bool $conditional = true): FetchResult
    {
        $workspace = $source->workspace;

        if (blank(config('ai.providers.openai.key'))) {
            throw new SourceException(__('AI web search needs an OpenAI API key (OPENAI_API_KEY).'));
        }

        $limit = $workspace->limit('web_searches_per_day');

        if (! $this->budget->allows($workspace) || ($limit !== null && $this->budget->webSearchesToday($workspace) >= $limit)) {
            return FetchResult::notModified(); // Try again after the daily reset.
        }

        $response = (new WebSearchDiscoverer($source))->prompt("Find the latest: {$source->target}");

        $this->budget->record(
            $workspace,
            'openai',
            (string) ($response->meta->model ?? $source->workspace->setting('ai.web_search_model')),
            'web_search',
            $response->usage,
            $this->searchCalls($response),
        );

        return new FetchResult(items: $this->itemsFromCitations($response));
    }

    /**
     * @return list<RawItem>
     */
    protected function itemsFromCitations(AgentResponse $response): array
    {
        $items = [];

        foreach ($response->meta->citations as $citation) {
            if (! $citation instanceof UrlCitation) {
                continue;
            }

            $url = $this->withoutTrackingSuffix($citation->url);
            $host = Str::of((string) parse_url($url, PHP_URL_HOST))->lower()->chopStart('www.')->toString();
            $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

            if ($host === '' || $path === '' || in_array($host, self::SKIP_HOSTS, true)) {
                continue;
            }

            $key = $this->urls->canonicalize($url);

            $items[$key] ??= new RawItem(
                url: $url,
                title: Text::title($citation->title ?: Str::headline(Str::afterLast($path, '/'))),
                media: ['publisher' => ['name' => $host, 'url' => 'https://'.$host]],
            );
        }

        return array_values($items);
    }

    /**
     * OpenAI appends "?utm_source=openai" to cited URLs.
     */
    protected function withoutTrackingSuffix(string $url): string
    {
        return $this->urls->canonicalize($url);
    }

    protected function searchCalls(AgentResponse $response): int
    {
        $calls = $response->steps->sum(fn (Step $step) => count(array_filter(
            $step->providerToolCalls,
            fn ($call) => str_contains($call->type, 'web_search'),
        )));

        return max(1, (int) $calls);
    }
}
