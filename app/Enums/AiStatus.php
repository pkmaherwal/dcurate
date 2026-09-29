<?php

namespace App\Enums;

enum AiStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
