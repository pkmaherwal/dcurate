<?php

namespace App\Sources\Support;

use Illuminate\Support\Str;

/**
 * Small helpers for turning feed/HTML fragments into clean plain text.
 */
class Text
{
    public static function clean(?string $value, ?int $limit = null): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/div|\/li)[^>]*>/i', "\n", $value) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace("/[ \t\x{00A0}]+/u", ' ', $value) ?? '';
        $value = trim(preg_replace("/\n\s*\n+/", "\n\n", $value) ?? '');

        if ($value === '') {
            return null;
        }

        return $limit ? Str::limit($value, $limit) : $value;
    }

    /**
     * A single-line title.
     */
    public static function title(?string $value, int $limit = 480): string
    {
        return Str::limit(Str::squish((string) self::clean($value)), $limit);
    }
}
