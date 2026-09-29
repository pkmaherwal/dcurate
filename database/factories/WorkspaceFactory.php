<?php

namespace Database\Factories;

use App\Enums\WorkspacePlan;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'plan' => WorkspacePlan::Internal,
            'status' => 'active',
        ];
    }

    public function plan(WorkspacePlan $plan): static
    {
        return $this->state(fn () => ['plan' => $plan]);
    }
}
