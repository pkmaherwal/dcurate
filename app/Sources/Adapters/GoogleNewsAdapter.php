<?php

namespace App\Sources\Adapters;

use App\Models\Source;
use App\Sources\Contracts\SourceAdapter;
use App\Sources\FetchResult;
use App\Sources\RawItem;
use App\Sources\SourceException;
use App\Sources\Support\FeedParser;
use App\Sources\Support\HttpClient;
use Illuminate\Support\Str;

/**
 * Google News search results via the (free, unofficial) Google News RSS search feed.
 *
 * Item links are news.google.com redirects; the publisher's name and site come from <source>.
 */
class GoogleNewsAdapter implements SourceAdapter
{
    public function __construct(protected HttpClient $http, protected FeedParser $parser)
    {
        //
    }

    public function prepare(Source $source): void
    {
        $source->target = trim($source->target);

        if (mb_strlen($source->target) < 3) {
            throw new SourceException(__('Enter a search query of at least 3 characters.'));
        }

        if (blank($source->name)) {
            $source->name = 'Google News: '.Str::limit($source->target, 60);
        }
    }

    public function fetch(Source $source, bool $conditional = true): FetchResult
    {
        $url = $this->feedUrl($source);
        $response = $this->http->get($url);

        if (! $response->successful()) {
            throw new SourceException("Google News returned HTTP {$response->status()}.");
        }

        $items = array_map(
            fn (RawItem $item) => $this->withoutPublisherSuffix($item),
            $this->parser->parse($response->body(), $url)['items'],
        );

        return new FetchResult(items: $items);
    }

    public function feedUrl(Source $source): string
    {
        $campaign = $source->campaign;
        $region = Str::upper($source->option('region', $campaign->region ?? 'IN'));
        $language = $source->option('language', $campaign->language ?? 'en-IN');
        $when = $source->option('when', '7d');

        return 'https://news.google.com/rss/search?'.http_build_query([
            'q' => trim($source->target.($when ? " when:{$when}" : '')),
            'hl' => $language,
            'gl' => $region,
            'ceid' => $region.':'.Str::before($language, '-'),
        ]);
    }

    /**
     * Google News titles end with " - Publisher"; drop it since the publisher is stored separately.
     */
    protected function withoutPublisherSuffix(RawItem $item): RawItem
    {
        $publisher = $item->media['publisher']['name'] ?? null;

        if (! $publisher || ! Str::endsWith($item->title, ' - '.$publisher)) {
            return $item;
        }

        return new RawItem(
            url: $item->url,
            title: Str::beforeLast($item->title, ' - '.$publisher),
            externalId: $item->externalId,
            excerpt: null,
            content: null,
            publishedAt: $item->publishedAt,
            author: $publisher,
            media: $item->media,
            meta: $item->meta,
        );
    }
}
