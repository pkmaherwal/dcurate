@props(['item'])

@if ($item->ai_relevance === null)
    <flux:badge size="sm" color="zinc" icon="sparkles">{{ $item->ai_status === App\Enums\AiStatus::Failed ? __('AI failed') : __('AI pending') }}</flux:badge>
@else
    @php($color = $item->ai_relevance >= 70 ? 'green' : ($item->ai_relevance >= (int) config('curation.ai.relevance_threshold', 30) ? 'amber' : 'red'))
    <flux:tooltip :content="$item->ai_reason ?: __('AI relevance score')">
        <flux:badge size="sm" :color="$color" icon="sparkles">{{ $item->ai_relevance }}%</flux:badge>
    </flux:tooltip>
@endif
