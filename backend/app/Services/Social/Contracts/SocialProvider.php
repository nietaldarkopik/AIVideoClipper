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
     *
     * $state is a caller-generated, tamper-evident token (see
     * SocialAccountController::authorize()) that must be echoed back verbatim
     * as the `state` query param on the redirect URI — it's how the callback
     * (which Google/etc. hit directly, with no bearer token attached) recovers
     * which user initiated the connection. $user is null for "Login/Sign up
     * with {Platform}" flows (AuthController), where no app user exists yet —
     * no implementation actually reads $user, it's kept for interface parity
     * with connect().
     */
    public function getAuthorizationUrl(?User $user, string $redirectUri, string $state): string;

    /**
     * Exchange the OAuth callback (or, for mock providers, a user-supplied handle)
     * for a connected SocialAccount. $payload holds either the OAuth `code` or,
     * in mock mode, a { account_name, username } pair.
     */
    public function connect(User $user, array $payload): SocialAccount;

    /**
     * Upload and publish the clip's rendered video to this platform.
     *
     * $coverImagePath is the clip's generated cover/thumbnail image (see
     * CoverGeneratorService/Clip::cover_path), when one exists — attached as
     * a custom thumbnail on platforms whose API actually supports uploading
     * one (currently YouTube via thumbnails.set, Facebook via the `thumb`
     * video field); ignored by platforms whose API doesn't (Instagram's
     * automation service and TikTok's Content Posting API, which only
     * supports picking a timestamp WITHIN the uploaded video, not an
     * arbitrary custom image, for an organic/non-ad post).
     *
     * @return array{success: bool, post_url?: string, external_post_id?: string, error?: string}
     */
    public function publish(SocialPost $post, string $videoFilePath, ?string $coverImagePath = null): array;

    /**
     * @return array{views: int, likes: int, comments: int, shares: int, saves: int, watch_time: float, retention: float}
     */
    public function fetchMetrics(SocialPost $post): array;

    public function refreshToken(SocialAccount $account): bool;

    public function disconnect(SocialAccount $account): void;
}
