<?php

namespace App\Http\Middleware;

use App\Models\WorkspaceApiToken;
use App\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates public API requests with a workspace API token and enters that workspace.
 */
class AuthenticateWorkspaceToken
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
        $plain = $request->bearerToken() ?? $request->query('token');

        $token = is_string($plain) && $plain !== '' ? WorkspaceApiToken::findValid($plain) : null;

        abort_if($token === null, 401, 'Invalid or missing API token.');

        $this->workspace->set($token->workspace);

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
