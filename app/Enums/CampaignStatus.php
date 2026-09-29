<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Active = 'active';
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Paused => __('Paused'),
        };
    }
}
