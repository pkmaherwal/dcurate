<?php

namespace App\Sources;

use App\Enums\SourceType;
use App\Models\Source;
use App\Sources\Adapters\GoogleNewsAdapter;
use App\Sources\Adapters\InstagramAdapter;
use App\Sources\Adapters\RssAdapter;
use App\Sources\Adapters\WebSearchAdapter;
use App\Sources\Adapters\WebsiteAdapter;
use App\Sources\Adapters\YouTubeAdapter;
use App\Sources\Contracts\SourceAdapter;
use Illuminate\Contracts\Container\Container;

class SourceAdapterFactory
{
    public function __construct(protected Container $container)
    {
        //
    }

    public function for(Source|SourceType $source): SourceAdapter
    {
        $type = $source instanceof Source ? $source->type : $source;

        return $this->container->make(match ($type) {
            SourceType::Rss => RssAdapter::class,
            SourceType::Website => WebsiteAdapter::class,
            SourceType::YouTube => YouTubeAdapter::class,
            SourceType::GoogleNews => GoogleNewsAdapter::class,
            SourceType::WebSearch => WebSearchAdapter::class,
            SourceType::Instagram => InstagramAdapter::class,
        });
    }
}
