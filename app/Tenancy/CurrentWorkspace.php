<?php

namespace App\Tenancy;

use App\Models\Workspace;
use Illuminate\Support\Facades\Context;

/**
 * Holds the workspace (tenant) the current request, command or job runs in.
 *
 * The id is stored in Laravel's Context, which is serialized into every
 * queued job's payload and restored before the job runs, so background
 * work always executes inside the workspace that dispatched it.
 */
class CurrentWorkspace
{
    public const CONTEXT_KEY = 'workspace_id';

    protected ?Workspace $workspace = null;

    /**
     * Set (or clear) the current workspace.
     */
    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;

        if ($workspace === null) {
            Context::forget(self::CONTEXT_KEY);

            return;
        }

        Context::add(self::CONTEXT_KEY, $workspace->getKey());
    }

    /**
     * Get the current workspace id, if any.
     */
    public function id(): ?int
    {
        $id = Context::get(self::CONTEXT_KEY);

        return $id === null ? null : (int) $id;
    }

    /**
     * Determine if a workspace is currently set.
     */
    public function check(): bool
    {
        return $this->id() !== null;
    }

    /**
     * Get the current workspace model, if any.
     */
    public function get(): ?Workspace
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        if ($this->workspace?->getKey() !== $id) {
            $this->workspace = Workspace::find($id);
        }

        return $this->workspace;
    }

    /**
     * Get the current workspace or fail.
     *
     * @throws MissingWorkspaceException
     */
    public function getOrFail(): Workspace
    {
        return $this->get() ?? throw MissingWorkspaceException::forOperation('resolve the current workspace');
    }

    /**
     * Run the callback inside the given workspace, restoring the previous one afterwards.
     *
     * @template TReturn
     *
     * @param  callable(Workspace): TReturn  $callback
     * @return TReturn
     */
    public function run(Workspace $workspace, callable $callback): mixed
    {
        $previous = $this->get();

        $this->set($workspace);

        try {
            return $callback($workspace);
        } finally {
            $this->set($previous);
        }
    }
}
