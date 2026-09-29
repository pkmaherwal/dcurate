@props(['current'])

@php($workspaces = auth()->user()->workspaces()->orderBy('name')->get())

<div class="px-1">
    @if ($workspaces->count() > 1)
        <flux:dropdown position="bottom" align="start">
            <flux:button variant="subtle" size="sm" icon:trailing="chevron-up-down" class="w-full justify-between">
                <span class="truncate">{{ $current->name }}</span>
            </flux:button>

            <flux:menu>
                <flux:menu.radio.group>
                    @foreach ($workspaces as $workspace)
                        <form method="POST" action="{{ route('workspace.switch', $workspace) }}">
                            @csrf
                            <flux:menu.radio as="button" type="submit" :checked="$workspace->is($current)">
                                {{ $workspace->name }}
                            </flux:menu.radio>
                        </form>
                    @endforeach
                </flux:menu.radio.group>
            </flux:menu>
        </flux:dropdown>
    @else
        <flux:text size="sm" class="truncate px-2 font-medium">{{ $current->name }}</flux:text>
    @endif
</div>
