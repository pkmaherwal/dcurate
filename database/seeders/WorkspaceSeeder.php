<?php

namespace Database\Seeders;

use App\Enums\WorkspacePlan;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class WorkspaceSeeder extends Seeder
{
    /**
     * Seed the internal workspace and its owner.
     */
    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password'],
        );

        $owner->forceFill(['email_verified_at' => $owner->email_verified_at ?? now()])->save();

        $workspace = Workspace::firstOrCreate(
            ['slug' => 'default'],
            ['name' => 'Default Workspace', 'owner_id' => $owner->id, 'plan' => WorkspacePlan::Internal],
        );

        $workspace->addMember($owner, WorkspaceRole::Owner);
    }
}
