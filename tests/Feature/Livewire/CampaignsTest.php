<?php

namespace Tests\Feature\Livewire;

use App\Enums\WorkspacePlan;
use App\Enums\WorkspaceRole;
use App\Livewire\Campaigns\Index;
use App\Livewire\Campaigns\Settings;
use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CampaignsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_create_campaigns_from_a_template(): void
    {
        [$workspace, $user] = $this->workspaceWithUser(WorkspaceRole::Admin);
        $this->actingInWorkspace($user, $workspace);

        Livewire::test(Index::class)
            ->set('name', 'Football tournaments in India')
            ->set('template', 'sports_tournaments')
            ->set('keywords', 'football tournament, Santosh Trophy')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect(route('campaigns.sources', 'football-tournaments-in-india'));

        $campaign = Campaign::firstOrFail();
        $this->assertSame($workspace->id, $campaign->workspace_id);
        $this->assertSame(['football tournament', 'Santosh Trophy'], $campaign->keywords);
        $this->assertContains('tournament', array_column($campaign->extraction_fields, 'key'));
        $this->assertSame($user->id, $campaign->created_by);
    }

    public function test_reviewers_cannot_create_campaigns(): void
    {
        [$workspace, $user] = $this->workspaceWithUser(WorkspaceRole::Reviewer);
        $this->actingInWorkspace($user, $workspace);

        Livewire::test(Index::class)->set('name', 'Nope')->call('create')->assertForbidden();
    }

    public function test_plan_limits_are_enforced(): void
    {
        [$workspace, $user] = $this->workspaceWithUser();
        $workspace->update(['plan' => WorkspacePlan::Free]);
        Campaign::factory()->for($workspace)->create();
        $this->actingInWorkspace($user, $workspace);

        Livewire::test(Index::class)->set('name', 'Second')->call('create')->assertForbidden();
    }

    public function test_campaign_pages_of_another_workspace_are_not_found(): void
    {
        [, $user] = $this->workspaceWithUser();
        $foreign = Campaign::factory()->create();

        $this->actingAs($user)->get(route('campaigns.review', $foreign))->assertNotFound();
        $this->actingAs($user)->get(route('campaigns.sources', $foreign))->assertNotFound();
    }

    public function test_ai_fields_can_be_edited(): void
    {
        [$workspace, $user] = $this->workspaceWithUser();
        $campaign = Campaign::factory()->for($workspace)->create();
        $this->actingInWorkspace($user, $workspace);

        Livewire::test(Settings::class, ['campaign' => $campaign])
            ->set('aiBrief', 'Only U-17 tournaments')
            ->set('categories', "Announcement\nResult\n\nResult")
            ->set('fields', [['key' => '', 'label' => 'Age group', 'type' => 'string', 'description' => '']])
            ->call('save')
            ->assertHasNoErrors();

        $campaign->refresh();
        $this->assertSame('Only U-17 tournaments', $campaign->ai_brief);
        $this->assertSame(['Announcement', 'Result'], $campaign->categories);
        $this->assertSame('age_group', $campaign->extraction_fields[0]['key']);
    }

    public function test_campaign_pages_render(): void
    {
        [$workspace, $user] = $this->workspaceWithUser();
        $campaign = Campaign::factory()->for($workspace)->create();

        $this->actingAs($user);

        foreach (['campaigns.index', 'dashboard', 'sources.health', 'workspace.settings'] as $route) {
            $this->get(route($route))->assertOk();
        }

        foreach (['campaigns.review', 'campaigns.sources', 'campaigns.approved', 'campaigns.settings'] as $route) {
            $this->get(route($route, $campaign))->assertOk();
        }
    }
}
