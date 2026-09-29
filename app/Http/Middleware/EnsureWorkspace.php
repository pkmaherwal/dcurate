<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards tenant routes: the user must be inside a workspace.
 */
class EnsureWorkspace
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
        abort_unless($this->workspace->check(), 403, __('You are not a member of any workspace yet. Ask an admin to add you.'));

        return $next($request);
    }
}
