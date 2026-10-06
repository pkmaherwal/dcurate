<div class="max-w-4xl space-y-12">
    <div>
        <flux:heading size="xl" level="1">{{ __('Workspace settings') }}</flux:heading>
        <flux:text class="mt-1">{{ $this->workspace->name }} · {{ __(':plan plan', ['plan' => $this->workspace->plan->label()]) }}</flux:text>
    </div>

    <section class="space-y-4">
        <flux:heading size="lg">{{ __('General') }}</flux:heading>
        <form wire:submit="saveGeneral" class="flex max-w-lg items-end gap-3">
            <flux:input wire:model="name" :label="__('Workspace name')" class="flex-1" required />
            <flux:button type="submit">{{ __('Save') }}</flux:button>
        </form>
    </section>

    <section class="space-y-4">
        <div>
            <flux:heading size="lg">{{ __('AI') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Used to score, summarize and extract details from every new item. Today: $:spent of $:budget.', ['spent' => number_format($this->todaySpend, 2), 'budget' => number_format((float) $dailyBudget, 2)]) }}</flux:text>
        </div>
        <form wire:submit="saveAi" class="grid max-w-2xl gap-4 sm:grid-cols-2">
            <flux:select wire:model.live="aiProvider" :label="__('Provider')">
                <flux:select.option value="openai">OpenAI</flux:select.option>
                <flux:select.option value="gemini">Google Gemini</flux:select.option>
            </flux:select>
            <flux:select wire:model="aiModel" :label="__('Model')">
                @foreach ($this->models[$aiProvider] ?? [] as $model)
                    <flux:select.option :value="$model">{{ $model }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($aiProvider === 'openai')
                <flux:select wire:model="reasoningEffort" :label="__('Reasoning effort')" :description="__('Higher effort can improve accuracy but costs more output tokens.')">
                    @foreach (config('curation.ai.reasoning_efforts') as $effort)
                        <flux:select.option :value="$effort">{{ ucfirst($effort) }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:input wire:model="dailyBudget" type="number" step="0.5" min="0" :label="__('Daily budget (USD)')" :description="__('Enrichment pauses for the day when reached.')" />
            <div class="sm:col-span-2">
                <flux:button type="submit">{{ __('Save AI settings') }}</flux:button>
            </div>
        </form>
    </section>

    <section class="space-y-4">
        <flux:heading size="lg">{{ __('Members') }}</flux:heading>
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->members as $member)
                    <flux:table.row :key="$member->id">
                        <flux:table.cell>
                            <div class="font-medium">{{ $member->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $member->email }}</div>
                        </flux:table.cell>
                        <flux:table.cell>
                            @if ($member->id === $this->workspace->owner_id || $member->pivot->role === 'owner')
                                <flux:badge size="sm">{{ __('Owner') }}</flux:badge>
                            @else
                                <flux:select size="sm" class="max-w-36" wire:change="updateRole({{ $member->id }}, $event.target.value)">
                                    @foreach ([App\Enums\WorkspaceRole::Admin, App\Enums\WorkspaceRole::Reviewer] as $role)
                                        <flux:select.option :value="$role->value" :selected="$member->pivot->role === $role->value">{{ $role->label() }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            @if ($member->id !== $this->workspace->owner_id && $member->id !== auth()->id())
                                <flux:button size="sm" variant="ghost" icon="user-minus" wire:click="removeMember({{ $member->id }})" wire:confirm="{{ __('Remove this member from the workspace?') }}">{{ __('Remove') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <form wire:submit="addMember" class="grid items-end gap-3 sm:grid-cols-[1fr_1fr_9rem_auto]">
            <flux:input wire:model="memberName" :label="__('Name')" />
            <flux:input wire:model="memberEmail" type="email" :label="__('Email')" />
            <flux:select wire:model="memberRole" :label="__('Role')">
                <flux:select.option value="reviewer">{{ __('Reviewer') }}</flux:select.option>
                <flux:select.option value="admin">{{ __('Admin') }}</flux:select.option>
            </flux:select>
            <flux:button type="submit" icon="user-plus">{{ __('Add') }}</flux:button>
        </form>
        <flux:text size="sm">{{ __('Reviewers can approve and reject items. Admins can also manage campaigns, sources, members and tokens.') }}</flux:text>
    </section>

    <section class="space-y-4">
        <div>
            <flux:heading size="lg">{{ __('API tokens') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Your website uses a token to read approved items: send it as "Authorization: Bearer <token>".') }}</flux:text>
        </div>

        @if ($plainToken)
            <flux:callout variant="success" icon="key" :heading="__('Copy this token now. It will not be shown again.')">
                <flux:callout.text><code class="break-all select-all">{{ $plainToken }}</code></flux:callout.text>
            </flux:callout>
        @endif

        @if ($this->tokens->isNotEmpty())
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->tokens as $token)
                    <div class="flex items-center justify-between gap-3 px-4 py-3" wire:key="token-{{ $token->id }}">
                        <div>
                            <div class="font-medium">{{ $token->name }}</div>
                            <div class="text-xs text-zinc-500">{{ __('Created :created · last used :used', ['created' => $token->created_at?->diffForHumans(), 'used' => $token->last_used_at?->diffForHumans() ?? __('never')]) }}</div>
                        </div>
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="revokeToken({{ $token->id }})" wire:confirm="{{ __('Revoke this token? Websites using it stop receiving updates.') }}">{{ __('Revoke') }}</flux:button>
                    </div>
                @endforeach
            </div>
        @endif

        <form wire:submit="createToken" class="flex max-w-lg items-end gap-3">
            <flux:input wire:model="tokenName" :label="__('Token name')" :placeholder="__('Main website')" class="flex-1" />
            <flux:button type="submit" icon="key">{{ __('Create token') }}</flux:button>
        </form>
    </section>

    <section class="space-y-4">
        <flux:heading size="lg">{{ __('AI usage this month') }}</flux:heading>
        @if ($this->usage->isEmpty())
            <flux:text>{{ __('No AI usage yet this month.') }}</flux:text>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Model') }}</flux:table.column>
                    <flux:table.column>{{ __('Purpose') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Requests') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Tokens in / out') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Searches') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Est. cost') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->usage as $row)
                        <flux:table.row :key="$row->model.$row->purpose">
                            <flux:table.cell>{{ $row->model }}</flux:table.cell>
                            <flux:table.cell>{{ Str::headline($row->purpose) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format((int) $row->requests) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format((int) $row->input_tokens) }} / {{ number_format((int) $row->output_tokens) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format((int) $row->web_search_calls) }}</flux:table.cell>
                            <flux:table.cell align="end">${{ number_format((float) $row->est_cost_usd, 4) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </section>
</div>
