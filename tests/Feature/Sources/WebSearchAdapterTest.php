<?php

namespace Tests\Feature\Sources;

use App\Ai\Agents\WebSearchDiscoverer;
use App\Enums\SourceType;
use App\Enums\WorkspacePlan;
use App\Models\AiUsage;
use App\Models\Campaign;
use App\Models\Source;
use App\Sources\Adapters\WebSearchAdapter;
use App\Sources\SourceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\UrlCitation;
use Tests\TestCase;

class WebSearchAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.providers.openai.key' => 'test-key']);
        $campaign = Campaign::factory()->create();
        $this->enterWorkspace($campaign->workspace);
        $this->source = Source::factory()->for($campaign)->type(SourceType::WebSearch, 'Newly announced football tournaments in Kerala')->create();
    }

    protected function fakeCitations(): void
    {
        WebSearchDiscoverer::fake([new AgentResponse('inv', '- Kerala U-17 league announced [1]', new TextUsage(1200, 150), new Meta('openai', 'gpt-5.4-nano', collect([
            new UrlCitation('https://www.thehindu.com/sport/football/kerala-u17-league-announced/article123.ece?utm_source=openai', 'Kerala U-17 league announced'),
            new UrlCitation('https://www.thehindu.com/sport/football/kerala-u17-league-announced/article123.ece', 'Duplicate citation'),
            new UrlCitation('https://www.instagram.com/keralafa/', 'KFA Instagram'),
            new UrlCitation('https://keralafa.example.org/', 'KFA home'),
        ])))]);
    }

    public function test_items_come_from_real_citations_only(): void
    {
        $this->fakeCitations();

        $items = app(WebSearchAdapter::class)->fetch($this->source)->items;

        $this->assertCount(1, $items, 'Duplicates, social profiles and homepages are skipped.');
        $this->assertSame('https://www.thehindu.com/sport/football/kerala-u17-league-announced/article123.ece', $items[0]->url);
        $this->assertSame('Kerala U-17 league announced', $items[0]->title);
        $this->assertSame('thehindu.com', $items[0]->media['publisher']['name']);

        $usage = AiUsage::firstOrFail();
        $this->assertSame('web_search', $usage->purpose);
        $this->assertSame(1, $usage->web_search_calls);
        $this->assertGreaterThan(0.01, (float) $usage->est_cost_usd);
    }

    public function test_the_search_is_localized_to_the_campaign_region(): void
    {
        $this->source->mergeConfig(['allowed_domains' => ['the-aiff.com']]);

        $tools = [...(new WebSearchDiscoverer($this->source))->tools()];

        $this->assertSame('IN', $tools[0]->country);
        $this->assertSame(['the-aiff.com'], $tools[0]->allowedDomains);
    }

    public function test_the_daily_search_limit_of_the_plan_is_respected(): void
    {
        $this->fakeCitations();
        $this->source->workspace->update(['plan' => WorkspacePlan::Free]);

        foreach (range(1, 5) as $ignored) {
            app(WebSearchAdapter::class)->fetch($this->source->fresh());
        }

        $this->assertTrue(app(WebSearchAdapter::class)->fetch($this->source->fresh())->notModified);
        WebSearchDiscoverer::assertPromptedTimes(5);
    }

    public function test_an_openai_key_is_required(): void
    {
        config(['ai.providers.openai.key' => null]);

        $this->expectException(SourceException::class);

        app(WebSearchAdapter::class)->prepare($this->source);
    }
}
