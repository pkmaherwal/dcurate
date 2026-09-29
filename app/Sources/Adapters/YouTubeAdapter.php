<?php

namespace App\Sources\Adapters;

use App\Models\Source;
use App\Sources\Contracts\SourceAdapter;
use App\Sources\FetchResult;
use App\Sources\RawItem;
use App\Sources\SourceException;
use App\Sources\Support\FeedParser;
use App\Sources\Support\HttpClient;
use App\Sources\Support\Text;
use App\Sources\Support\YouTubeChannelResolver;
use Illuminate\Support\Carbon;

/**
 * YouTube channels via the free channel RSS feed, with the Data API as a fallback.
 */
class YouTubeAdapter implements SourceAdapter
{
    public function __construct(
        protected HttpClient $http,
        protected FeedParser $parser,
        protected YouTubeChannelResolver $resolver,
    ) {
        //
    }

    public function prepare(Source $source): void
    {
        $channel = $this->resolver->resolve($source->target);

        $source->mergeConfig(['channel_id' => $channel['channel_id']]);

        if (blank($source->name)) {
            $source->name = $channel['title'] ?? 'YouTube '.$channel['channel_id'];
        }
    }

    public function fetch(Source $source, bool $conditional = true): FetchResult
    {
        $channelId = $source->option('channel_id') ?? $this->resolver->resolve($source->target)['channel_id'];
        $feedUrl = 'https://www.youtube.com/feeds/videos.xml?channel_id='.$channelId;

        $response = $this->http->get($feedUrl, $source, $conditional);

        if ($response->status() === 304) {
            return FetchResult::notModified();
        }

        if ($response->successful()) {
            return new FetchResult(
                items: $this->parser->parse($response->body(), $feedUrl)['items'],
                sourceUpdates: $this->http->validators($response),
                configUpdates: ['channel_id' => $channelId],
            );
        }

        if (config('curation.youtube.api_key')) {
            return new FetchResult(items: $this->fromDataApi($channelId), configUpdates: ['channel_id' => $channelId]);
        }

        throw new SourceException("The YouTube feed returned HTTP {$response->status()}. Set YOUTUBE_API_KEY to use the Data API fallback.");
    }

    /**
     * Latest uploads via playlistItems.list (1 quota unit per call).
     *
     * @return list<RawItem>
     */
    protected function fromDataApi(string $channelId): array
    {
        $response = $this->http->request()->get('https://www.googleapis.com/youtube/v3/playlistItems', [
            'part' => 'snippet',
            'playlistId' => 'UU'.substr($channelId, 2),
            'maxResults' => 25,
            'key' => config('curation.youtube.api_key'),
        ]);

        if (! $response->successful()) {
            throw new SourceException('YouTube Data API error: '.$response->json('error.message', 'HTTP '.$response->status()));
        }

        $items = [];

        foreach ((array) $response->json('items', []) as $item) {
            $videoId = data_get($item, 'snippet.resourceId.videoId');

            if (! is_string($videoId) || $videoId === '') {
                continue;
            }

            $publishedAt = data_get($item, 'snippet.publishedAt');

            $items[] = new RawItem(
                url: "https://www.youtube.com/watch?v={$videoId}",
                title: Text::title(data_get($item, 'snippet.title')),
                externalId: "yt:video:{$videoId}",
                excerpt: Text::clean(data_get($item, 'snippet.description'), 1000),
                publishedAt: is_string($publishedAt) ? Carbon::parse($publishedAt) : null,
                author: data_get($item, 'snippet.channelTitle'),
                media: ['video_id' => $videoId, 'embed' => 'youtube', 'image' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg"],
            );
        }

        return $items;
    }
}
