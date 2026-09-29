<?php

namespace Database\Factories;

use App\Enums\SourceType;
use App\Models\Campaign;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'workspace_id' => fn (array $attributes) => Campaign::acrossWorkspaces()->whereKey($attributes['campaign_id'])->value('workspace_id'),
            'type' => SourceType::Rss,
            'name' => fake()->company().' News',
            'target' => 'https://'.fake()->domainName().'/feed',
            'config' => [],
            'fetch_interval_minutes' => 60,
            'next_fetch_at' => null,
            'is_active' => true,
        ];
    }

    public function type(SourceType $type, ?string $target = null): static
    {
        return $this->state(fn () => array_filter([
            'type' => $type,
            'target' => $target,
        ]));
    }
}
