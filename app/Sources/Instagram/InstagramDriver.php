<?php

namespace App\Sources\Instagram;

use App\Sources\SourceException;

interface InstagramDriver
{
    /**
     * Latest posts of a public account, newest first.
     *
     * @return array{name: string|null, posts: list<array{permalink: string, caption: string|null, timestamp: string|null, image: string|null, shortcode: string|null}>}
     *
     * @throws SourceException
     */
    public function recentPosts(string $username, int $limit = 20): array;
}
