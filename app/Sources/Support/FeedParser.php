<?php

namespace App\Sources\Support;

use App\Sources\RawItem;
use App\Sources\SourceException;
use Illuminate\Support\Carbon;
use SimplePie\Item as FeedItem;
use SimplePie\SimplePie;

/**
 * Parses RSS/Atom (including YouTube and Google News feeds) into RawItems.
 */
class FeedParser
{
    protected const NS_YOUTUBE = 'http://www.youtube.com/xml/schemas/2015';

    public function __construct(protected UrlCanonicalizer $urls)
    {
        //
    }

    /**
     * @return array{title: string|null, items: list<RawItem>}
     *
     * @throws SourceException
     */
    public function parse(string $xml, string $feedUrl, ?int $limit = null): array
    {
        $feed = new SimplePie;
        $feed->set_raw_data($xml);
        $feed->enable_cache(false);
        $feed->enable_order_by_date(false);
        $feed->set_stupidly_fast(true);

        if (! $feed->init()) {
            $error = $feed->error();

            throw new SourceException('The feed could not be parsed: '.(is_array($error) ? implode('; ', $error) : ($error ?: 'unknown error')));
        }

        $limit ??= (int) config('curation.fetch.max_items_per_fetch', 50);
        $items = [];

        foreach ($feed->get_items(0, $limit) as $entry) {
            $item = $this->toRawItem($entry, $feedUrl);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [
            'title' => Text::clean($feed->get_title()),
            'items' => $items,
        ];
    }

    /**
     * Quick check whether a response body looks like an RSS/Atom feed.
     */
    public static function looksLikeFeed(string $body): bool
    {
        $head = ltrim(substr($body, 0, 1000));

        return (bool) preg_match('/^(\xEF\xBB\xBF)?(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<(rss|feed|rdf:RDF)\b/is', $head);
    }

    protected function toRawItem(FeedItem $entry, string $feedUrl): ?RawItem
    {
        $link = $entry->get_permalink();
        $title = Text::title($entry->get_title());

        if (! $link || $title === '') {
            return null;
        }

        $media = [];

        // YouTube: <yt:videoId> and <media:group>.
        if ($videoId = $entry->get_item_tags(self::NS_YOUTUBE, 'videoId')[0]['data'] ?? null) {
            $media['video_id'] = $videoId;
            $media['embed'] = 'youtube';
            $media['image'] = "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg";
        }

        $group = $entry->get_item_tags(SimplePie::NAMESPACE_MEDIARSS, 'group')[0]['child'][SimplePie::NAMESPACE_MEDIARSS] ?? [];
        $description = $group['description'][0]['data'] ?? null;

        // Google News: <source url="https://publisher">Publisher</source>.
        if ($source = $entry->get_item_tags('', 'source')[0] ?? null) {
            $media['publisher'] = [
                'name' => Text::clean($source['data'] ?? null),
                'url' => $source['attribs']['']['url'] ?? null,
            ];
        }

        $media['image'] ??= $this->image($entry);

        $date = $entry->get_date('U');
        $content = $entry->get_content(true);
        $summary = $entry->get_description(true);

        return new RawItem(
            url: $this->urls->absolutize($link, $feedUrl),
            title: $title,
            externalId: $entry->get_id() ?: null,
            excerpt: Text::clean($description ?? $summary ?? $content, 1000),
            content: Text::clean($content !== $summary ? $content : null, 20000),
            publishedAt: is_numeric($date) ? Carbon::createFromTimestamp((int) $date) : null,
            author: Text::clean($entry->get_author()?->get_name()),
            media: array_filter($media),
        );
    }

    protected function image(FeedItem $entry): ?string
    {
        $thumbnail = $entry->get_thumbnail();

        if (is_array($thumbnail)) {
            return $thumbnail['url'];
        }

        foreach ($entry->get_enclosures() ?? [] as $enclosure) {
            if ($enclosure->get_thumbnail()) {
                return $enclosure->get_thumbnail();
            }

            if (str_starts_with((string) $enclosure->get_type(), 'image/') && $enclosure->get_link()) {
                return $enclosure->get_link();
            }
        }

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $entry->get_content(), $match)) {
            return html_entity_decode($match[1]);
        }

        return null;
    }
}
