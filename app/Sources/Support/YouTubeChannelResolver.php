<?php

namespace App\Sources\Support;

use App\Sources\SourceException;
use Illuminate\Support\Str;

/**
 * Turns a channel URL, @handle or channel id into a channel id (UC…) and title.
 */
class YouTubeChannelResolver
{
    public function __construct(protected HttpClient $http)
    {
        //
    }

    /**
     * @return array{channel_id: string, title: string|null}
     *
     * @throws SourceException
     */
    public function resolve(string $target): array
    {
        $target = trim($target);

        if (preg_match('/(UC[\w-]{22})/', $target, $match)) {
            return ['channel_id' => $match[1], 'title' => null];
        }

        $handle = $this->handle($target);

        if ($handle === null) {
            throw new SourceException(__('Enter a YouTube channel URL, @handle or channel ID (UC…).'));
        }

        if ($key = config('curation.youtube.api_key')) {
            $response = $this->http->request()->get('https://www.googleapis.com/youtube/v3/channels', [
                'part' => 'id,snippet',
                'forHandle' => $handle,
                'key' => $key,
            ]);

            if ($id = $response->json('items.0.id')) {
                return ['channel_id' => $id, 'title' => $response->json('items.0.snippet.title')];
            }
        }

        $response = $this->http->get('https://www.youtube.com/'.$handle);
        $html = $response->successful() ? $response->body() : '';

        if (preg_match('#<link rel="canonical" href="https://www\.youtube\.com/channel/(UC[\w-]{22})"#', $html, $match)
            || preg_match('/"(?:channelId|externalId)":"(UC[\w-]{22})"/', $html, $match)) {
            preg_match('/<meta property="og:title" content="([^"]*)"/', $html, $title);

            return ['channel_id' => $match[1], 'title' => isset($title[1]) ? html_entity_decode($title[1]) : null];
        }

        throw new SourceException(__('Could not find the YouTube channel :handle.', ['handle' => $handle]));
    }

    /**
     * Extract "@handle" (or a legacy /c/ or /user/ path) from the target.
     */
    protected function handle(string $target): ?string
    {
        if (Str::startsWith($target, '@')) {
            return $target;
        }

        if (preg_match('#youtube\.com/(@[\w.\-]+)#i', $target, $match)) {
            return $match[1];
        }

        if (preg_match('#youtube\.com/((?:c|user)/[\w.\-]+)#i', $target, $match)) {
            return $match[1];
        }

        return preg_match('/^[\w.\-]{3,}$/', $target) ? '@'.$target : null;
    }
}
