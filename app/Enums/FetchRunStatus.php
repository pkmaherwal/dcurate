<?php

namespace App\Enums;

enum FetchRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case NotModified = 'not_modified';
    case Failed = 'failed';

    public function color(): string
    {
        return match ($this) {
            self::Running => 'blue',
            self::Success, self::NotModified => 'green',
            self::Failed => 'red',
        };
    }
}
