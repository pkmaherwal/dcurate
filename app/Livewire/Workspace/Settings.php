<?php

namespace App\Livewire\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\AiUsage;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use App\Tenancy\CurrentWorkspace;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Workspace $workspace
 * @property-read array<string, list<string>> $models
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, WorkspaceApiToken> $tokens
 * @property-read Collection<int, AiUsage> $usage
 * @property-read float $todaySpend
 */
#[Title('Workspace settings')]
class Settings extends Component
{
    public string $name = '';

    public string $aiProvider = 'openai';

    public string $aiModel = '';

    public string $prefilterModel = '';

    public string $dailyBudget = '';

    public string $memberName = '';

    public string $memberEmail = '';

    public string $memberRole = 'reviewer';

    public string $tokenName = '';

    public ?string $plainToken = null;

    public function mount(): void
    {
        $workspace = $this->workspace();
        $this->authorize('manage', $workspace);

        $this->name = $workspace->name;
        $this->aiProvider = (string) $workspace->setting('ai.provider');
        $this->aiModel = (string) $workspace->setting('ai.model');
        $this->prefilterModel = (string) $workspace->setting('ai.prefilter_model');
        $this->dailyBudget = (string) $workspace->setting('ai.daily_budget_usd');
    }

    #[Computed]
    public function workspace(): Workspace
    {
        return app(CurrentWorkspace::class)->getOrFail();
    }

    /**
     * Known models per provider (from the pricing table).
     *
     * @return array<string, list<string>>
     */
    #[Computed]
    public function models(): array
    {
        $models = ['openai' => [], 'gemini' => []];

        foreach (array_keys((array) config('curation.ai.pricing')) as $model) {
            $model = (string) $model;
            $models[str_starts_with($model, 'gemini') ? 'gemini' : 'openai'][] = $model;
        }

        return $models;
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->workspace->users()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, WorkspaceApiToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return WorkspaceApiToken::query()->latest()->get();
    }

    /**
     * @return Collection<int, AiUsage>
     */
    #[Computed]
    public function usage(): Collection
    {
        return AiUsage::query()
            ->where('date', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('provider, model, purpose, sum(requests) as requests, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(web_search_calls) as web_search_calls, sum(est_cost_usd) as est_cost_usd')
            ->groupBy('provider', 'model', 'purpose')
            ->orderByDesc('est_cost_usd')
            ->get();
    }

    #[Computed]
    public function todaySpend(): float
    {
        return (float) AiUsage::where('date', now()->toDateString())->sum('est_cost_usd');
    }

    public function saveGeneral(): void
    {
        $this->authorize('manage', $this->workspace);
        $this->validate(['name' => ['required', 'string', 'max:120']]);

        $this->workspace->update(['name' => $this->name]);

        Flux::toast(variant: 'success', text: __('Workspace saved.'));
    }

    public function saveAi(): void
    {
        $this->authorize('manage', $this->workspace);

        $this->validate([
            'aiProvider' => ['required', Rule::in(['openai', 'gemini'])],
            'aiModel' => ['required', 'string', 'max:100'],
            'prefilterModel' => ['nullable', 'string', 'max:100'],
            'dailyBudget' => ['required', 'numeric', 'min:0', 'max:1000'],
        ]);

        $settings = $this->workspace->settings ?? [];
        data_set($settings, 'ai.provider', $this->aiProvider);
        data_set($settings, 'ai.model', $this->aiModel);
        data_set($settings, 'ai.prefilter_model', $this->prefilterModel ?: null);
        data_set($settings, 'ai.daily_budget_usd', (float) $this->dailyBudget);

        $this->workspace->update(['settings' => $settings]);

        Flux::toast(variant: 'success', text: __('AI settings saved.'));
    }

    public function addMember(): void
    {
        $this->authorize('manage', $this->workspace);

        $this->validate([
            'memberName' => ['required', 'string', 'max:255'],
            'memberEmail' => ['required', 'email', 'max:255'],
            'memberRole' => ['required', Rule::in([WorkspaceRole::Admin->value, WorkspaceRole::Reviewer->value])],
        ]);

        $limit = $this->workspace->limit('members');

        if ($limit !== null && $this->members->count() >= $limit) {
            $this->addError('memberEmail', __('Your plan allows :count members.', ['count' => $limit]));

            return;
        }

        $email = Str::lower($this->memberEmail);
        $user = User::where('email', $email)->first();
        $isNew = $user === null;

        if ($isNew) {
            $user = User::create(['name' => $this->memberName, 'email' => $email, 'password' => Str::password(32)]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $this->workspace->addMember($user, WorkspaceRole::from($this->memberRole));

        if ($isNew) {
            Password::sendResetLink(['email' => $email]);
        }

        $this->reset('memberName', 'memberEmail', 'memberRole');
        unset($this->members);

        Flux::toast(variant: 'success', text: $isNew ? __('Member added. They will get an email to set a password.') : __('Member added.'));
    }

    public function updateRole(int $userId, string $role): void
    {
        $this->authorize('manage', $this->workspace);

        $member = $this->members->firstWhere('id', $userId);
        $newRole = WorkspaceRole::tryFrom($role);

        if ($member === null || $newRole === null || $newRole === WorkspaceRole::Owner || $member->id === $this->workspace->owner_id) {
            return;
        }

        $this->workspace->users()->updateExistingPivot($member->id, ['role' => $newRole->value]);
        unset($this->members);
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('manage', $this->workspace);

        if ($userId === $this->workspace->owner_id || $userId === Auth::id()) {
            Flux::toast(variant: 'danger', text: __('The owner and yourself cannot be removed.'));

            return;
        }

        $this->workspace->users()->detach($userId);
        User::whereKey($userId)->where('current_workspace_id', $this->workspace->id)->update(['current_workspace_id' => null]);

        unset($this->members);
    }

    public function createToken(): void
    {
        $this->authorize('manage', $this->workspace);
        $this->validate(['tokenName' => ['required', 'string', 'max:100']]);

        [, $this->plainToken] = WorkspaceApiToken::issue($this->tokenName, Auth::user());

        $this->reset('tokenName');
        unset($this->tokens);
    }

    public function revokeToken(int $id): void
    {
        $this->authorize('manage', $this->workspace);

        WorkspaceApiToken::whereKey($id)->delete();
        unset($this->tokens);
    }
}
