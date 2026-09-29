<?php

namespace Tests;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Create a workspace with a member in the given role.
     *
     * @return array{0: Workspace, 1: User}
     */
    protected function workspaceWithUser(WorkspaceRole $role = WorkspaceRole::Owner): array
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $workspace->addMember($user, $role);

        return [$workspace, $user->fresh()];
    }

    /**
     * Enter the workspace for code that runs outside HTTP middleware (Livewire::test, jobs, services).
     */
    protected function enterWorkspace(Workspace $workspace): static
    {
        app(CurrentWorkspace::class)->set($workspace);

        return $this;
    }

    /**
     * Load a fixture from tests/Fixtures/sources, filling in {{recent}} / {{recent_iso}} dates.
     */
    protected function sourceFixture(string $name): string
    {
        $contents = (string) file_get_contents(base_path("tests/Fixtures/sources/{$name}"));

        return strtr($contents, [
            '{{recent}}' => now()->subHours(3)->toRfc2822String(),
            '{{recent_iso}}' => now()->subHours(3)->toIso8601String(),
        ]);
    }

    /**
     * Act as the user inside the workspace.
     */
    protected function actingInWorkspace(User $user, Workspace $workspace): static
    {
        return $this->enterWorkspace($workspace)->actingAs($user);
    }
}
