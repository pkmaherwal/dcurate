<?php

namespace App\Services\Dedupe;

use App\Models\Item;
use Illuminate\Support\Str;

/**
 * Groups items that report the same story (e.g. one announcement covered by five sites).
 *
 * The first item of a story becomes the cluster id; later matches point at it.
 */
class StoryClusterer
{
    protected const JACCARD_THRESHOLD = 0.6;

    protected const KEY_TERM_THRESHOLD = 0.8;

    protected const CONTAINED_KEY_TERM_THRESHOLD = 0.75;

    /**
     * Capitalized words that appear in templated headlines and say nothing about which story it is.
     */
    protected const GENERIC_TERMS = [
        'live', 'tv', 'the', 'a', 'an', 'in', 'on', 'at', 'of', 'and', 'vs', 'v', 'to', 'for', 'with', 'from', 'watch',
        'news', 'update', 'updates', 'preview', 'prediction', 'highlights', 'video', 'report', 'how', 'when', 'where', 'what',
        'who', 'why', 'full', 'list', 'confirmed', 'match', 'today', 'latest', 'breaking', 'official', 'here', 'is', 'are',
    ];

    public function assign(Item $item): void
    {
        if (mb_strlen(static::normalize($item->title)) < 15) {
            return;
        }

        $candidates = Item::query()
            ->where('campaign_id', $item->campaign_id)
            ->whereKeyNot($item->id)
            ->where('created_at', '>=', now()->subDays((int) config('curation.dedupe.cluster_window_days', 7)))
            ->latest('id')
            ->limit(300)
            ->get(['id', 'title', 'cluster_id']);

        foreach ($candidates as $candidate) {
            if (! static::sameStory($item->title, $candidate->title)) {
                continue;
            }

            if ($candidate->cluster_id === null) {
                $candidate->forceFill(['cluster_id' => $candidate->id])->saveQuietly();
            }

            $item->forceFill(['cluster_id' => $candidate->cluster_id ?? $candidate->id])->saveQuietly();

            return;
        }
    }

    /**
     * Whether two headlines report the same story.
     *
     * Deliberately conservative: a wrong merge hides an item from reviewers, a missed one only shows a duplicate.
     * The wording must be similar, the numbers (dates, days, scores, years) must agree, and one headline's
     * distinctive names must be contained in the other's (or overlap strongly). That keeps templated headlines
     * like "Turkey vs Italy LIVE…" / "Belgium vs France LIVE…" or "Day 9" / "Day 10" schedules apart.
     */
    public static function sameStory(string $titleA, string $titleB): bool
    {
        $a = static::normalize($titleA);
        $b = static::normalize($titleB);

        similar_text($a, $b, $percent);

        $similarWording = $percent / 100 >= (float) config('curation.dedupe.title_similarity', 0.82)
            || static::jaccard(static::words($a), static::words($b)) >= self::JACCARD_THRESHOLD;

        if (! $similarWording) {
            return false;
        }

        $numbersA = static::numbers($a);
        $numbersB = static::numbers($b);

        if ($numbersA !== [] && $numbersB !== [] && array_diff($numbersA, $numbersB) !== array_diff($numbersB, $numbersA)) {
            return false;
        }

        $keyA = static::keyTerms($titleA);
        $keyB = static::keyTerms($titleB);
        $overlap = static::jaccard($keyA, $keyB);

        // One headline adding a name ("… - The Hindu") is fine; a vague headline that only shares the
        // series name ("WPL 2027 … by all teams") must not swallow every specific one.
        $contained = array_diff($keyA, $keyB) === [] || array_diff($keyB, $keyA) === [];

        return $overlap >= self::KEY_TERM_THRESHOLD || ($contained && $overlap >= self::CONTAINED_KEY_TERM_THRESHOLD);
    }

    /**
     * @return list<string>
     */
    protected static function numbers(string $normalized): array
    {
        preg_match_all('/\p{N}+/u', $normalized, $matches);

        return array_values(array_unique($matches[0]));
    }

    public static function normalize(string $title): string
    {
        $title = Str::lower(html_entity_decode($title));
        $title = preg_replace('/\s+[-|–—]\s+[^-|–—]{2,40}$/u', '', $title) ?? $title; // " - Publisher"
        $title = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title) ?? $title;

        return Str::squish($title);
    }

    /**
     * Capitalized words and numbers: the names and dates that identify a story.
     *
     * @return list<string>
     */
    protected static function keyTerms(string $title): array
    {
        preg_match_all('/\b(\p{Lu}[\p{L}\p{N}\'’-]*|\p{N}[\p{N}\/-]*)/u', html_entity_decode($title), $matches);

        return array_values(array_unique(array_filter(
            array_map(fn (string $term) => Str::lower(trim($term, "'’-")), $matches[1]),
            fn (string $term) => $term !== '' && ! in_array($term, self::GENERIC_TERMS, true),
        )));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    protected static function jaccard(array $a, array $b): float
    {
        $union = count(array_unique([...$a, ...$b]));

        return $union === 0 ? 0.0 : count(array_intersect($a, $b)) / $union;
    }

    /**
     * @return list<string>
     */
    protected static function words(string $normalized): array
    {
        return array_values(array_unique(array_filter(
            explode(' ', $normalized),
            fn (string $word) => mb_strlen($word) > 2,
        )));
    }
}
