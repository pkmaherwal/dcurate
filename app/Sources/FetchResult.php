<?php

namespace App\Sources;

/**
 * What a source adapter returns: new raw items plus any state to remember on the source.
 */
final class FetchResult
{
    /**
     * @param  list<RawItem>  $items
     * @param  array<string, mixed>  $sourceUpdates  Columns to update on the source (etag, last_modified, content_hash).
     * @param  array<string, mixed>  $configUpdates  Values to merge into the source's config column.
     */
    public function __construct(
        public array $items = [],
        public bool $notModified = false,
        public array $sourceUpdates = [],
        public array $configUpdates = [],
    ) {
        //
    }

    /**
     * @param  array<string, mixed>  $sourceUpdates
     */
    public static function notModified(array $sourceUpdates = []): self
    {
        return new self(notModified: true, sourceUpdates: $sourceUpdates);
    }
}
