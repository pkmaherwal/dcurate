<?php

namespace Tests\Unit;

use App\Services\Dedupe\StoryClusterer;
use Tests\TestCase;

class StoryClustererTest extends TestCase
{
    public function test_rewordings_of_the_same_story_match(): void
    {
        $this->assertTrue(StoryClusterer::sameStory(
            'Santosh Trophy 2026: Kerala squad announced for group stage',
            'Santosh Trophy 2026 - Kerala squad announced for the group stage - The Hindu',
        ));
    }

    public function test_templated_headlines_about_different_matches_do_not_match(): void
    {
        $this->assertFalse(StoryClusterer::sameStory(
            'Turkey vs Italy LIVE streaming, TV channel, kick-off time & where to watch',
            'Belgium vs France LIVE streaming, TV channel, kick-off time & where to watch',
        ));

        $this->assertFalse(StoryClusterer::sameStory(
            'Somalia vs Ivory Coast Preview, prediction, team news, lineups | AFCON 2027 Qualifiers',
            'Congo vs Cameroon Preview, prediction, team news, lineups | AFCON 2027 Qualifiers',
        ));
    }

    public function test_different_days_and_teams_in_the_same_series_do_not_match(): void
    {
        $this->assertFalse(StoryClusterer::sameStory(
            "Asian Games 2026: India's updated schedule for Day 10 (September 29)",
            "Asian Games 2026: India's updated schedule, results for today, Day 9 (September 28)",
        ));

        $this->assertFalse(StoryClusterer::sameStory(
            'Hockey at Asian Games 2026: Full schedule, fixtures, results, live streaming details',
            'Badminton at Asian Games 2026: Full schedule, fixtures, results, live streaming details',
        ));

        $this->assertFalse(StoryClusterer::sameStory(
            'ISL 2026-27: Punjab FC fixtures for Phase 1',
            'ISL 2026-27: Mohun Bagan fixtures for Phase 1',
        ));
    }

    public function test_a_vague_headline_does_not_absorb_specific_ones(): void
    {
        $this->assertFalse(StoryClusterer::sameStory(
            'WPL 2027: List of players retained & released by all teams',
            'WPL 2027: All players retained & released by Mumbai Indians',
        ));
    }

    public function test_unrelated_stories_do_not_match(): void
    {
        $this->assertFalse(StoryClusterer::sameStory(
            'U-17 district trials in Kochi on Sunday',
            'Durand Cup 2026 quarter-final highlights',
        ));
    }
}
