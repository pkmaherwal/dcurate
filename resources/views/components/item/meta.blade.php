@props(['item'])

<div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-zinc-500">
    @if ($item->source)
        <span class="inline-flex items-center gap-1 font-medium text-zinc-700 dark:text-zinc-300">
            <flux:icon :name="$item->source->type->icon()" variant="micro" />
            {{ $item->source->name }}
        </span>
    @endif
    @if ($publisher = data_get($item->media, 'publisher.name'))
        <span>· {{ $publisher }}</span>
    @endif
    <span>· {{ $item->effectiveDate()->diffForHumans() }}</span>
    @if ($item->author && $item->author !== $publisher)
        <span>· {{ Str::limit($item->author, 40) }}</span>
    @endif
</div>
