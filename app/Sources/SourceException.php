<?php

namespace App\Sources;

use RuntimeException;

/**
 * A problem with a source that should be shown to the user as-is (bad URL, no feed found, API not configured…).
 */
class SourceException extends RuntimeException
{
    //
}
