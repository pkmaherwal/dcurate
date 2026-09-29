<?php

namespace Tests\Feature\Sources;

use App\Ai\Agents\ListingExtractor;
use App\Enums\SourceType;
use App\Models\Campaign;
use App\Models\Source;
use App\Sources\Adapters\WebsiteAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        config(['curation.extractor.url' => null, 'ai.providers.openai.key' => 'test-key']);
        $this->campaign = Campaign::factory()->create();
        $this->enterWorkspace($this->campaign->workspace);
    }

    protected function source(string $target, array $config = []): Source
    {
        return Source::factory()->for($this->campaign)->type(SourceType::Website, $target)->create(['config' => $config]);
    }

    public function test_ai_listing_mode_keeps_only_links_that_are_on_the_page(): void
    {
        Http::fake(['www.example-ff.org/*' => Http::response($this->sourceFixture('listing.html'))]);
        ListingExtractor::fake([[
            'items' => [
                ['title' => 'Santosh Trophy 2026 schedule announced', 'url' => '/news/santosh-trophy-2026-schedule-announced', 'published_at' => '2026-09-27'],
                ['title' => 'U-17 national camp: list of probables', 'url' => 'https://www.example-ff.org/news/u17-national-camp-probables', 'published_at' => null],
                ['title' => 'Invented story', 'url' => 'https://www.example-ff.org/news/does-not-exist', 'published_at' => null],
            ],
        ]]);

        $source = $this->source('https://www.example-ff.org/news');
        $result = app(WebsiteAdapter::class)->fetch($source);

        $this->assertSame([
            'https://www.example-ff.org/news/santosh-trophy-2026-schedule-announced',
            'https://www.example-ff.org/news/u17-national-camp-probables',
        ], array_map(fn ($item) => $item->url, $result->items));
        $this->assertSame('2026-09-27', $result->items[0]->publishedAt?->toDateString());
        $this->assertNotNull($result->sourceUpdates['content_hash']);

        ListingExtractor::assertPrompted(fn ($prompt) => $prompt->contains('https://www.example-ff.org/news/u17-national-camp-probables | U-17 national camp'));
    }

    public function test_an_unchanged_page_does_not_call_the_ai_again(): void
    {
        Http::fake(['www.example-ff.org/*' => Http::response($this->sourceFixture('listing.html'))]);
        ListingExtractor::fake([['items' => []]]);

        $source = $this->source('https://www.example-ff.org/news');
        $first = app(WebsiteAdapter::class)->fetch($source);
        $source->forceFill($first->sourceUpdates)->save();

        $second = app(WebsiteAdapter::class)->fetch($source->fresh());

        $this->assertTrue($second->notModified);
        ListingExtractor::assertPromptedTimes(1);
    }

    public function test_css_selectors_mode(): void
    {
        Http::fake(['www.example-ff.org/*' => Http::response($this->sourceFixture('listing.html'))]);
        ListingExtractor::fake();

        $source = $this->source('https://www.example-ff.org/news', ['selectors' => ['item' => 'main article', 'title' => 'h3', 'link' => 'h3 a', 'date' => 'time']]);
        $items = app(WebsiteAdapter::class)->fetch($source)->items;

        $this->assertCount(3, $items);
        $this->assertSame('Santosh Trophy 2026 schedule announced', $items[0]->title);
        $this->assertSame('2026-09-25', $items[1]->publishedAt?->toDateString());
        ListingExtractor::assertNeverPrompted();
    }

    public function test_news_sitemaps_give_titles_and_dates_and_skip_stale_urls(): void
    {
        Http::fake(['www.example-ff.org/*' => Http::response($this->sourceFixture('news-sitemap.xml'))]);

        $items = app(WebsiteAdapter::class)->fetch($this->source('https://www.example-ff.org/news-sitemap.xml'))->items;

        $this->assertCount(1, $items);
        $this->assertSame('Santosh Trophy 2026 schedule announced', $items[0]->title);
        $this->assertNotNull($items[0]->publishedAt);
    }

    public function test_plain_pages_still_work_when_the_extractor_is_down(): void
    {
        config(['curation.extractor.url' => 'http://127.0.0.1:8100']);
        Http::fake([
            '127.0.0.1:8100/*' => fn () => throw new ConnectionException('Connection refused'),
            'www.example-ff.org/*' => Http::response($this->sourceFixture('listing.html')),
        ]);
        ListingExtractor::fake([['items' => [['title' => 'Santosh Trophy 2026 schedule announced', 'url' => '/news/santosh-trophy-2026-schedule-announced', 'published_at' => null]]]]);

        $items = app(WebsiteAdapter::class)->fetch($this->source('https://www.example-ff.org/news'))->items;

        $this->assertCount(1, $items);
    }

    public function test_the_extractor_sidecar_is_used_when_configured(): void
    {
        config(['curation.extractor.url' => 'http://127.0.0.1:8100', 'curation.extractor.token' => 'secret']);
        Http::fake(['127.0.0.1:8100/extract' => Http::response([
            'url' => 'https://www.example-ff.org/news',
            'title' => 'News',
            'markdown' => '## Latest',
            'links' => [['url' => 'https://www.example-ff.org/news/a-long-enough-headline', 'text' => 'A long enough headline']],
            'content_hash' => 'abc',
        ])]);
        ListingExtractor::fake([['items' => [['title' => 'A long enough headline', 'url' => 'https://www.example-ff.org/news/a-long-enough-headline', 'published_at' => null]]]]);

        $source = $this->source('https://www.example-ff.org/news', ['render_js' => true]);
        $result = app(WebsiteAdapter::class)->fetch($source);

        $this->assertCount(1, $result->items);
        $this->assertSame('abc', $result->sourceUpdates['content_hash']);
        Http::assertSent(fn (Request $request) => $request['render_js'] === true
            && $request['mode'] === 'listing'
            && $request->header('Authorization') === ['Bearer secret']);
    }
}
