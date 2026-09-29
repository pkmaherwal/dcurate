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
     * Ordered provider => model map; the SDK fails over down the list.
     *
     * @return array<string, string>
     */
    public static function chain(Workspace $workspace, ?string $model = null): array
    {
        $provider = (string) $workspace->setting('ai.provider', 'openai');
        $chain = [$provider => $model ?? (string) $workspace->setting('ai.model')];

        $fallback = (string) $workspace->setting('ai.fallback_provider');

        if ($fallback !== '' && $fallback !== $provider && filled(config("ai.providers.{$fallback}.key"))) {
            $chain[$fallback] = (string) $workspace->setting('ai.fallback_model');
        }

        return $chain;
    }

    /**
     * Whether the workspace's primary AI provider has an API key.
     */
    public static function configured(Workspace $workspace): bool
    {
        return filled(config('ai.providers.'.$workspace->setting('ai.provider', 'openai').'.key'));
    }

    /**
     * Request options per provider. GPT-5.x models reason by default; curation needs none.
     *
     * @return array<string, mixed>
     */
    public static function options(Lab|string $provider, string $model): array
    {
        $provider = $provider instanceof Lab ? $provider->value : $provider;

        if ($provider !== 'openai' || ! str_starts_with($model, 'gpt-5')) {
            return [];
        }

        // The original GPT-5 family's lowest effort is "minimal"; 5.1+ supports "none".
        return ['reasoning' => ['effort' => preg_match('/^gpt-5-(nano|mini)$|^gpt-5$/', $model) ? 'minimal' : 'none']];
    }
}
