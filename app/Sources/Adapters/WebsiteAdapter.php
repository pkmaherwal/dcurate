<?php

namespace App\Sources\Adapters;

use App\Ai\Agents\ListingExtractor;
use App\Ai\AiRouting;
use App\Ai\StructuredOutput;
use App\Models\Source;
use App\Services\Ai\AiBudget;
use App\Services\Extractor\ExtractorClient;
use App\Services\Extractor\ExtractorUnavailable;
use App\Sources\Contracts\SourceAdapter;
use App\Sources\FetchResult;
use App\Sources\RawItem;
use App\Sources\SourceException;
use App\Sources\Support\HttpClient;
use App\Sources\Support\Text;
use App\Sources\Support\UrlCanonicalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Official pages without RSS (federations, clubs, government notices).
 *
 * Three modes, cheapest first:
 *  1. Sitemap: the target is a sitemap .xml (news sitemaps give titles and dates).
 *  2. CSS selectors configured on the source.
 *  3. AI listing mode: the page is reduced to markdown + links and the AI picks the real entries.
 *     Only runs when the set of links on the page changed.
 */
class WebsiteAdapter implements SourceAdapter
{
    public function __construct(
        protected HttpClient $http,
        protected UrlCanonicalizer $urls,
        protected ExtractorClient $extractor,
        protected AiBudget $budget,
    ) {
        //
    }

    public function prepare(Source $source): void
    {
        $source->target = trim($source->target);

        if (! filter_var($source->target, FILTER_VALIDATE_URL)) {
            throw new SourceException(__('Enter the full URL of the news or announcements page.'));
        }

        if ($this->isSitemap($source)) {
            $this->sitemapEntries($source->target);
            $source->name = $source->name ?: 'Sitemap: '.parse_url($source->target, PHP_URL_HOST);

            return;
        }

        $page = $this->load($source, $this->hasSelectors($source));

        if ($page['links'] === [] && ! $this->hasSelectors($source)) {
            throw new SourceException(__('No article links were found on this page. If it needs JavaScript, turn on "Page needs JavaScript".'));
        }

        $source->name = $source->name ?: ($page['title'] ? Str::limit($page['title'], 120) : (string) parse_url($source->target, PHP_URL_HOST));
    }

    public function fetch(Source $source, bool $conditional = true): FetchResult
    {
        if ($this->isSitemap($source)) {
            return new FetchResult(items: $this->fromSitemap($source));
        }

        $page = $this->load($source, $this->hasSelectors($source));

        if ($this->hasSelectors($source)) {
            return new FetchResult(items: $this->fromSelectors($source, (string) $page['html'], $page['url']));
        }

        if ($conditional && $page['content_hash'] !== null && $page['content_hash'] === $source->content_hash) {
            return FetchResult::notModified();
        }

        $workspace = $source->workspace;

        if (! AiRouting::configured($workspace)) {
            throw new SourceException(__('AI is not configured (set OPENAI_API_KEY), so this page cannot be read. Add CSS selectors or configure AI.'));
        }

        if (! $this->budget->allows($workspace)) {
            // Leave the stored hash alone so the page is read again once the budget resets.
            return FetchResult::notModified();
        }

        return new FetchResult(
            items: $this->fromAi($source, $page),
            sourceUpdates: ['content_hash' => $page['content_hash']],
        );
    }

    /**
     * @param  array{url: string, title: string|null, markdown: string|null, links: list<array{url: string, text: string}>}  $page
     * @return list<RawItem>
     */
    protected function fromAi(Source $source, array $page): array
    {
        $links = collect($page['links'])->keyBy(fn (array $link) => $this->urls->canonicalize($link['url']));

        $prompt = "Page: {$page['url']}\nTitle: ".($page['title'] ?? '')."\n\nLinks on the page (URL | text):\n"
            .$links->map(fn (array $link) => "{$link['url']} | {$link['text']}")->take(150)->implode("\n")
            ."\n\nPage content (markdown):\n".Str::limit((string) $page['markdown'], 12000);

        $response = (new ListingExtractor($source->workspace))->prompt($prompt);

        $this->budget->record($source->workspace, (string) $response->meta->provider, (string) $response->meta->model, 'listing', $response->usage);

        $items = [];

        foreach ((array) data_get(StructuredOutput::from($response), 'items', []) as $entry) {
            $url = is_string($entry['url'] ?? null) ? $this->urls->absolutize($entry['url'], $page['url']) : null;

            // Only accept links that really are on the page.
            if ($url === null || ! $links->has($this->urls->canonicalize($url))) {
                continue;
            }

            $items[] = new RawItem(
                url: $url,
                title: Text::title($entry['title'] ?? $links[$this->urls->canonicalize($url)]['text']),
                publishedAt: $this->date($entry['published_at'] ?? null),
            );
        }

        return $items;
    }

    /**
     * @return list<RawItem>
     */
    protected function fromSelectors(Source $source, string $html, string $baseUrl): array
    {
        $selectors = (array) $source->option('selectors', []);
        $items = [];

        (new Crawler($html, $baseUrl))->filter((string) $selectors['item'])->each(function (Crawler $node) use ($selectors, $baseUrl, &$items): void {
            $titleNode = filled($selectors['title'] ?? null) ? $node->filter($selectors['title']) : $node;
            $linkNode = filled($selectors['link'] ?? null) ? $node->filter($selectors['link']) : $node->filter('a');
            $href = $linkNode->count() > 0 ? $linkNode->first()->attr('href') : ($node->nodeName() === 'a' ? $node->attr('href') : null);
            $title = $titleNode->count() > 0 ? Text::title($titleNode->first()->text('')) : '';

            if (! $href || $title === '') {
                return;
            }

            $date = null;

            if (filled($selectors['date'] ?? null) && ($dateNode = $node->filter($selectors['date']))->count() > 0) {
                $date = $this->date($dateNode->first()->attr('datetime') ?? $dateNode->first()->text(''));
            }

            $items[] = new RawItem(url: $this->urls->absolutize($href, $baseUrl), title: $title, publishedAt: $date);
        });

        return $items;
    }

    /**
     * @return list<RawItem>
     */
    protected function fromSitemap(Source $source): array
    {
        $cutoff = now()->subDays($source->campaign->freshness_days);

        return array_values(collect($this->sitemapEntries($source->target))
            ->filter(fn (array $entry) => $entry['date'] === null || $entry['date']->gte($cutoff))
            ->sortByDesc(fn (array $entry) => $entry['date'] ? $entry['date']->timestamp : 0)
            ->take((int) config('curation.fetch.max_items_per_fetch', 50))
            ->map(fn (array $entry) => new RawItem(url: $entry['url'], title: $entry['title'], publishedAt: $entry['date']))
            ->all());
    }

    /**
     * @return list<array{url: string, title: string, date: Carbon|null}>
     */
    protected function sitemapEntries(string $url, int $depth = 0): array
    {
        $response = $this->http->get($url);

        if (! $response->successful()) {
            throw new SourceException("The sitemap returned HTTP {$response->status()}.");
        }

        try {
            $xml = new SimpleXMLElement($response->body(), LIBXML_NONET);
        } catch (Throwable) {
            throw new SourceException(__('This URL is not a valid XML sitemap.'));
        }

        $namespaces = $xml->getDocNamespaces(true);
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        // A sitemap index: follow the most recently modified child sitemap.
        if ($xml->getName() === 'sitemapindex') {
            $children = collect($xml->xpath('//s:sitemap') ?: [])
                ->map(fn (SimpleXMLElement $node) => ['loc' => (string) $node->children($namespaces[''] ?? null)->loc, 'lastmod' => (string) $node->children($namespaces[''] ?? null)->lastmod])
                ->sortByDesc('lastmod');

            if ($depth > 1 || $children->isEmpty()) {
                return [];
            }

            return $this->sitemapEntries($children->first()['loc'], $depth + 1);
        }

        $entries = [];

        foreach ($xml->xpath('//s:url') ?: [] as $node) {
            $fields = $node->children($namespaces[''] ?? null);
            $news = isset($namespaces['news']) ? $node->children($namespaces['news'])->news : null;
            $loc = trim((string) $fields->loc);

            if ($loc === '') {
                continue;
            }

            $title = $news !== null && trim((string) $news->title) !== ''
                ? Text::title((string) $news->title)
                : Str::headline((string) Str::of((string) parse_url($loc, PHP_URL_PATH))->rtrim('/')->afterLast('/')->beforeLast('.'));

            $entries[] = [
                'url' => $loc,
                'title' => $title,
                'date' => $this->date($news !== null ? (string) $news->publication_date : null) ?? $this->date((string) $fields->lastmod),
            ];
        }

        return $entries;
    }

    /**
     * Load the page as markdown + candidate links (and HTML when selectors need it).
     *
     * @return array{url: string, title: string|null, markdown: string|null, links: list<array{url: string, text: string}>, content_hash: string|null, html: string|null}
     */
    protected function load(Source $source, bool $withHtml): array
    {
        $renderJs = (bool) $source->option('render_js', false);

        if ($this->extractor->enabled()) {
            try {
                $page = $this->extractor->extract($source->target, 'listing', $renderJs, $withHtml);

                return array_intersect_key($page, array_flip(['url', 'title', 'markdown', 'links', 'content_hash', 'html']));
            } catch (ExtractorUnavailable $e) {
                // Sidecar down: plain HTTP still works for pages that don't need JavaScript.
                if ($renderJs) {
                    throw $e;
                }
            }
        }

        if ($renderJs) {
            throw new SourceException(__('This page needs JavaScript rendering, which requires the extractor service (EXTRACTOR_URL).'));
        }

        return $this->loadWithoutExtractor($source->target);
    }

    /**
     * Plain-HTTP fallback when the Python sidecar is not running.
     *
     * @return array{url: string, title: string|null, markdown: string|null, links: list<array{url: string, text: string}>, content_hash: string|null, html: string|null}
     */
    protected function loadWithoutExtractor(string $url): array
    {
        $response = $this->http->get($url);

        if (! $response->successful()) {
            throw new SourceException("The page returned HTTP {$response->status()}.");
        }

        $html = $response->body();
        $crawler = new Crawler($html, $url);
        $host = Str::of((string) parse_url($url, PHP_URL_HOST))->lower()->chopStart('www.')->toString();
        $links = [];

        $crawler->filter('a[href]')->each(function (Crawler $anchor) use ($url, $host, &$links): void {
            $href = $this->urls->absolutize((string) $anchor->attr('href'), $url);
            $text = Str::squish($anchor->text(''));
            $linkHost = Str::of((string) parse_url($href, PHP_URL_HOST))->lower()->chopStart('www.')->toString();

            if (mb_strlen($text) >= 15 && $linkHost === $host && ! isset($links[$href]) && rtrim($href, '/') !== rtrim($url, '/')) {
                $links[strtok($href, '#') ?: $href] = ['url' => strtok($href, '#') ?: $href, 'text' => Str::limit($text, 300)];
            }
        });

        $links = array_values(array_slice($links, 0, 200));
        $title = $crawler->filter('title')->count() > 0 ? Text::title($crawler->filter('title')->text('')) : null;
        $body = $crawler->filter('body')->count() > 0 ? Text::clean($crawler->filter('body')->html('')) : null;

        return [
            'url' => $url,
            'title' => $title,
            'markdown' => $body ? Str::limit($body, 20000) : null,
            'links' => $links,
            'content_hash' => $links === [] ? null : hash('sha256', implode("\n", array_column($links, 'url'))),
            'html' => $html,
        ];
    }

    protected function isSitemap(Source $source): bool
    {
        return (bool) preg_match('/\.xml(\.gz)?($|\?)/i', $source->target);
    }

    protected function hasSelectors(Source $source): bool
    {
        return filled(data_get($source->config, 'selectors.item'));
    }

    protected function date(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $date = Carbon::parse(trim($value));
        } catch (Throwable) {
            return null;
        }

        return $date->isFuture() && $date->diffInDays(now()) > 1 ? null : $date;
    }
}
