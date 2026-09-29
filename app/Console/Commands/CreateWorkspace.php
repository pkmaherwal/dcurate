<?php

namespace App\Console\Commands;

use App\Enums\WorkspacePlan;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('workspace:create {name : Workspace name} {email : Owner email} {--plan=internal : internal, free or pro}')]
#[Description('Create a workspace and its owner (creates the user when needed)')]
class CreateWorkspace extends Command
{
    public function handle(): int
    {
        $plan = WorkspacePlan::tryFrom((string) $this->option('plan'));

        if ($plan === null) {
            $this->components->error('Unknown plan. Use internal, free or pro.');

            return self::FAILURE;
        }

        $email = Str::lower((string) $this->argument('email'));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $user = User::create([
                'name' => text('Owner name', required: true),
                'email' => $email,
                'password' => password('Owner password', required: true),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $workspace = Workspace::create([
            'name' => (string) $this->argument('name'),
            'owner_id' => $user->id,
            'plan' => $plan,
        ]);

        $workspace->addMember($user, WorkspaceRole::Owner);

        $this->components->info("Workspace [{$workspace->name}] ({$workspace->slug}) created with owner {$user->email}.");

        return self::SUCCESS;
    }
}
