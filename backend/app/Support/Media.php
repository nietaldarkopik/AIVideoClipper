<?php

namespace App\Support;

class Media
{
    public static function url(?string $relativePath): ?string
    {
        if (! $relativePath) {
            return null;
        }

        return rtrim(config('app.url'), '/') . '/api/media/' . ltrim($relativePath, '/');
    }
}
