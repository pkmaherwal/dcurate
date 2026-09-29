<?php

namespace App\Sources\Support;

use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Finds the RSS/Atom feed for a website URL.
 */
class FeedDiscovery
{
    protected const COMMON_PATHS = ['/feed', '/rss', '/feed.xml', '/rss.xml', '/atom.xml', '/index.xml', '/feeds/posts/default'];

    public function __construct(protected HttpClient $http, protected UrlCanonicalizer $urls)
    {
        //
    }

    /**
     * @return array{feed_url: string, site_title: string|null}|null
     */
    public function discover(string $url): ?array
    {
        $response = $this->http->get($url);

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        if (FeedParser::looksLikeFeed($body)) {
            return ['feed_url' => $url, 'site_title' => null];
        }

        $crawler = new Crawler($body, $url);
        $title = $this->title($crawler);

        $links = $crawler->filter('link[rel~="alternate"][type*="rss"], link[rel~="alternate"][type*="atom"]');

        if ($links->count() > 0 && ($href = $links->first()->attr('href'))) {
            return ['feed_url' => $this->urls->absolutize($href, $url), 'site_title' => $title];
        }

        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        foreach (self::COMMON_PATHS as $path) {
            try {
                $candidate = $this->http->request()->retry(0)->timeout(8)->get($origin.$path);
            } catch (Throwable) {
                continue;
            }

            if ($candidate->successful() && FeedParser::looksLikeFeed($candidate->body())) {
                return ['feed_url' => $origin.$path, 'site_title' => $title];
            }
        }

        return null;
    }

    protected function title(Crawler $crawler): ?string
    {
        $og = $crawler->filter('meta[property="og:site_name"]');

        if ($og->count() > 0) {
            return Text::clean($og->attr('content'));
        }

        $title = $crawler->filter('title');

        return $title->count() > 0 ? Text::title($title->text(), 120) : null;
    }
}
