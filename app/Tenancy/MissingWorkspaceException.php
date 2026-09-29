<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class MissingWorkspaceException extends RuntimeException
{
    public static function forModel(Model $model): self
    {
        return new self(sprintf(
            'No current workspace is set while querying [%s]. Set one via CurrentWorkspace or use acrossWorkspaces() in system code.',
            $model::class,
        ));
    }

    public static function forOperation(string $operation): self
    {
        return new self("No current workspace is set; unable to {$operation}.");
    }
}
