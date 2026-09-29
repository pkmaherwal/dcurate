<?php

namespace App\Enums;

enum SourceType: string
{
    case Rss = 'rss';
    case Website = 'website';
    case YouTube = 'youtube';
    case GoogleNews = 'google_news';
    case WebSearch = 'web_search';
    case Instagram = 'instagram';

    public function label(): string
    {
        return match ($this) {
            self::Rss => __('RSS / Blog / News site'),
            self::Website => __('Website (no RSS)'),
            self::YouTube => __('YouTube channel'),
            self::GoogleNews => __('Google News search'),
            self::WebSearch => __('AI web search'),
            self::Instagram => __('Instagram account'),
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Rss => 'RSS',
            self::Website => __('Website'),
            self::YouTube => 'YouTube',
            self::GoogleNews => __('Google News'),
            self::WebSearch => __('Web search'),
            self::Instagram => 'Instagram',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Rss => 'rss',
            self::Website => 'globe-alt',
            self::YouTube => 'play-circle',
            self::GoogleNews => 'newspaper',
            self::WebSearch => 'magnifying-glass',
            self::Instagram => 'camera',
        };
    }

    /**
     * Help text for the "target" field when adding a source.
     */
    public function targetHint(): string
    {
        return match ($this) {
            self::Rss => __('Feed URL or the site URL (the feed is auto-discovered). RSSHub routes work too.'),
            self::Website => __('URL of the news / announcements listing page.'),
            self::YouTube => __('Channel URL, @handle or channel ID (UC…).'),
            self::GoogleNews => __('Search query, e.g. "football tournament" Kerala.'),
            self::WebSearch => __('What to search for, in plain language.'),
            self::Instagram => __('Instagram username (Business or Creator account).'),
        };
    }

    /**
     * Default minutes between fetches for a new source of this type.
     */
    public function defaultInterval(): int
    {
        return match ($this) {
            self::Rss, self::YouTube, self::GoogleNews => 60,
            self::Website, self::Instagram => 120,
            self::WebSearch => 360,
        };
    }

    public function isUrlBased(): bool
    {
        return in_array($this, [self::Rss, self::Website], true);
    }
}
