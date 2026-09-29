<div class="space-y-8">
    <flux:heading size="xl" level="1">{{ __('Dashboard') }}</flux:heading>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => __('Waiting for review'), 'value' => number_format($this->stats['pending']), 'icon' => 'inbox-stack'],
            ['label' => __('Approved today'), 'value' => number_format($this->stats['approved_today']), 'icon' => 'check-badge'],
            ['label' => __('Sources with errors'), 'value' => number_format($this->stats['failing_sources']), 'icon' => 'exclamation-triangle'],
            ['label' => __('AI cost this month'), 'value' => '$'.number_format($this->stats['ai_cost_month'], 2), 'icon' => 'sparkles'],
        ] as $stat)
            <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                <div class="flex items-center gap-2 text-sm text-zinc-500">
                    <flux:icon :name="$stat['icon']" variant="micro" />
                    {{ $stat['label'] }}
                </div>
                <div class="mt-2 text-3xl font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        <section>
            <div class="mb-3 flex items-center justify-between">
                <flux:heading>{{ __('Campaigns') }}</flux:heading>
                <flux:link :href="route('campaigns.index')" wire:navigate class="text-sm">{{ __('All campaigns') }}</flux:link>
            </div>
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->campaigns as $campaign)
                    <a href="{{ route('campaigns.review', $campaign) }}" wire:navigate wire:key="dash-campaign-{{ $campaign->id }}" class="flex items-center justify-between px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <span class="font-medium">{{ $campaign->name }}</span>
                        <flux:badge size="sm" :color="$campaign->pending_count ? 'amber' : 'zinc'">{{ trans_choice(':count to review|:count to review', $campaign->pending_count) }}</flux:badge>
                    </a>
                @empty
                    <div class="px-4 py-6 text-center">
                        <flux:text>{{ __('No campaigns yet.') }}</flux:text>
                        @can('create', App\Models\Campaign::class)
                            <flux:button class="mt-3" size="sm" variant="primary" :href="route('campaigns.index')" wire:navigate>{{ __('Create a campaign') }}</flux:button>
                        @endcan
                    </div>
                @endforelse
            </div>
        </section>

        <section>
            <div class="mb-3 flex items-center justify-between">
                <flux:heading>{{ __('Recent fetches') }}</flux:heading>
                <flux:link :href="route('sources.health')" wire:navigate class="text-sm">{{ __('Source health') }}</flux:link>
            </div>
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->recentRuns as $run)
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm" wire:key="dash-run-{{ $run->id }}">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $run->source?->name ?? __('Deleted source') }}</div>
                            <div class="text-xs text-zinc-500">{{ $run->started_at->diffForHumans() }} · {{ trans_choice(':count new|:count new', $run->items_new) }}</div>
                        </div>
                        <flux:badge size="sm" :color="$run->status->color()">{{ Str::headline($run->status->value) }}</flux:badge>
                    </div>
                @empty
                    <div class="px-4 py-6 text-center"><flux:text>{{ __('No fetches yet.') }}</flux:text></div>
                @endforelse
            </div>
        </section>
    </div>
</div>
