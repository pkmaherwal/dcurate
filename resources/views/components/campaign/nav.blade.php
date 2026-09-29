@props(['campaign', 'current'])

@php
    $counts = $campaign->statusCounts();
    $sourceCount = $campaign->sources()->count();
@endphp

<div class="mb-6 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('campaigns.index')" wire:navigate>{{ __('Campaigns') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $campaign->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" level="1" class="mt-1 truncate">{{ $campaign->name }}</flux:heading>
            @if ($campaign->description)
                <flux:text class="mt-1">{{ $campaign->description }}</flux:text>
            @endif
        </div>

        <flux:badge :color="$campaign->isActive() ? 'green' : 'zinc'" size="sm">{{ $campaign->status->label() }}</flux:badge>
    </div>

    <flux:navbar class="-mx-2 overflow-x-auto border-b border-zinc-200 dark:border-zinc-700">
        <flux:navbar.item icon="inbox-stack" :href="route('campaigns.review', $campaign)" :current="$current === 'review'" :badge="$counts['pending'] ?: null" wire:navigate>
            {{ __('Review') }}
        </flux:navbar.item>
        <flux:navbar.item icon="rss" :href="route('campaigns.sources', $campaign)" :current="$current === 'sources'" :badge="$sourceCount ?: null" wire:navigate>
            {{ __('Sources') }}
        </flux:navbar.item>
        <flux:navbar.item icon="check-badge" :href="route('campaigns.approved', $campaign)" :current="$current === 'approved'" :badge="$counts['approved'] ?: null" wire:navigate>
            {{ __('Approved') }}
        </flux:navbar.item>
        @can('update', $campaign)
            <flux:navbar.item icon="cog-6-tooth" :href="route('campaigns.settings', $campaign)" :current="$current === 'settings'" wire:navigate>
                {{ __('Settings') }}
            </flux:navbar.item>
        @endcan
    </flux:navbar>
</div>
