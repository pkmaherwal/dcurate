<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the signed-in user's current workspace for web (and Livewire) requests.
 */
class SetCurrentWorkspace
{
    public function __construct(protected CurrentWorkspace $workspace)
    {
        //
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $workspace = $user->currentWorkspace;

            if ($workspace === null || ! $user->belongsToWorkspace($workspace)) {
                $workspace = $user->workspaces()->orderBy('workspace_user.created_at')->first();

                $user->forceFill(['current_workspace_id' => $workspace?->id])->save();
            }

            $this->workspace->set($workspace);
        }

        return $next($request);
    }
}
