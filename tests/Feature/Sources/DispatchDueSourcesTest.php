<?php

namespace Tests\Feature\Sources;

use App\Jobs\FetchSourceJob;
use App\Models\Campaign;
use App\Models\Source;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchDueSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_due_sources_in_active_campaigns_are_dispatched_inside_their_workspace(): void
    {
        Queue::fake();

        $due = Source::factory()->create(['next_fetch_at' => now()->subMinute()]);
        $never = Source::factory()->create(['next_fetch_at' => null]);
        $later = Source::factory()->create(['next_fetch_at' => now()->addHour()]);
        $inactive = Source::factory()->create(['is_active' => false]);
        $pausedCampaign = Source::factory()->for(Campaign::factory()->paused())->create();

        $seen = [];
        Queue::assertNothingPushed();

        $this->artisan('curation:dispatch-due')->assertSuccessful();

        Queue::assertPushed(FetchSourceJob::class, 2);
        Queue::assertPushed(FetchSourceJob::class, function (FetchSourceJob $job) use (&$seen) {
            $seen[$job->source->id] = $job->source->workspace_id;

            return true;
        });

        $this->assertEqualsCanonicalizing([$due->id, $never->id], array_keys($seen));
        $this->assertFalse(app(CurrentWorkspace::class)->check(), 'The command must not leak a workspace.');
        $this->assertTrue($due->fresh()->next_fetch_at->isFuture());
        $this->assertNotContains($later->id, array_keys($seen));
        $this->assertNotContains($inactive->id, array_keys($seen));
        $this->assertNotContains($pausedCampaign->id, array_keys($seen));
    }

    public function test_queued_payloads_carry_the_sources_workspace(): void
    {
        config(['queue.default' => 'database']);
        $source = Source::factory()->create(['next_fetch_at' => null]);

        $this->artisan('curation:dispatch-due')->assertSuccessful();

        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

        $this->assertSame($source->workspace_id, data_get($payload, 'illuminate:log:context.data.workspace_id') !== null
            ? (int) unserialize(data_get($payload, 'illuminate:log:context.data.workspace_id'))
            : null);
    }
}
