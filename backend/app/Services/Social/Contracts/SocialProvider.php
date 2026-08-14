<?php

namespace App\Services\Social\Contracts;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;

interface SocialProvider
{
    public function platform(): string;

    public function label(): string;

    /**
     * URL the frontend should send the user to in order to connect an account.
     * Real providers return the platform's OAuth consent screen URL; the mock
     * provider returns an internal frontend route that simulates the OAuth
     * consent + callback round trip without leaving the app.
     */
    public function getAuthorizationUrl(User $user, string $redirectUri): string;

    /**
     * Exchange the OAuth callback (or, for mock providers, a user-supplied handle)
     * for a connected SocialAccount. $payload holds either the OAuth `code` or,
     * in mock mode, a { account_name, username } pair.
     */
    public function connect(User $user, array $payload): SocialAccount;

    /**
     * Upload and publish the clip's rendered video to this platform.
     *
     * @return array{success: bool, post_url?: string, external_post_id?: string, error?: string}
     */
    public function publish(SocialPost $post, string $videoFilePath): array;

    /**
     * @return array{views: int, likes: int, comments: int, shares: int, saves: int, watch_time: float, retention: float}
     */
    public function fetchMetrics(SocialPost $post): array;

    public function refreshToken(SocialAccount $account): bool;

    public function disconnect(SocialAccount $account): void;
}
