<?php

namespace Tests\Feature\Sources;

use App\Enums\SourceType;
use App\Models\Campaign;
use App\Models\Source;
use App\Sources\Adapters\GoogleNewsAdapter;
use App\Sources\Adapters\RssAdapter;
use App\Sources\Adapters\YouTubeAdapter;
use App\Sources\SourceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeedAdaptersTest extends TestCase
{
    use RefreshDatabase;

    public function test_rss_feed_is_discovered_from_the_site_url(): void
    {
        Http::fake([
            'kfa.example.org/feed/' => Http::response($this->sourceFixture('rss.xml')),
            'kfa.example.org' => Http::response($this->sourceFixture('site-with-feed.html')),
        ]);

        $source = new Source(['type' => SourceType::Rss, 'target' => 'https://kfa.example.org', 'name' => '']);

        app(RssAdapter::class)->prepare($source);

        $this->assertSame('https://kfa.example.org/feed/', $source->option('feed_url'));
        $this->assertSame('Kerala Football Association', $source->name);
    }

    public function test_a_page_without_a_feed_is_rejected_with_a_helpful_message(): void
    {
        Http::fake(['*' => Http::response('<html><head><title>No feed</title></head></html>')]);

        $this->expectException(SourceException::class);
        $this->expectExceptionMessage('No RSS/Atom feed');

        app(RssAdapter::class)->prepare(new Source(['type' => SourceType::Rss, 'target' => 'https://nofeed.example.org']));
    }

    public function test_rss_items_are_parsed(): void
    {
        Http::fake(['kfa.example.org/*' => Http::response($this->sourceFixture('rss.xml'), 200, ['ETag' => '"v1"'])]);

        $source = new Source(['type' => SourceType::Rss, 'target' => 'https://kfa.example.org/feed/']);
        $result = app(RssAdapter::class)->fetch($source);

        $this->assertCount(3, $result->items);
        $this->assertSame('"v1"', $result->sourceUpdates['etag']);

        $first = $result->items[0];
        $this->assertSame('Santosh Trophy 2026: Kerala squad announced for group stage', $first->title);
        $this->assertSame('The Kerala Football Association has named its 22-member squad.', $first->excerpt);
        $this->assertSame('https://kfa.example.org/img/squad.jpg', $first->media['image']);
        $this->assertSame('KFA Media', $first->author);
        $this->assertNotNull($first->publishedAt);
    }

    public function test_unchanged_feeds_are_skipped_with_conditional_requests(): void
    {
        Http::fake(['*' => Http::response('', 304)]);

        $source = new Source(['type' => SourceType::Rss, 'target' => 'https://kfa.example.org/feed/']);
        $source->etag = '"v1"';

        $result = app(RssAdapter::class)->fetch($source);

        $this->assertTrue($result->notModified);
        Http::assertSent(fn (Request $request) => $request->header('If-None-Match') === ['"v1"']);
    }

    public function test_youtube_handles_resolve_to_a_channel_and_videos_are_parsed(): void
    {
        Http::fake([
            'www.youtube.com/@indianfootballtv' => Http::response($this->sourceFixture('youtube-channel.html')),
            'www.youtube.com/feeds/*' => Http::response($this->sourceFixture('youtube.xml')),
        ]);

        $source = new Source(['type' => SourceType::YouTube, 'target' => 'https://www.youtube.com/@indianfootballtv']);
        $adapter = app(YouTubeAdapter::class);

        $adapter->prepare($source);
        $this->assertSame('UCabcdefghijklmnopqrstuv', $source->option('channel_id'));
        $this->assertSame('Indian Football TV', $source->name);

        $item = $adapter->fetch($source)->items[0];
        Http::assertSent(fn (Request $request) => $request->url() === 'https://www.youtube.com/feeds/videos.xml?channel_id=UCabcdefghijklmnopqrstuv');
        $this->assertSame('https://www.youtube.com/watch?v=abc123XYZ00', $item->url);
        $this->assertSame('abc123XYZ00', $item->media['video_id']);
        $this->assertSame('youtube', $item->media['embed']);
        $this->assertSame('All goals from the quarter-final in Kolkata.', $item->excerpt);
    }

    public function test_google_news_uses_the_campaign_region_and_strips_publisher_suffixes(): void
    {
        Http::fake(['news.google.com/*' => Http::response($this->sourceFixture('google-news.xml'))]);

        $campaign = Campaign::factory()->create(['region' => 'IN', 'language' => 'en-IN']);
        $this->enterWorkspace($campaign->workspace);
        $source = Source::factory()->for($campaign)->type(SourceType::GoogleNews, '"football tournament" Kerala')->create();

        $item = app(GoogleNewsAdapter::class)->fetch($source)->items[0];

        $this->assertSame('Kerala to host Santosh Trophy final round in March', $item->title);
        $this->assertSame('The Hindu', $item->media['publisher']['name']);
        $this->assertSame('https://www.thehindu.com', $item->media['publisher']['url']);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'gl=IN')
                && str_contains($request->url(), 'hl=en-IN')
                && str_contains($request->url(), 'ceid=IN%3Aen')
                && str_contains(urldecode($request->url()), '"football tournament" Kerala when:7d');
        });
    }
}
