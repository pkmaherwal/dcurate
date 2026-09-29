<?php

namespace App\Sources\Instagram;

use App\Sources\SourceException;
use App\Sources\Support\HttpClient;

/**
 * Apify "Instagram Scraper" actor: works for any public account (paid per result).
 */
class ApifyDriver implements InstagramDriver
{
    public function __construct(protected HttpClient $http)
    {
        //
    }

    public function recentPosts(string $username, int $limit = 20): array
    {
        $token = config('curation.instagram.apify.token');

        if (blank($token)) {
            throw new SourceException(__('Set APIFY_TOKEN to use the Apify Instagram driver.'));
        }

        $response = $this->http->request()
            ->timeout(150)
            ->withToken((string) $token)
            ->post(sprintf('https://api.apify.com/v2/acts/%s/run-sync-get-dataset-items', config('curation.instagram.apify.actor')), [
                'directUrls' => ["https://www.instagram.com/{$username}/"],
                'resultsType' => 'posts',
                'resultsLimit' => $limit,
                'addParentData' => false,
            ]);

        if (! $response->successful()) {
            throw new SourceException('Apify: '.$response->json('error.message', 'HTTP '.$response->status()));
        }

        $posts = [];
        $name = null;

        foreach ((array) $response->json() as $post) {
            if (! is_array($post) || isset($post['error'])) {
                continue;
            }

            $name ??= $post['ownerFullName'] ?? null;

            $posts[] = [
                'permalink' => (string) ($post['url'] ?? ''),
                'caption' => $post['caption'] ?? null,
                'timestamp' => $post['timestamp'] ?? null,
                'image' => $post['displayUrl'] ?? null,
                'shortcode' => $post['shortCode'] ?? InstagramUrl::shortcode((string) ($post['url'] ?? '')),
            ];
        }

        return ['name' => $name, 'posts' => $posts];
    }
}
