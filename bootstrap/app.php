<?php

use App\Http\Middleware\AuthenticateWorkspaceToken;
use App\Http\Middleware\EnsureWorkspace;
use App\Http\Middleware\SetCurrentWorkspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetCurrentWorkspace::class]);

        $middleware->alias([
            'workspace' => EnsureWorkspace::class,
            'workspace.token' => AuthenticateWorkspaceToken::class,
        ]);

        // The tenant must be known before route model binding runs its (workspace-scoped) queries.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentWorkspace::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: AuthenticateWorkspaceToken::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
