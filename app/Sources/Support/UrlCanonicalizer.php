<?php

namespace App\Sources\Support;

use Illuminate\Support\Str;

/**
 * Normalizes URLs so the same article found twice is stored once.
 */
class UrlCanonicalizer
{
    /**
     * Query parameters that only track clicks and never change the page.
     */
    protected const TRACKING_PARAMS = [
        'fbclid', 'gclid', 'dclid', 'msclkid', 'igshid', 'igsh', 'mc_cid', 'mc_eid', '_ga', 'ref', 'ref_src',
        'si', 'feature', 'cmpid', 'ito', 'ncid', 'ocid', 'ns_mchannel', 'ns_source', 'ns_campaign', 'yclid',
    ];

    public function canonicalize(string $url): string
    {
        $url = trim(html_entity_decode($url));
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }

        $scheme = Str::lower($parts['scheme'] ?? 'https');
        $host = Str::lower($parts['host']);
        $port = isset($parts['port']) && ! in_array($parts['port'], [80, 443], true) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/';

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $query = '';

        if (isset($parts['query'])) {
            parse_str($parts['query'], $params);

            $params = array_filter(
                $params,
                fn ($key) => ! Str::startsWith(Str::lower((string) $key), 'utm_') && ! in_array(Str::lower((string) $key), self::TRACKING_PARAMS, true),
                ARRAY_FILTER_USE_KEY,
            );

            ksort($params);
            $query = $params === [] ? '' : '?'.http_build_query($params);
        }

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    public function hash(string $url): string
    {
        return hash('sha256', $this->canonicalize($url));
    }

    /**
     * Resolve a possibly relative URL against a base URL.
     */
    public function absolutize(string $url, string $base): string
    {
        $url = trim($url);

        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return $url;
        }

        $baseParts = parse_url($base);
        $origin = ($baseParts['scheme'] ?? 'https').'://'.($baseParts['host'] ?? '').(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        if (str_starts_with($url, '//')) {
            return ($baseParts['scheme'] ?? 'https').':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }

        $dir = preg_replace('#/[^/]*$#', '/', $baseParts['path'] ?? '/');

        return $origin.$dir.$url;
    }
}
