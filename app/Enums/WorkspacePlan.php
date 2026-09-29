<?php

namespace App\Enums;

enum WorkspacePlan: string
{
    case Internal = 'internal';
    case Free = 'free';
    case Pro = 'pro';

    public function label(): string
    {
        return (string) config("plans.{$this->value}.label", ucfirst($this->value));
    }

    /**
     * Get a plan limit. A null limit means unlimited.
     */
    public function limit(string $key): int|float|null
    {
        return config("plans.{$this->value}.limits.{$key}");
    }
}
