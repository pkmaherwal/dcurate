<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Reviewer = 'reviewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Admin => __('Admin'),
            self::Reviewer => __('Reviewer'),
        };
    }

    /**
     * Whether the role may manage campaigns, sources, members and tokens.
     */
    public function canManage(): bool
    {
        return $this !== self::Reviewer;
    }
}
