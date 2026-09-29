<?php

namespace App\Ai\Agents;

use App\Ai\AiRouting;
use App\Models\Source;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;

/**
 * Searches the web (OpenAI web search) for the latest items matching a source's query.
 *
 * Results are taken from the response's citations, i.e. the real pages the search returned,
 * never from URLs the model writes itself.
 */
#[Timeout(120)]
class WebSearchDiscoverer implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    public function __construct(public Source $source)
    {
        //
    }

    /**
     * Web search always runs on OpenAI: Gemini's Search grounding terms forbid storing results.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return ['openai' => (string) $this->source->workspace->setting('ai.web_search_model', 'gpt-5.4-nano')];
    }

    public function instructions(): string
    {
        $campaign = $this->source->campaign;
        $days = min($campaign->freshness_days, 14);
        $today = now($this->source->workspace->setting('timezone'))->toDateString();

        return <<<PROMPT
        You find the latest news for the curated feed "{$campaign->name}". Today is {$today}; region: {$campaign->region}.
        Feed brief: {$campaign->ai_brief}

        Search the web for pages published in the last {$days} days that match the request.
        Prefer official sources (federations, associations, clubs, organizers) and reputable news sites.
        Skip homepages, search result pages, social media profiles, and anything older than {$days} days.
        Answer with a short bullet list: one line per distinct item, each citing its source page.
        PROMPT;
    }

    /**
     * @return array<int, WebSearch>
     */
    public function tools(): iterable
    {
        $search = (new WebSearch)->max(5)->location(country: $this->source->campaign->region);

        $domains = (array) $this->source->option('allowed_domains', []);

        return [$domains === [] ? $search : $search->allow($domains)];
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return AiRouting::options($provider, $this->provider()['openai']);
    }
}
