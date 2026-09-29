<?php

namespace App\Services\Extractor;

use App\Sources\SourceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the Python extractor sidecar (extractor/): headless rendering + article/date extraction.
 */
class ExtractorClient
{
    protected const DOWN_KEY = 'extractor:down';

    public function enabled(): bool
    {
        return filled(config('curation.extractor.url')) && ! Cache::has(self::DOWN_KEY);
    }

    /**
     * @param  'article'|'listing'  $mode
     * @return array{url: string, title: string|null, text: string|null, markdown: string|null, published_at: string|null, image: string|null, links: list<array{url: string, text: string}>, content_hash: string|null, html: string|null}
     *
     * @throws SourceException
     */
    public function extract(string $url, string $mode = 'article', bool $renderJs = false, bool $includeHtml = false): array
    {
        try {
            $response = Http::baseUrl((string) config('curation.extractor.url'))
                ->withToken((string) config('curation.extractor.token'))
                ->timeout((int) config('curation.extractor.timeout', 90))
                ->connectTimeout(3)
                ->acceptJson()
                ->post('/extract', ['url' => $url, 'mode' => $mode, 'render_js' => $renderJs, 'include_html' => $includeHtml]);
        } catch (ConnectionException $e) {
            // Don't hammer a sidecar that is not running; try again in a few minutes.
            Cache::put(self::DOWN_KEY, true, now()->addMinutes(5));

            throw new ExtractorUnavailable('The extractor service is not reachable at '.config('curation.extractor.url').'.', previous: $e);
        }

        if (! $response->successful()) {
            throw new SourceException('Extractor: '.($response->json('detail') ?? 'HTTP '.$response->status()));
        }

        $string = fn (string $key): ?string => is_string($value = $response->json($key)) && $value !== '' ? $value : null;
        $links = [];

        foreach ((array) $response->json('links', []) as $link) {
            if (is_array($link) && is_string($link['url'] ?? null)) {
                $links[] = ['url' => $link['url'], 'text' => is_string($link['text'] ?? null) ? $link['text'] : ''];
            }
        }

        return [
            'url' => $string('url') ?? $url,
            'title' => $string('title'),
            'text' => $string('text'),
            'markdown' => $string('markdown'),
            'published_at' => $string('published_at'),
            'image' => $string('image'),
            'links' => $links,
            'content_hash' => $string('content_hash'),
            'html' => $string('html'),
        ];
    }
}
