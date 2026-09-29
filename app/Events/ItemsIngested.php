<?php

namespace App\Events;

use App\Models\Source;
use Illuminate\Foundation\Events\Dispatchable;

class ItemsIngested
{
    use Dispatchable;

    /**
     * @param  list<int>  $itemIds
     */
    public function __construct(public Source $source, public array $itemIds)
    {
        //
    }
}
