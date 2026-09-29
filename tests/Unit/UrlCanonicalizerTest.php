<?php

namespace Tests\Unit;

use App\Sources\Support\UrlCanonicalizer;
use PHPUnit\Framework\TestCase;

class UrlCanonicalizerTest extends TestCase
{
    public function test_tracking_parameters_fragments_and_trailing_slashes_are_removed(): void
    {
        $urls = new UrlCanonicalizer;

        $this->assertSame(
            'https://example.com/news/item?id=7&page=2',
            $urls->canonicalize('HTTPS://Example.com:443/news/item/?utm_source=x&page=2&id=7&fbclid=abc#comments'),
        );
    }

    public function test_the_same_article_hashes_the_same(): void
    {
        $urls = new UrlCanonicalizer;

        $this->assertSame(
            $urls->hash('https://example.com/a?utm_medium=rss'),
            $urls->hash('https://example.com/a/'),
        );
    }

    public function test_relative_urls_are_resolved(): void
    {
        $urls = new UrlCanonicalizer;

        $this->assertSame('https://kfa.org/feed/', $urls->absolutize('/feed/', 'https://kfa.org/news/today'));
        $this->assertSame('https://kfa.org/news/item-2', $urls->absolutize('item-2', 'https://kfa.org/news/today'));
        $this->assertSame('https://cdn.kfa.org/x.jpg', $urls->absolutize('//cdn.kfa.org/x.jpg', 'https://kfa.org/'));
    }
}
