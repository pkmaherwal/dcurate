<?php

namespace Tests\Feature\Livewire;

use App\Enums\WorkspaceRole;
use App\Livewire\Workspace\Settings;
use App\Models\User;
use App\Models\WorkspaceApiToken;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_invite_members_who_get_a_password_link(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->workspaceWithUser();
        $this->actingInWorkspace($owner, $workspace);

        Livewire::test(Settings::class)
            ->set('memberName', 'Riya')
            ->set('memberEmail', 'riya@example.com')
            ->set('memberRole', 'reviewer')
            ->call('addMember')
            ->assertHasNoErrors();

        $riya = User::where('email', 'riya@example.com')->firstOrFail();
        $this->assertSame(WorkspaceRole::Reviewer, $workspace->roleOf($riya));
        $this->assertSame($workspace->id, $riya->current_workspace_id);
        Notification::assertSentTo($riya, ResetPassword::class);
    }

    public function test_api_tokens_are_shown_once_and_stored_hashed(): void
    {
        [$workspace, $owner] = $this->workspaceWithUser();
        $this->actingInWorkspace($owner, $workspace);

        $component = Livewire::test(Settings::class)->set('tokenName', 'Website')->call('createToken');

        $plain = $component->get('plainToken');
        $this->assertStringStartsWith('dc_', $plain);
        $this->assertSame(hash('sha256', $plain), WorkspaceApiToken::firstOrFail()->token);
        $this->assertTrue(WorkspaceApiToken::findValid($plain)?->workspace->is($workspace));
    }

    public function test_ai_settings_are_saved_per_workspace(): void
    {
        [$workspace, $owner] = $this->workspaceWithUser();
        $this->actingInWorkspace($owner, $workspace);

        Livewire::test(Settings::class)
            ->set('aiModel', 'gpt-5-nano')
            ->set('prefilterModel', 'gpt-5-nano')
            ->set('dailyBudget', '2.5')
            ->call('saveAi')
            ->assertHasNoErrors();

        $workspace->refresh();
        $this->assertSame('gpt-5-nano', $workspace->setting('ai.model'));
        $this->assertSame(2.5, $workspace->setting('ai.daily_budget_usd'));
    }

    public function test_reviewers_cannot_open_workspace_settings(): void
    {
        [$workspace, $reviewer] = $this->workspaceWithUser(WorkspaceRole::Reviewer);

        $this->actingAs($reviewer)->get(route('workspace.settings'))->assertForbidden();
    }
}
