<?php

namespace App\Sources\Contracts;

use App\Models\Source;
use App\Sources\FetchResult;
use App\Sources\SourceException;

interface SourceAdapter
{
    /**
     * Validate and complete a new or edited source before it is saved
     * (discover feeds, resolve channel ids, suggest a name).
     *
     * @throws SourceException
     */
    public function prepare(Source $source): void;

    /**
     * Fetch the latest items for the source.
     *
     * @param  bool  $conditional  Use ETag / Last-Modified and content hashes to skip unchanged sources.
     *
     * @throws SourceException
     */
    public function fetch(Source $source, bool $conditional = true): FetchResult;
}
