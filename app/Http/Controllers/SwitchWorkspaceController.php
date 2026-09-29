<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchWorkspaceController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($request->user()->switchWorkspace($workspace), 403);

        return redirect()->route('dashboard');
    }
}
