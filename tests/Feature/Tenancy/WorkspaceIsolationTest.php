<?php

namespace Tests\Feature\Tenancy;

use App\Enums\WorkspaceRole;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use App\Tenancy\MissingWorkspaceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Tests\Fixtures\RecordWorkspaceJob;
use Tests\TestCase;

class WorkspaceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_querying_a_tenant_model_without_a_workspace_fails_closed(): void
    {
        Campaign::factory()->create();

        $this->expectException(MissingWorkspaceException::class);

        Campaign::count();
    }

    public function test_queries_only_see_the_current_workspace(): void
    {
        $a = Campaign::factory()->create(['name' => 'A campaign']);
        $b = Campaign::factory()->create(['name' => 'B campaign']);

        $this->enterWorkspace($a->workspace);

        $this->assertSame(['A campaign'], Campaign::pluck('name')->all());
        $this->assertNull(Campaign::find($b->id));
        $this->assertSame(2, Campaign::acrossWorkspaces()->count());
    }

    public function test_new_tenant_models_are_stamped_with_the_current_workspace(): void
    {
        $workspace = Workspace::factory()->create();

        $this->enterWorkspace($workspace);

        $campaign = Campaign::create(['name' => 'Football tournaments in India']);

        $this->assertSame($workspace->id, $campaign->workspace_id);
        $this->assertSame('football-tournaments-in-india', $campaign->slug);
    }

    public function test_creating_a_tenant_model_without_a_workspace_fails(): void
    {
        $this->expectException(MissingWorkspaceException::class);

        Campaign::create(['name' => 'Orphan']);
    }

    public function test_campaign_slugs_are_unique_per_workspace_only(): void
    {
        [$a] = $this->workspaceWithUser();
        [$b] = $this->workspaceWithUser();

        $first = app(CurrentWorkspace::class)->run($a, fn () => Campaign::create(['name' => 'Football']));
        $second = app(CurrentWorkspace::class)->run($a, fn () => Campaign::create(['name' => 'Football']));
        $other = app(CurrentWorkspace::class)->run($b, fn () => Campaign::create(['name' => 'Football']));

        $this->assertSame('football', $first->slug);
        $this->assertSame('football-2', $second->slug);
        $this->assertSame('football', $other->slug);
    }

    public function test_run_restores_the_previous_workspace(): void
    {
        [$a] = $this->workspaceWithUser();
        [$b] = $this->workspaceWithUser();
        $current = app(CurrentWorkspace::class);

        $this->enterWorkspace($a);
        $inside = $current->run($b, fn () => $current->id());

        $this->assertSame($b->id, $inside);
        $this->assertSame($a->id, $current->id());
    }

    public function test_queued_jobs_run_inside_the_dispatching_workspace(): void
    {
        config(['queue.default' => 'database']);
        [$workspace] = $this->workspaceWithUser();

        $this->enterWorkspace($workspace);
        RecordWorkspaceJob::dispatch();

        // Simulate a fresh worker process: nothing in memory knows the tenant.
        Context::flush();
        app()->forgetScopedInstances();
        $this->assertFalse(app(CurrentWorkspace::class)->check());

        RecordWorkspaceJob::$seen = null;
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertSame($workspace->id, RecordWorkspaceJob::$seen);
    }

    public function test_web_requests_resolve_the_users_workspace(): void
    {
        [$workspace, $user] = $this->workspaceWithUser(WorkspaceRole::Reviewer);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame($workspace->id, app(CurrentWorkspace::class)->id());
        $this->assertSame($workspace->id, $user->fresh()->current_workspace_id);
    }

    public function test_a_user_cannot_keep_a_workspace_they_were_removed_from(): void
    {
        [$mine, $user] = $this->workspaceWithUser();
        $foreign = Workspace::factory()->create();
        $user->forceFill(['current_workspace_id' => $foreign->id])->save();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $this->assertSame($mine->id, app(CurrentWorkspace::class)->id());
    }

    public function test_users_without_a_workspace_are_blocked_from_tenant_pages(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertForbidden();
    }
}
