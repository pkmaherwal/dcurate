<?php

namespace Tests\Feature\Sources;

use App\Enums\WorkspaceRole;
use App\Events\SourcePaused;
use App\Models\Campaign;
use App\Models\Source;
use App\Models\User;
use App\Notifications\SourcePausedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SourcePausedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owners_and_admins_are_emailed_when_a_source_is_paused(): void
    {
        Notification::fake();
        [$workspace, $owner] = $this->workspaceWithUser();
        $admin = User::factory()->create();
        $reviewer = User::factory()->create();
        $workspace->addMember($admin, WorkspaceRole::Admin);
        $workspace->addMember($reviewer, WorkspaceRole::Reviewer);

        $this->enterWorkspace($workspace);
        $source = Source::factory()->for(Campaign::factory()->for($workspace))->create();

        SourcePaused::dispatch($source, 'HTTP 500');

        Notification::assertSentTo([$owner, $admin], SourcePausedNotification::class);
        Notification::assertNotSentTo($reviewer, SourcePausedNotification::class);
    }
}
