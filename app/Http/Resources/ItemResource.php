<?php

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An approved item as your website receives it.
 *
 * @mixin Item
 */
class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $publisher = data_get($this->media, 'publisher');

        return [
            'id' => $this->id,
            'title' => $this->displayTitle(),
            'summary' => $this->displaySummary(),
            'category' => $this->ai_category,
            'fields' => (object) array_filter($this->ai_fields ?? [], fn ($value) => filled($value)),
            'url' => $this->url,
            // Instagram posts are shown through the official embed, not a re-hosted copy of the image.
            'image' => data_get($this->media, 'embed') === 'instagram' ? null : $this->imageUrl(),
            'embed' => match (data_get($this->media, 'embed')) {
                'youtube' => ['type' => 'youtube', 'id' => data_get($this->media, 'video_id')],
                'instagram' => ['type' => 'instagram', 'id' => data_get($this->media, 'shortcode'), 'url' => $this->url],
                default => null,
            },
            'featured' => $this->is_featured,
            'published_at' => $this->published_at?->toIso8601String(),
            'approved_at' => $this->reviewed_at?->toIso8601String(),
            'source' => [
                'name' => data_get($publisher, 'name') ?? $this->source?->name,
                'type' => $this->source?->type->value,
                'url' => data_get($publisher, 'url') ?? $this->source?->publicUrl(),
            ],
        ];
    }
}
