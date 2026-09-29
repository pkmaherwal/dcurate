<?php

namespace App\Ai\Agents;

use App\Ai\AiRouting;
use App\Ai\CampaignSchemaBuilder;
use App\Models\Campaign;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * A cheap relevance-only pass (e.g. gpt-5-nano) run before full enrichment.
 */
#[Strict]
#[Timeout(30)]
class RelevancePrefilter implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(public Campaign $campaign, public Workspace $workspace)
    {
        //
    }

    /**
     * @return array<string, string>
     */
    public function provider(): array
    {
        return AiRouting::chain($this->workspace, (string) $this->workspace->setting('ai.prefilter_model'));
    }

    public function instructions(): string
    {
        $brief = $this->campaign->ai_brief ?: "Updates about: {$this->campaign->name}.";

        return "Score how relevant the item is to this brief (0-100). Be strict: off-topic, promotional or unrelated items score below 30.\n\nBrief:\n{$brief}";
    }

    public function schema(JsonSchema $schema): array
    {
        return CampaignSchemaBuilder::relevance($schema);
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiRouting::options($provider, $this->provider()[$key] ?? '');
    }
}
