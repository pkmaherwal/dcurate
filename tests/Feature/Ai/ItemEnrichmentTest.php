<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\ItemEnricher;
use App\Ai\AiRouting;
use App\Enums\AiStatus;
use App\Enums\ItemStatus;
use App\Enums\SourceType;
use App\Events\ItemsIngested;
use App\Jobs\EnrichItemsJob;
use App\Models\AiUsage;
use App\Models\Campaign;
use App\Models\Item;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Ai\ItemEnrichmentService;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\TestCase;

class ItemEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspace;

    protected Campaign $campaign;

    protected Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        config(['curation.extractor.url' => null, 'ai.providers.openai.key' => 'test-key']);

        $this->campaign = Campaign::factory()->create();
        $this->workspace = $this->campaign->workspace;
        $this->source = Source::factory()->for($this->campaign)->create();
        $this->enterWorkspace($this->workspace);
    }

    protected function aiResponse(array $overrides = []): array
    {
        return array_replace_recursive([
            'relevance' => 92,
            'reason' => 'Announces the Santosh Trophy squad.',
            'title' => 'Kerala names 22-member Santosh Trophy squad',
            'summary' => 'The Kerala Football Association named its squad. The group stage starts in March.',
            'language' => 'en',
            'category' => 'Announcement',
            'fields' => ['tournament' => 'Santosh Trophy', 'city' => 'Kochi', 'venue' => null],
        ], $overrides);
    }

    public function test_the_schema_is_built_from_the_campaigns_categories_and_fields(): void
    {
        $this->campaign->update([
            'categories' => ['Result', 'Fixture'],
            'extraction_fields' => [
                ['key' => 'tournament', 'label' => 'Tournament', 'type' => 'string', 'description' => ''],
                ['key' => 'start_date', 'label' => 'Start', 'type' => 'date', 'description' => 'Start date'],
            ],
        ]);

        $agent = new ItemEnricher($this->campaign, $this->workspace);
        $schema = (new ObjectSchema($agent->schema(new JsonSchemaTypeFactory), strict: true))->toSchema();

        $this->assertSame(['Result', 'Fixture'], $schema['properties']['category']['enum']);
        $this->assertEqualsCanonicalizing(['relevance', 'reason', 'title', 'summary', 'language', 'category', 'fields'], $schema['required']);
        $this->assertEqualsCanonicalizing(['tournament', 'start_date'], $schema['properties']['fields']['required']);
        $this->assertSame(['string', 'null'], $schema['properties']['fields']['properties']['start_date']['type']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function test_items_are_enriched_and_usage_is_recorded(): void
    {
        ItemEnricher::fake([$this->aiResponse(['relevance' => 140, 'fields' => ['unknown_key' => 'x']])]);
        $item = Item::factory()->for($this->source)->create();

        $this->assertTrue(app(ItemEnrichmentService::class)->enrich($item));

        $item->refresh();
        $this->assertSame(AiStatus::Done, $item->ai_status);
        $this->assertSame('Kerala names 22-member Santosh Trophy squad', $item->ai_title);
        $this->assertSame(100, $item->ai_relevance, 'Relevance is clamped to 0-100.');
        $this->assertSame('Announcement', $item->ai_category);
        $this->assertSame(['tournament' => 'Santosh Trophy', 'city' => 'Kochi'], $item->ai_fields, 'Nulls and unknown keys are dropped.');
        $this->assertSame(1, AiUsage::sum('requests'));

        ItemEnricher::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains($item->title) && $prompt->contains('Source: '));
    }

    public function test_categories_outside_the_campaign_list_are_ignored(): void
    {
        ItemEnricher::fake([$this->aiResponse(['category' => 'Gossip'])]);
        $item = Item::factory()->for($this->source)->create();

        app(ItemEnrichmentService::class)->enrich($item);

        $this->assertNull($item->fresh()->ai_category);
    }

    public function test_the_daily_budget_stops_enrichment(): void
    {
        ItemEnricher::fake();
        $this->workspace->update(['settings' => ['ai' => ['daily_budget_usd' => 0]]]);
        $item = Item::factory()->for($this->source)->create();

        $this->assertFalse(app(ItemEnrichmentService::class)->enrich($item));

        $this->assertSame(AiStatus::Pending, $item->fresh()->ai_status);
        ItemEnricher::assertNeverPrompted();
    }

    public function test_without_an_api_key_items_stay_pending(): void
    {
        ItemEnricher::fake();
        config(['ai.providers.openai.key' => null]);
        $item = Item::factory()->for($this->source)->create();

        $this->assertTrue(app(ItemEnrichmentService::class)->enrich($item));

        $this->assertSame(AiStatus::Pending, $item->fresh()->ai_status);
        ItemEnricher::assertNeverPrompted();
    }

    public function test_items_found_to_be_old_after_reading_the_article_are_filed_as_outdated(): void
    {
        ItemEnricher::fake();
        config(['curation.extractor.url' => 'http://127.0.0.1:8100']);
        Http::fake(['127.0.0.1:8100/extract' => Http::response([
            'text' => 'An old article.', 'published_at' => '2019-05-01',
        ])]);
        $search = Source::factory()->for($this->campaign)->type(SourceType::WebSearch, 'tournaments')->create();
        $item = Item::factory()->for($search)->create(['published_at' => null, 'excerpt' => null]);

        app(ItemEnrichmentService::class)->enrich($item);

        $item->refresh();
        $this->assertSame(ItemStatus::Rejected, $item->status);
        $this->assertSame('Outdated', $item->reject_reason);
        $this->assertSame('2019-05-01', $item->published_at->toDateString());
        ItemEnricher::assertNeverPrompted();
    }

    public function test_failures_are_recorded_on_the_item(): void
    {
        ItemEnricher::fake(fn () => throw new RuntimeException('Rate limited'));
        $item = Item::factory()->for($this->source)->create();

        app(ItemEnrichmentService::class)->enrich($item);

        $this->assertSame(AiStatus::Failed, $item->fresh()->ai_status);
        $this->assertStringContainsString('Rate limited', $item->fresh()->ai_reason);
    }

    public function test_instagram_posts_are_sent_with_their_image(): void
    {
        ItemEnricher::fake([$this->aiResponse()]);
        $instagram = Source::factory()->for($this->campaign)->type(SourceType::Instagram, 'indianfootball')->create();
        $item = Item::factory()->for($instagram)->create(['excerpt' => '#football', 'media' => ['image' => 'https://cdn.example/poster.jpg']]);

        app(ItemEnrichmentService::class)->enrich($item);

        ItemEnricher::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 1);
    }

    public function test_new_items_are_queued_for_enrichment_in_batches(): void
    {
        Queue::fake();
        config(['curation.ai.batch_size' => 2]);

        ItemsIngested::dispatch($this->source, [1, 2, 3]);

        Queue::assertPushed(EnrichItemsJob::class, 2);
        Queue::assertPushedOn('ai', EnrichItemsJob::class);
    }

    public function test_the_job_enriches_its_pending_items(): void
    {
        ItemEnricher::fake([$this->aiResponse(), $this->aiResponse()]);
        $items = Item::factory()->for($this->source)->count(2)->create();

        (new EnrichItemsJob($items->modelKeys()))->handle(app(ItemEnrichmentService::class), app(CurrentWorkspace::class));

        $this->assertSame(2, Item::where('ai_status', AiStatus::Done->value)->count());
        $this->assertSame(2, (int) AiUsage::sum('requests'), 'Both requests land on the same daily usage row.');
        $this->assertSame(1, AiUsage::count());
    }

    public function test_routing_uses_the_workspace_model_and_reasoning_effort(): void
    {
        $this->assertSame(['reasoning' => ['effort' => 'none']], AiRouting::options($this->workspace, 'openai'));
        $this->assertSame([], AiRouting::options($this->workspace, 'gemini'));

        config(['ai.providers.gemini.key' => 'test-key']);
        $this->assertSame(['openai' => 'gpt-6-luna'], AiRouting::chain($this->workspace), 'No hidden fallback provider.');

        $this->workspace->update(['settings' => ['ai' => ['reasoning_effort' => 'low']]]);
        $this->assertSame(['reasoning' => ['effort' => 'low']], AiRouting::options($this->workspace, 'openai'));
    }
}
