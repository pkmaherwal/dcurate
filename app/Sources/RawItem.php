<?php

namespace App\Sources;

use Carbon\CarbonInterface;

/**
 * An item as found by a source adapter, before it is stored.
 */
final readonly class RawItem
{
    /**
     * @param  array{image?: string|null, video_id?: string|null, embed?: string|null, publisher?: array{name?: string|null, url?: string|null}}  $media
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $url,
        public string $title,
        public ?string $externalId = null,
        public ?string $excerpt = null,
        public ?string $content = null,
        public ?CarbonInterface $publishedAt = null,
        public ?string $author = null,
        public array $media = [],
        public array $meta = [],
    ) {
        //
    }
}
