<?php

namespace App\Sources\Instagram;

use App\Sources\SourceException;

class InstagramUrl
{
    /**
     * "@user", "user" or "https://www.instagram.com/user/" → "user".
     *
     * @throws SourceException
     */
    public static function username(string $target): string
    {
        $target = trim($target);

        if (preg_match('#instagram\.com/([A-Za-z0-9._]+)#i', $target, $match)) {
            $target = $match[1];
        }

        $username = ltrim($target, '@');

        if (! preg_match('/^[A-Za-z0-9._]{1,30}$/', $username) || in_array($username, ['p', 'reel', 'explore', 'stories'], true)) {
            throw new SourceException(__('Enter an Instagram username, e.g. indianfootball.'));
        }

        return strtolower($username);
    }

    public static function shortcode(string $permalink): ?string
    {
        return preg_match('#instagram\.com/(?:p|reel|tv)/([A-Za-z0-9_-]+)#', $permalink, $match) ? $match[1] : null;
    }
}
