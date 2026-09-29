<div>
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Source health') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Sources back off after errors and pause after :count failures in a row.', ['count' => config('curation.fetch.max_consecutive_errors')]) }}</flux:text>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Source') }}</flux:table.column>
            <flux:table.column>{{ __('Campaign') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Last fetch') }}</flux:table.column>
            <flux:table.column>{{ __('Next fetch') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->sources as $source)
                <flux:table.row :key="$source->id">
                    <flux:table.cell class="max-w-sm whitespace-normal">
                        <div class="flex items-center gap-2 font-medium">
                            <flux:icon :name="$source->type->icon()" variant="micro" class="text-zinc-400" />
                            {{ $source->name }}
                        </div>
                        @if ($source->last_error)
                            <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ Str::limit($source->last_error, 140) }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:link :href="route('campaigns.sources', $source->campaign)" wire:navigate>{{ $source->campaign->name }}</flux:link>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if (! $source->is_active)
                            <flux:badge size="sm" color="zinc">{{ $source->consecutive_errors ? __('Paused (errors)') : __('Inactive') }}</flux:badge>
                        @elseif ($source->consecutive_errors)
                            <flux:badge size="sm" color="red">{{ trans_choice(':count error|:count errors in a row', $source->consecutive_errors) }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="green">{{ __('Healthy') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell class="text-xs">{{ $source->last_fetched_at?->diffForHumans() ?? '—' }}</flux:table.cell>
                    <flux:table.cell class="text-xs">{{ $source->is_active ? ($source->next_fetch_at?->diffForHumans() ?? __('Now')) : '—' }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @can('update', $source)
                            @if ($source->consecutive_errors || ! $source->is_active)
                                <flux:button size="sm" icon="arrow-path" wire:click="retry({{ $source->id }})">{{ __('Retry now') }}</flux:button>
                            @endif
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($this->failures->isNotEmpty())
        <flux:heading class="mt-10 mb-3">{{ __('Recent failures') }}</flux:heading>
        <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->failures as $run)
                <div class="px-4 py-3 text-sm" wire:key="run-{{ $run->id }}">
                    <div class="flex justify-between gap-3">
                        <span class="font-medium">{{ $run->source?->name }}</span>
                        <span class="text-xs text-zinc-500">{{ $run->started_at->diffForHumans() }}</span>
                    </div>
                    <div class="mt-1 text-xs text-red-600 dark:text-red-400">{{ Str::limit($run->error, 300) }}</div>
                </div>
            @endforeach
        </div>
    @endif
</div>
