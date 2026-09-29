<?php

namespace App\Support;

/**
 * Region and language options for campaigns (used by Google News and the AI prompts).
 */
class Locales
{
    /**
     * @return array<string, string>
     */
    public static function regions(): array
    {
        return [
            'IN' => 'India',
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'AE' => 'United Arab Emirates',
            'AU' => 'Australia',
            'CA' => 'Canada',
            'SG' => 'Singapore',
            'ZA' => 'South Africa',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function languages(): array
    {
        return [
            'en-IN' => 'English (India)',
            'hi-IN' => 'Hindi',
            'ml-IN' => 'Malayalam',
            'ta-IN' => 'Tamil',
            'bn-IN' => 'Bengali',
            'mr-IN' => 'Marathi',
            'en-US' => 'English (US)',
            'en-GB' => 'English (UK)',
        ];
    }
}
