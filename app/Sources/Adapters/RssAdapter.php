<?php

namespace App\Sources\Adapters;

use App\Models\Source;
use App\Sources\Contracts\SourceAdapter;
use App\Sources\FetchResult;
use App\Sources\SourceException;
use App\Sources\Support\FeedDiscovery;
use App\Sources\Support\FeedParser;
use App\Sources\Support\HttpClient;

/**
 * RSS/Atom feeds: blogs, news sites, federation sites, RSSHub routes and RSS.app feeds.
 */
class RssAdapter implements SourceAdapter
{
    public function __construct(
        protected HttpClient $http,
        protected FeedParser $parser,
        protected FeedDiscovery $discovery,
    ) {
        //
    }

    public function prepare(Source $source): void
    {
        $target = $this->expandRssHubRoute(trim($source->target));

        if (! filter_var($target, FILTER_VALIDATE_URL)) {
            throw new SourceException(__('Enter a valid URL (or an RSSHub route starting with "rsshub:/").'));
        }

        $found = $this->discovery->discover($target);

        if ($found === null) {
            throw new SourceException(__('No RSS/Atom feed was found at this URL. Try the "Website (no RSS)" type instead.'));
        }

        $source->mergeConfig(['feed_url' => $found['feed_url']]);

        if (blank($source->name)) {
            $source->name = $found['site_title'] ?? $this->feedTitle($found['feed_url']) ?? (string) parse_url($target, PHP_URL_HOST);
        }
    }

    public function fetch(Source $source, bool $conditional = true): FetchResult
    {
        $url = $source->option('feed_url') ?? $this->expandRssHubRoute($source->target);
        $response = $this->http->get($url, $source, $conditional);

        if ($response->status() === 304) {
            return FetchResult::notModified();
        }

        if (! $response->successful()) {
            throw new SourceException("The feed returned HTTP {$response->status()}.");
        }

        return new FetchResult(
            items: $this->parser->parse($response->body(), $url)['items'],
            sourceUpdates: $this->http->validators($response),
        );
    }

    /**
     * "rsshub:/instagram/user/x" → "{RSSHUB_URL}/instagram/user/x".
     */
    protected function expandRssHubRoute(string $target): string
    {
        if (str_starts_with($target, 'rsshub:')) {
            $base = rtrim((string) config('curation.rsshub.url'), '/');

            if ($base === '') {
                throw new SourceException(__('Set RSSHUB_URL to use RSSHub routes.'));
            }

            return $base.'/'.ltrim(substr($target, strlen('rsshub:')), '/');
        }

        return $target;
    }

    protected function feedTitle(string $feedUrl): ?string
    {
        $response = $this->http->get($feedUrl);

        return $response->successful() ? $this->parser->parse($response->body(), $feedUrl, 1)['title'] : null;
    }
}
