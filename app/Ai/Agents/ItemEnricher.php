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
 * Scores, summarizes, categorizes and extracts fields for one item, using the campaign's own AI settings.
 */
#[Strict]
#[Timeout(60)]
class ItemEnricher implements Agent, HasProviderOptions, HasStructuredOutput
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
        return AiRouting::chain($this->workspace);
    }

    public function instructions(): string
    {
        $brief = $this->campaign->ai_brief ?: "Updates about: {$this->campaign->name}.";
        $keywords = implode(', ', $this->campaign->keywords ?? []);
        $today = now($this->workspace->setting('timezone'))->toDateString();

        return <<<PROMPT
        You are a careful news editor curating the "{$this->campaign->name}" feed.
        Today is {$today}. Audience region: {$this->campaign->region}.

        What the feed is about (the brief):
        {$brief}
        Keywords: {$keywords}

        For the item you receive:
        - Score relevance against the brief: 80-100 clearly on-topic and newsworthy, 40-79 related but weaker, 0-39 off-topic, promotional, or not about the brief.
        - Old news, generic evergreen articles and pages without real news score lower.
        - Write the title and summary in English (translate if needed). Be factual and neutral; never invent facts.
        - Fill every field only from what the item states; use null when it is not stated. Dates as YYYY-MM-DD.
        - If an image is attached (for example a tournament poster), read its text as part of the item.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return CampaignSchemaBuilder::enrichment($this->campaign, $schema);
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $key = $provider instanceof Lab ? $provider->value : $provider;

        return AiRouting::options($provider, AiRouting::chain($this->workspace)[$key] ?? '');
    }
}
