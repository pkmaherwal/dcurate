<?php

namespace App\Ai;

use App\Models\Campaign;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Builds the AI's structured-output schema from a campaign's own categories and fields,
 * so nothing niche-specific is hard-coded.
 *
 * Every property is required (OpenAI strict mode); optional values are nullable instead.
 */
class CampaignSchemaBuilder
{
    /**
     * @return array<string, Type>
     */
    public static function enrichment(Campaign $campaign, JsonSchema $schema): array
    {
        $properties = [
            'relevance' => $schema->integer()->description('0 = unrelated to the brief, 100 = exactly what the brief asks for')->required(),
            'reason' => $schema->string()->description('One short sentence explaining the relevance score')->required(),
            'title' => $schema->string()->description('Clear, factual English headline, at most 110 characters, no emojis or hashtags')->required(),
            'summary' => $schema->string()->description('2-3 factual English sentences with the key facts: what, who, when, where')->required(),
            'language' => $schema->string()->description('ISO 639-1 code of the original text, e.g. "en", "hi", "ml"')->required(),
            'category' => $schema->string()->enum(static::categories($campaign))->description('The best matching category')->required(),
        ];

        if ($fields = static::fields($campaign, $schema)) {
            $properties['fields'] = $schema->object($fields)->description('Details stated in the item; null when not stated')->required();
        }

        return $properties;
    }

    /**
     * @return array<string, Type>
     */
    public static function relevance(JsonSchema $schema): array
    {
        return [
            'relevance' => $schema->integer()->description('0 = unrelated to the brief, 100 = exactly what the brief asks for')->required(),
            'reason' => $schema->string()->description('One short sentence explaining the score')->required(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function categories(Campaign $campaign): array
    {
        $categories = array_values(array_filter($campaign->categories ?? [], fn (string $category) => trim($category) !== ''));

        return $categories === [] ? ['Other'] : $categories;
    }

    /**
     * @return array<string, Type>
     */
    protected static function fields(Campaign $campaign, JsonSchema $schema): array
    {
        $fields = [];

        foreach ($campaign->extraction_fields ?? [] as $field) {
            $hint = trim(($field['description'] ?? '') ?: $field['label']);

            $fields[$field['key']] = match ($field['type']) {
                'date' => $schema->string()->nullable()->description("{$hint} (YYYY-MM-DD)"),
                'number' => $schema->number()->nullable()->description($hint),
                'list' => $schema->array()->items($schema->string())->nullable()->description($hint),
                default => $schema->string()->nullable()->description($hint),
            };

            $fields[$field['key']]->required();
        }

        return $fields;
    }
}
