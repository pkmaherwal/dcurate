<?php

namespace Database\Seeders;

use App\Enums\SourceType;
use App\Models\Campaign;
use App\Models\Source;
use App\Models\Workspace;
use App\Sources\SourceAdapterFactory;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Demo campaign with real sources. Makes network calls (feed discovery, channel lookup):
 *
 *     php artisan db:seed --class=FootballIndiaSeeder
 */
class FootballIndiaSeeder extends Seeder
{
    public function run(SourceAdapterFactory $adapters, CurrentWorkspace $current): void
    {
        $workspace = Workspace::where('slug', 'default')->first() ?? Workspace::firstOrFail();

        $current->run($workspace, function () use ($adapters) {
            $campaign = Campaign::firstOrCreate(['slug' => 'football-india'], [
                'name' => 'Football tournaments in India',
                'description' => 'Latest football tournament updates across India.',
                ...Campaign::templateAttributes('sports_tournaments'),
                'ai_brief' => 'Football (soccer) tournaments in India at every level: national competitions (Santosh Trophy, Durand Cup, I-League, ISL, IWL), '
                    .'state and district leagues, age-group (U-13 to U-21), school, college and corporate tournaments. '
                    .'Relevant: new tournament announcements, fixtures and schedules, results, registrations and trials, venue or date changes. '
                    .'Not relevant: transfer gossip, player interviews without tournament news, international football unrelated to India, other sports.',
                'keywords' => ['football tournament', 'Santosh Trophy', 'Durand Cup', 'I-League', 'football trials'],
                'region' => 'IN',
                'language' => 'en-IN',
                'freshness_days' => 21,
            ]);

            $sources = [
                [SourceType::Rss, 'https://khelnow.com', 'Khel Now'],
                [SourceType::GoogleNews, '"football tournament" India', null],
                [SourceType::GoogleNews, '"Santosh Trophy" OR "Durand Cup" OR "I-League"', null],
                [SourceType::YouTube, 'https://www.youtube.com/@IndianSuperLeague', null],
                [SourceType::Website, 'https://www.the-aiff.com/', 'AIFF (official site)'],
                [SourceType::WebSearch, 'Newly announced football tournaments, trials or registrations in India', 'AI web search: new tournaments'],
                [SourceType::Instagram, 'indianfootball', 'AIFF on Instagram'],
            ];

            foreach ($sources as [$type, $target, $name]) {
                if ($campaign->sources()->where('type', $type)->where('target', $target)->exists()) {
                    continue;
                }

                $source = new Source([
                    'campaign_id' => $campaign->id,
                    'type' => $type,
                    'target' => $target,
                    'name' => $name ?? '',
                    'fetch_interval_minutes' => $type->defaultInterval(),
                ]);

                try {
                    $adapters->for($type)->prepare($source);
                } catch (Throwable $e) {
                    // Keep it, but inactive, so the problem is visible in the Sources screen.
                    $source->is_active = false;
                    $source->forceFill(['last_error' => $e->getMessage()]);
                    $this->command->warn("  {$type->shortLabel()} {$target}: {$e->getMessage()}");
                }

                $source->name = $source->name ?: $target;
                $source->save();
            }
        });
    }
}
