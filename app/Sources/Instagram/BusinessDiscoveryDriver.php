<?php

namespace App\Sources\Instagram;

use App\Sources\SourceException;
use App\Sources\Support\HttpClient;

/**
 * Official Instagram Graph API "Business Discovery": reads other Business/Creator accounts
 * through your own Instagram professional account (linked to a Facebook Page).
 */
class BusinessDiscoveryDriver implements InstagramDriver
{
    public function __construct(protected HttpClient $http)
    {
        //
    }

    public function recentPosts(string $username, int $limit = 20): array
    {
        $igUserId = config('curation.instagram.graph.ig_user_id');
        $token = config('curation.instagram.graph.access_token');

        if (blank($igUserId) || blank($token)) {
            throw new SourceException(__('Instagram is not connected. Set INSTAGRAM_IG_USER_ID and INSTAGRAM_ACCESS_TOKEN (or use the Apify driver).'));
        }

        $fields = sprintf(
            'business_discovery.username(%s){username,name,media.limit(%d){caption,media_type,media_url,thumbnail_url,permalink,timestamp,children{media_type,media_url}}}',
            $username,
            $limit,
        );

        $response = $this->http->request()->get(
            sprintf('https://graph.facebook.com/%s/%s', config('curation.instagram.graph.version', 'v25.0'), $igUserId),
            ['fields' => $fields, 'access_token' => $token],
        );

        if (! $response->successful()) {
            $message = (string) $response->json('error.message', 'HTTP '.$response->status());

            throw new SourceException(str_contains($message, 'business')
                ? __('@:user is not a Business or Creator account. Use the Apify driver for personal accounts.', ['user' => $username])
                : 'Instagram Graph API: '.$message);
        }

        $posts = [];

        foreach ((array) $response->json('business_discovery.media.data', []) as $media) {
            $image = match ($media['media_type'] ?? null) {
                'VIDEO' => $media['thumbnail_url'] ?? null,
                'CAROUSEL_ALBUM' => $this->firstImage((array) data_get($media, 'children.data', [])) ?? ($media['media_url'] ?? null),
                default => $media['media_url'] ?? null,
            };

            $posts[] = [
                'permalink' => (string) ($media['permalink'] ?? ''),
                'caption' => $media['caption'] ?? null,
                'timestamp' => $media['timestamp'] ?? null,
                'image' => $image,
                'shortcode' => InstagramUrl::shortcode((string) ($media['permalink'] ?? '')),
            ];
        }

        return ['name' => $response->json('business_discovery.name'), 'posts' => $posts];
    }

    /**
     * @param  array<int, mixed>  $children
     */
    protected function firstImage(array $children): ?string
    {
        foreach ($children as $child) {
            if (is_array($child) && ($child['media_type'] ?? null) === 'IMAGE' && is_string($child['media_url'] ?? null)) {
                return $child['media_url'];
            }
        }

        return null;
    }
}
