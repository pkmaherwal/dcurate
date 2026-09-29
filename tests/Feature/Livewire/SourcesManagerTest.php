<?php

namespace Tests\Feature\Livewire;

use App\Enums\WorkspaceRole;
use App\Jobs\FetchSourceJob;
use App\Livewire\Sources\Manager;
use App\Models\Campaign;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SourcesManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_site_discovers_its_feed_and_queues_the_first_fetch(): void
    {
        Queue::fake();
        Http::fake([
            'kfa.example.org/feed/' => Http::response($this->sourceFixture('rss.xml')),
            'kfa.example.org' => Http::response($this->sourceFixture('site-with-feed.html')),
        ]);

        [$workspace, $user] = $this->workspaceWithUser();
        $campaign = Campaign::factory()->for($workspace)->create();
        $this->actingInWorkspace($user, $workspace);

        Livewire::test(Manager::class, ['campaign' => $campaign])
            ->call('openCreate')
            ->set('type', 'rss')
            ->set('target', 'https://kfa.example.org')
            ->call('testFetch')
            ->assertSet('name', 'Kerala Football Association')
            ->assertCount('preview', 3)
            ->call('save')
            ->assertHasNoErrors();

        $source = Source::firstOrFail();
        $this->assertSame('https://kfa.example.org/feed/', $source->option('feed_url'));
        Queue::assertPushed(FetchSourceJob::class, fn (FetchSourceJob $job) => $job->source->is($source));
    }

    public function test_bad_targets_show_a_validation_error(): void
    {
        Http::fake(['*' => Http::response('<html><title>x</title></html>')]);

        [$workspace, $user] = $this->workspaceWithUser();
        $campaign = Campaign::factory()->for($workspace)->create();
        $this->actingInWorkspace($user, $workspace);

        Livewire::test(Manager::class, ['campaign' => $campaign])
            ->set('type', 'rss')
            ->set('target', 'https://nofeed.example.org')
            ->call('save')
            ->assertHasErrors(['target']);

        $this->assertSame(0, Source::count());
    }

    public function test_reviewers_cannot_manage_sources(): void
    {
        [$workspace, $user] = $this->workspaceWithUser(WorkspaceRole::Reviewer);
        $campaign = Campaign::factory()->for($workspace)->create();
        $source = Source::factory()->for($campaign)->create();
        $this->actingInWorkspace($user, $workspace);

        Livewire::test(Manager::class, ['campaign' => $campaign])
            ->call('openCreate')->assertForbidden();

        Livewire::test(Manager::class, ['campaign' => $campaign])
            ->call('delete', $source->id)->assertForbidden();

        $this->assertTrue($source->fresh()->exists);
    }

    public function test_sources_of_other_campaigns_cannot_be_touched(): void
    {
        [$workspace, $user] = $this->workspaceWithUser();
        $campaign = Campaign::factory()->for($workspace)->create();
        $foreign = Source::factory()->create();
        $this->actingInWorkspace($user, $workspace);

        Livewire::test(Manager::class, ['campaign' => $campaign])
            ->call('delete', $foreign->id)
            ->assertNotFound();
    }
}
