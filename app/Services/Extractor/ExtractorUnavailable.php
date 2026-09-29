<?php

namespace App\Services\Extractor;

use App\Sources\SourceException;

/**
 * The extractor sidecar is not running or not reachable (as opposed to failing on a page).
 */
class ExtractorUnavailable extends SourceException
{
    //
}
