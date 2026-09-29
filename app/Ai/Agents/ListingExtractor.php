<?php

namespace App\Ai\Agents;

use App\Ai\AiRouting;
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
 * Reads a website's news/announcements listing page and returns the actual entries on it.
 */
#[Strict]
#[Timeout(90)]
class ListingExtractor implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function __construct(public Workspace $workspace)
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
        return <<<'PROMPT'
        You extract the entries from a website's news or announcements listing page.
        - Return only real entries: news, announcements, notices, circulars, fixtures, results, press releases.
        - Ignore navigation, menus, category/tag/author pages, social links, pagination, login pages and ads.
        - Copy each entry's URL exactly from the provided list of links. Never invent or modify URLs.
        - published_at: the date shown next to the entry as YYYY-MM-DD, or null when no date is shown.
        - Keep page order (usually newest first). At most 30 entries.
        PROMPT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object([
                'title' => $schema->string()->description('The entry headline as shown')->required(),
                'url' => $schema->string()->description('The exact entry URL from the links list')->required(),
                'published_at' => $schema->string()->nullable()->description('YYYY-MM-DD or null')->required(),
            ]))->required(),
        ];
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
