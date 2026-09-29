<?php

namespace App\Events;

use App\Models\Source;
use Illuminate\Foundation\Events\Dispatchable;

class SourcePaused
{
    use Dispatchable;

    public function __construct(public Source $source, public string $error)
    {
        //
    }
}
