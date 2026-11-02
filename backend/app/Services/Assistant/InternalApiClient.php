<?php

namespace App\Services\Assistant;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

/**
 * Calls one of this app's own API routes in-process, as a given user, going through the
 * full HTTP kernel (auth, throttling, validation, authorization, resources). The
 * assistant's tools are built on this so they get exactly the rules the UI gets — there
 * is no second, assistant-only code path that could drift from the real one or skip an
 * ownership check.
 */
class InternalApiClient
{
    /**
     * @param  array<string, mixed>  $query  sent as the query string
     * @param  array<string, mixed>  $body  sent as request parameters (non-GET)
     * @return array{status: int, data: mixed}
     */
    public function call(User $user, string $method, string $path, array $query = [], array $body = []): array
    {
        $method = strtoupper($method);
        $original = app('request');

        try {
            // actingAs sets the guard's user for the duration of this call only; the
            // finally below clears it so a long-lived queue worker never carries one
            // user's identity into the next job.
            Sanctum::actingAs($user, ['*']);

            $uri = '/api' . $path . ($query !== [] ? '?' . http_build_query($query) : '');
            $request = Request::create($uri, $method, $method === 'GET' ? [] : $body, [], [], ['HTTP_ACCEPT' => 'application/json']);

            $response = app()->handle($request);
            $content = $response->getContent();
            $decoded = json_decode((string) $content, true);

            return ['status' => $response->getStatusCode(), 'data' => $decoded ?? $content];
        } finally {
            app()->instance('request', $original);
            app('auth')->forgetGuards();
        }
    }
}
