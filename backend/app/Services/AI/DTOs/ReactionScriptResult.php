<?php

namespace App\Services\AI\DTOs;

class ReactionScriptResult
{
    public function __construct(
        public readonly string $text,
        // 'positive' | 'satire'
        public readonly string $tone,
    ) {
    }
}
