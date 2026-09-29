<?php

namespace App\Sources\Adapters;

use App\Models\Source;
use App\Sources\Contracts\SourceAdapter;
use App\Sources\FetchResult;
use App\Sources\Instagram\ApifyDriver;
use App\Sources\Instagram\BusinessDiscoveryDriver;
use App\Sources\Instagram\InstagramDriver;
use App\Sources\Instagram\InstagramUrl;
use App\Sources\RawItem;
use App\Sources\Support\Text;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Public Instagram accounts. Captions are often just hashtags, so the AI also reads the post image.
 */
class InstagramAdapter implements SourceAdapter
{
    public function __construct(protected Container $container)
    {
        //
    }

    public function prepare(Source $source): void
    {
        $username = InstagramUrl::username($source->target);
        $account = $this->driver($source)->recentPosts($username, 1);

        $source->target = $username;
        $source->name = $source->name ?: ($account['name'] ? "{$account['name']} (@{$username})" : "@{$username}");
    }

    public function fetch(Source $source, bool $conditional = true): FetchResult
    {
        $username = InstagramUrl::username($source->target);
        $items = [];

        foreach ($this->driver($source)->recentPosts($username)['posts'] as $post) {
            if ($post['permalink'] === '') {
                continue;
            }

            $caption = Text::clean($post['caption']);

            $items[] = new RawItem(
                url: $post['permalink'],
                title: $this->title($caption, $username),
                externalId: $post['shortcode'] ? "ig:{$post['shortcode']}" : null,
                excerpt: $caption ? Str::limit($caption, 2000) : null,
                publishedAt: $post['timestamp'] ? Carbon::parse($post['timestamp']) : null,
                author: "@{$username}",
                media: array_filter([
                    'image' => $post['image'],
                    'embed' => 'instagram',
                    'shortcode' => $post['shortcode'],
                    'publisher' => ['name' => "@{$username}", 'url' => "https://www.instagram.com/{$username}/"],
                ]),
            );
        }

        return new FetchResult(items: $items);
    }

    protected function driver(Source $source): InstagramDriver
    {
        $driver = $source->option('driver') ?: config('curation.instagram.driver', 'official');

        return $this->container->make($driver === 'apify' ? ApifyDriver::class : BusinessDiscoveryDriver::class);
    }

    /**
     * First meaningful line of the caption (without hashtags), or a placeholder the AI replaces.
     */
    protected function title(?string $caption, string $username): string
    {
        $line = Str::of((string) $caption)
            ->explode("\n")
            ->map(fn (string $line) => trim(preg_replace('/(^|\s)#\S+/u', ' ', $line) ?? ''))
            ->first(fn (string $line) => mb_strlen($line) >= 8);

        return $line ? Text::title($line, 150) : "Instagram post by @{$username}";
    }
}
