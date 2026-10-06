<?php

namespace App\Ai;

use App\Models\Workspace;
use Laravel\Ai\Enums\Lab;

/**
 * Picks the provider/model chain and provider options for a workspace's AI calls.
 */
class AiRouting
{
    /**
     * The workspace's provider => model map.
     *
     * @return array<string, string>
     */
    public static function chain(Workspace $workspace): array
    {
        return [(string) $workspace->setting('ai.provider', 'openai') => (string) $workspace->setting('ai.model')];
    }

    /**
     * Whether the workspace's primary AI provider has an API key.
     */
    public static function configured(Workspace $workspace): bool
    {
        return filled(config('ai.providers.'.$workspace->setting('ai.provider', 'openai').'.key'));
    }

    /**
     * Request options per provider. OpenAI models reason by default; the workspace picks the effort.
     *
     * @return array<string, mixed>
     */
    public static function options(Workspace $workspace, Lab|string $provider): array
    {
        $provider = $provider instanceof Lab ? $provider->value : $provider;

        if ($provider !== 'openai') {
            return [];
        }

        return ['reasoning' => ['effort' => (string) $workspace->setting('ai.reasoning_effort', 'none')]];
    }
}
