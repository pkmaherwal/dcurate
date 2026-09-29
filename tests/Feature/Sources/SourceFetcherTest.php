<?php

namespace Tests\Feature\Sources;

use App\Enums\FetchRunStatus;
use App\Events\ItemsIngested;
use App\Events\SourcePaused;
use App\Models\Campaign;
use App\Models\Item;
use App\Models\Source;
use App\Services\Curation\SourceFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourceFetcherTest extends TestCase
{
    use RefreshDatabase;

    protected Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $campaign = Campaign::factory()->create(['freshness_days' => 30]);
        $this->enterWorkspace($campaign->workspace);

        $this->source = Source::factory()->for($campaign)->create([
            'target' => 'https://kfa.example.org/feed/',
            'fetch_interval_minutes' => 60,
        ]);
    }

    public function test_new_items_are_stored_once_and_stale_items_are_skipped(): void
    {
        Event::fake([ItemsIngested::class]);
        Http::fake(['*' => Http::response($this->sourceFixture('rss.xml'))]);

        $run = app(SourceFetcher::class)->fetch($this->source);

        $this->assertSame(FetchRunStatus::Success, $run->status);
        $this->assertSame(3, $run->items_found);
        $this->assertSame(2, $run->items_new, 'The 2019 archive item is older than the freshness window.');
        $this->assertSame(
            'https://kfa.example.org/news/santosh-trophy-squad',
            Item::where('title', 'like', 'Santosh Trophy%')->value('canonical_url'),
        );
        Event::assertDispatched(ItemsIngested::class, fn (ItemsIngested $event) => count($event->itemIds) === 2);

        $again = app(SourceFetcher::class)->fetch($this->source->fresh());

        $this->assertSame(0, $again->items_new);
        $this->assertSame(2, Item::count());
    }

    public function test_successful_fetches_schedule_the_next_one(): void
    {
        Http::fake(['*' => Http::response($this->sourceFixture('rss.xml'))]);

        app(SourceFetcher::class)->fetch($this->source);

        $this->source->refresh();
        $this->assertSame('success', $this->source->last_status);
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $this->source->next_fetch_at->timestamp, 5);
    }

    public function test_failures_back_off_and_eventually_pause_the_source(): void
    {
        Event::fake([SourcePaused::class]);
        Http::fake(['*' => Http::response('Server error', 500)]);

        $fetcher = app(SourceFetcher::class);

        $run = $fetcher->fetch($this->source);
        $this->assertSame(FetchRunStatus::Failed, $run->status);
        $this->assertSame(1, $this->source->fresh()->consecutive_errors);
        $this->assertEqualsWithDelta(now()->addHour()->timestamp, $this->source->fresh()->next_fetch_at->timestamp, 5);

        $fetcher->fetch($this->source->fresh());
        $this->assertEqualsWithDelta(now()->addHours(2)->timestamp, $this->source->fresh()->next_fetch_at->timestamp, 5);

        foreach (range(3, 5) as $ignored) {
            $fetcher->fetch($this->source->fresh());
        }

        $this->assertFalse($this->source->fresh()->is_active);
        Event::assertDispatched(SourcePaused::class);
    }

    public function test_the_same_story_from_different_sources_is_clustered(): void
    {
        Http::fake([
            'kfa.example.org/*' => Http::response($this->sourceFixture('rss.xml')),
            'othersite.example.com/*' => Http::response(str_replace(
                ['kfa.example.org/news/', 'Santosh Trophy 2026: Kerala squad announced for group stage'],
                ['othersite.example.com/sports/', 'Santosh Trophy 2026 - Kerala squad announced for the group stage'],
                $this->sourceFixture('rss.xml'),
            )),
        ]);

        app(SourceFetcher::class)->fetch($this->source);
        $original = Item::where('title', 'like', 'Santosh Trophy%')->firstOrFail();

        $other = Source::factory()->for($this->source->campaign)->create(['target' => 'https://othersite.example.com/feed']);
        app(SourceFetcher::class)->fetch($other);

        $copy = Item::where('source_id', $other->id)->where('title', 'like', 'Santosh Trophy%')->firstOrFail();

        $this->assertSame($original->id, $copy->cluster_id);
        $this->assertSame($original->id, $original->fresh()->cluster_id);
    }
}
