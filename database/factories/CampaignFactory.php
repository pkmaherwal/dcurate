<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            ...Campaign::templateAttributes('sports_tournaments'),
            'keywords' => ['football tournament', 'India'],
            'region' => 'IN',
            'language' => 'en-IN',
            'freshness_days' => 30,
            'status' => CampaignStatus::Active,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => CampaignStatus::Paused]);
    }
}
