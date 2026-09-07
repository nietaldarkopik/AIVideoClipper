<?php

namespace App\Services\Research\DTOs;

class ProviderHealth
{
    public function __construct(
        public readonly string $sourceKey,
        public readonly bool $ok,
        public readonly string $message,
        /** False when required credentials are missing — a distinct state from "failed". */
        public readonly bool $configured = true,
        public readonly ?int $latencyMs = null,
    ) {}
}
