<?php

namespace App\Sources\Support;

use App\Models\Source;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The HTTP client every adapter uses: polite user agent, timeouts, retries and conditional GETs.
 */
class HttpClient
{
    public function request(): PendingRequest
    {
        return Http::withUserAgent((string) config('curation.fetch.user_agent'))
            ->timeout((int) config('curation.fetch.timeout', 20))
            ->connectTimeout(10)
            ->retry(2, 500, throw: false)
            ->withHeaders(['Accept-Language' => 'en-IN,en;q=0.9']);
    }

    /**
     * GET a URL, sending If-None-Match / If-Modified-Since from the source when conditional.
     *
     * @param  array<string, mixed>  $query
     */
    public function get(string $url, ?Source $source = null, bool $conditional = false, array $query = []): Response
    {
        $headers = [];

        if ($conditional && $source !== null) {
            $headers = array_filter([
                'If-None-Match' => $source->etag,
                'If-Modified-Since' => $source->last_modified,
            ]);
        }

        $request = $this->request()->withHeaders($headers);

        // Passing an empty query array would wipe a query string already present in the URL.
        return $query === [] ? $request->get($url) : $request->get($url, $query);
    }

    /**
     * Cache validators from a response, to store on the source.
     *
     * @return array{etag: string|null, last_modified: string|null}
     */
    public function validators(Response $response): array
    {
        return [
            'etag' => $response->header('ETag') ?: null,
            'last_modified' => $response->header('Last-Modified') ?: null,
        ];
    }
}
