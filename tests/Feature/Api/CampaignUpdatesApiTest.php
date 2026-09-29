<?php

namespace Tests\Feature\Api;

use App\Models\Campaign;
use App\Models\Item;
use App\Models\Source;
use App\Models\User;
use App\Models\WorkspaceApiToken;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignUpdatesApiTest extends TestCase
{
    use RefreshDatabase;

    protected Campaign $campaign;

    protected Source $source;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campaign = Campaign::factory()->create(['slug' => 'football-india', 'name' => 'Football tournaments in India']);
        $this->source = Source::factory()->for($this->campaign)->create(['name' => 'KFA', 'target' => 'https://kfa.example.org/feed']);

        $this->token = app(CurrentWorkspace::class)->run($this->campaign->workspace, fn () => WorkspaceApiToken::issue('Website')[1]);
    }

    public function test_a_valid_token_is_required(): void
    {
        $this->getJson('/api/v1/campaigns/football-india/updates')->assertUnauthorized();
        $this->getJson('/api/v1/campaigns/football-india/updates', ['Authorization' => 'Bearer dc_wrong'])->assertUnauthorized();
    }

    public function test_only_approved_items_are_returned_with_attribution(): void
    {
        $approved = Item::factory()->for($this->source)->enriched()->approved()->create([
            'edited_title' => 'Kerala squad named for Santosh Trophy',
            'media' => ['image' => 'https://img.example/x.jpg'],
        ]);
        Item::factory()->for($this->source)->create();
        Item::factory()->for($this->source)->rejected()->create();

        $response = $this->withToken($this->token)->getJson('/api/v1/campaigns/football-india/updates');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approved->id)
            ->assertJsonPath('data.0.title', 'Kerala squad named for Santosh Trophy')
            ->assertJsonPath('data.0.summary', $approved->ai_summary)
            ->assertJsonPath('data.0.fields.tournament', 'Santosh Trophy')
            ->assertJsonPath('data.0.image', 'https://img.example/x.jpg')
            ->assertJsonPath('data.0.source.name', 'KFA')
            ->assertJsonPath('data.0.source.type', 'rss')
            ->assertJsonPath('data.0.source.url', 'https://kfa.example.org')
            ->assertJsonPath('campaign.slug', 'football-india')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_a_token_cannot_read_another_workspace(): void
    {
        $other = Campaign::factory()->create(['slug' => 'other-campaign']);
        Item::factory()->for(Source::factory()->for($other))->approved()->create();

        $this->withToken($this->token)->getJson('/api/v1/campaigns/other-campaign/updates')->assertNotFound();
    }

    public function test_filters_and_pagination(): void
    {
        Item::factory()->for($this->source)->approved()->count(3)->create(['ai_category' => 'Result', 'reviewed_at' => now()->subDays(5)]);
        Item::factory()->for($this->source)->approved()->create(['ai_category' => 'Announcement', 'reviewed_at' => now()]);

        $this->withToken($this->token)->getJson('/api/v1/campaigns/football-india/updates?category=Result&per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);

        $this->withToken($this->token)->getJson('/api/v1/campaigns/football-india/updates?since='.now()->subDay()->toIso8601String())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.category', 'Announcement');

        $this->withToken($this->token)->getJson('/api/v1/campaigns/football-india/updates?per_page=500')->assertUnprocessable();
    }

    public function test_approving_an_item_invalidates_the_cached_response(): void
    {
        $item = Item::factory()->for($this->source)->create();

        $this->withToken($this->token)->getJson('/api/v1/campaigns/football-india/updates')->assertJsonCount(0, 'data');

        app(CurrentWorkspace::class)->run($this->campaign->workspace, fn () => $item->approve(User::factory()->create()));

        $this->withToken($this->token)->getJson('/api/v1/campaigns/football-india/updates')->assertJsonCount(1, 'data');
    }

    public function test_campaigns_are_listed_and_rss_is_available_with_a_query_token(): void
    {
        Item::factory()->for($this->source)->approved()->create(['title' => 'U-17 trials in Kochi']);

        $this->withToken($this->token)->getJson('/api/v1/campaigns')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'football-india')
            ->assertJsonPath('data.0.approved_count', 1);

        $this->get('/api/v1/campaigns/football-india/feed.rss?token='.$this->token)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8')
            ->assertSee('<title>U-17 trials in Kochi</title>', false)
            ->assertSee('<source url="https://kfa.example.org">KFA</source>', false);
    }
}
