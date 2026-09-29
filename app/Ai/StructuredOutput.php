<?php

namespace App\Ai;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;

class StructuredOutput
{
    /**
     * The structured data of an agent response (decoding the text as a fallback).
     *
     * @return array<string, mixed>
     */
    public static function from(AgentResponse $response): array
    {
        if ($response instanceof StructuredAgentResponse) {
            return $response->toArray();
        }

        $decoded = json_decode($response->text, true);

        return is_array($decoded) ? $decoded : [];
    }
}
