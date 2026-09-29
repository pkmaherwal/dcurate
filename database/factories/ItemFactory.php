<?php

namespace Database\Factories;

use App\Enums\AiStatus;
use App\Enums\ItemStatus;
use App\Models\Item;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->unique()->url();

        return [
            'source_id' => Source::factory(),
            'campaign_id' => fn (array $attributes) => Source::acrossWorkspaces()->whereKey($attributes['source_id'])->value('campaign_id'),
            'workspace_id' => fn (array $attributes) => Source::acrossWorkspaces()->whereKey($attributes['source_id'])->value('workspace_id'),
            'url' => $url,
            'canonical_url' => $url,
            'url_hash' => hash('sha256', $url),
            'title' => fake()->sentence(8),
            'excerpt' => fake()->paragraph(),
            'published_at' => now()->subHours(fake()->numberBetween(1, 48)),
            'fetched_at' => now(),
            'ai_status' => AiStatus::Pending,
            'status' => ItemStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => ItemStatus::Approved, 'reviewed_at' => now()]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => ['status' => ItemStatus::Rejected, 'reviewed_at' => now()]);
    }

    public function enriched(int $relevance = 80): static
    {
        return $this->state(fn () => [
            'ai_status' => AiStatus::Done,
            'ai_title' => fake()->sentence(6),
            'ai_summary' => fake()->paragraph(),
            'ai_category' => 'Announcement',
            'ai_relevance' => $relevance,
            'ai_fields' => ['tournament' => 'Santosh Trophy', 'city' => 'Kochi'],
            'enriched_at' => now(),
        ]);
    }
}
