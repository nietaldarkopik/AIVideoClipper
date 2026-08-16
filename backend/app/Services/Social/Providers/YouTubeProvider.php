<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Contracts\SocialProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Real YouTube publishing via Google OAuth 2.0 + YouTube Data API v3. Uploads land
 * as Shorts-formatted links (matching this app's vertical-clip output). Requires
 * GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET / GOOGLE_REDIRECT_URI in .env — see
 * config/services.php for where those are read from.
 */
class YouTubeProvider implements SocialProvider
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    private const API_BASE = 'https://www.googleapis.com/youtube/v3';

    private const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

    private const SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

    public function platform(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    public function getAuthorizationUrl(User $user, string $redirectUri, string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            // offline + consent so Google always issues a refresh_token, even on
            // a re-connect — without `prompt=consent`, Google silently omits the
            // refresh_token on subsequent authorizations for the same app+user.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return self::AUTH_URL . '?' . $query;
    }

    public function connect(User $user, array $payload): SocialAccount
    {
        $code = $payload['code'] ?? throw new RuntimeException('Missing authorization code from Google.');
        $redirectUri = $payload['redirect_uri'] ?? $this->redirectUri();

        $tokenResponse = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if ($tokenResponse->failed()) {
            throw new RuntimeException('Google token exchange failed: ' . $tokenResponse->body());
        }

        $tokens = $tokenResponse->json();
        $accessToken = $tokens['access_token'] ?? throw new RuntimeException('Google did not return an access token.');

        $channel = $this->fetchOwnChannel($accessToken);

        return SocialAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'external_account_id' => $channel['id'],
            ],
            [
                'account_name' => $channel['snippet']['title'] ?? 'YouTube channel',
                'username' => $channel['snippet']['customUrl'] ?? null,
                'avatar_url' => $channel['snippet']['thumbnails']['default']['url'] ?? null,
                'status' => SocialAccount::STATUS_CONNECTED,
                'access_token' => $accessToken,
                // Google only returns refresh_token on the very first consent for
                // this app+user; keep the previously stored one on a reconnect
                // rather than overwriting it with null.
                'refresh_token' => $tokens['refresh_token']
                    ?? SocialAccount::where('user_id', $user->id)
                        ->where('platform', $this->platform())
                        ->where('external_account_id', $channel['id'])
                        ->value('refresh_token'),
                'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
                'permissions' => ['upload'],
                'last_synced_at' => now(),
            ]
        );
    }

    public function publish(SocialPost $post, string $videoFilePath): array
    {
        if (! file_exists($videoFilePath)) {
            return ['success' => false, 'error' => 'Rendered clip file not found.'];
        }

        $account = $post->socialAccount;

        try {
            $accessToken = $this->ensureFreshToken($account);
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $title = mb_substr($post->title ?: ($post->clip->title ?? 'Untitled'), 0, 100);
        $hashtags = collect($post->hashtags ?? [])->filter()->values();
        $description = mb_substr(
            trim(($post->caption ?? '') . "\n\n" . $hashtags->map(fn ($h) => '#' . ltrim($h, '#'))->implode(' ')),
            0,
            5000
        );

        $startResponse = Http::withToken($accessToken)
            ->withHeaders([
                'X-Upload-Content-Type' => 'video/*',
                'X-Upload-Content-Length' => (string) filesize($videoFilePath),
            ])
            ->post(self::UPLOAD_URL . '?uploadType=resumable&part=snippet,status', [
                'snippet' => [
                    'title' => $title,
                    'description' => $description,
                    'tags' => $hashtags->map(fn ($h) => ltrim($h, '#'))->take(500)->values()->all(),
                    'categoryId' => '22',
                ],
                'status' => [
                    'privacyStatus' => config('services.google.default_privacy', 'public'),
                    'selfDeclaredMadeForKids' => false,
                ],
            ]);

        if ($startResponse->failed()) {
            return ['success' => false, 'error' => 'Failed to start YouTube upload: ' . $startResponse->body()];
        }

        $uploadUrl = $startResponse->header('Location');
        if (! $uploadUrl) {
            return ['success' => false, 'error' => 'Google did not return a resumable upload URL.'];
        }

        $handle = fopen($videoFilePath, 'r');
        try {
            $uploadResponse = Http::withToken($accessToken)
                ->withBody($handle, 'video/*')
                ->put($uploadUrl);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($uploadResponse->failed()) {
            return ['success' => false, 'error' => 'YouTube upload failed: ' . $uploadResponse->body()];
        }

        $videoId = $uploadResponse->json('id');
        if (! $videoId) {
            return ['success' => false, 'error' => 'YouTube did not return a video id after upload.'];
        }

        return [
            'success' => true,
            'post_url' => "https://www.youtube.com/shorts/{$videoId}",
            'external_post_id' => $videoId,
        ];
    }

    /**
     * YouTube's public Data API v3 only exposes view/like/comment counts here.
     * Shares, saves, watch time, and retention require the separate YouTube
     * Analytics API (yt-analytics.readonly scope, channel-owner only) — not
     * wired up yet, so those come back as 0 rather than being faked.
     */
    public function fetchMetrics(SocialPost $post): array
    {
        $account = $post->socialAccount;
        $accessToken = $this->ensureFreshToken($account);

        $response = Http::withToken($accessToken)
            ->get(self::API_BASE . '/videos', [
                'part' => 'statistics',
                'id' => $post->external_post_id,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch YouTube metrics: ' . $response->body());
        }

        $stats = $response->json('items.0.statistics') ?? [];

        return [
            'views' => (int) ($stats['viewCount'] ?? 0),
            'likes' => (int) ($stats['likeCount'] ?? 0),
            'comments' => (int) ($stats['commentCount'] ?? 0),
            'shares' => 0,
            'saves' => 0,
            'watch_time' => 0.0,
            'retention' => 0.0,
        ];
    }

    public function refreshToken(SocialAccount $account): bool
    {
        if (! $account->refresh_token) {
            return false;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $account->refresh_token,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            Log::warning('YouTube token refresh failed', ['account_id' => $account->id, 'body' => $response->body()]);
            $account->update(['status' => SocialAccount::STATUS_EXPIRED]);

            return false;
        }

        $tokens = $response->json();
        $account->update([
            'access_token' => $tokens['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        return true;
    }

    public function disconnect(SocialAccount $account): void
    {
        if ($account->access_token) {
            // Best-effort — Google returns 200 even for an already-invalid token,
            // and a failed revoke shouldn't block disconnecting locally.
            Http::asForm()->post(self::REVOKE_URL, ['token' => $account->access_token]);
        }

        $account->update([
            'status' => SocialAccount::STATUS_REVOKED,
            'access_token' => null,
            'refresh_token' => null,
            'auto_publish_enabled' => false,
        ]);
    }

    private function ensureFreshToken(SocialAccount $account): string
    {
        if ($account->token_expires_at && $account->token_expires_at->isFuture()) {
            return $account->access_token;
        }

        if (! $this->refreshToken($account)) {
            throw new RuntimeException('YouTube access token expired and could not be refreshed. Reconnect the account.');
        }

        return $account->access_token;
    }

    private function fetchOwnChannel(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get(self::API_BASE . '/channels', [
                'part' => 'snippet',
                'mine' => 'true',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch YouTube channel info: ' . $response->body());
        }

        $channel = $response->json('items.0');
        if (! $channel) {
            throw new RuntimeException('No YouTube channel found for this Google account.');
        }

        return $channel;
    }

    private function clientId(): string
    {
        return (string) config('services.google.client_id')
            ?: throw new RuntimeException('GOOGLE_CLIENT_ID is not set.');
    }

    private function clientSecret(): string
    {
        return (string) config('services.google.client_secret')
            ?: throw new RuntimeException('GOOGLE_CLIENT_SECRET is not set.');
    }

    private function redirectUri(): string
    {
        return (string) config('services.google.redirect_uri')
            ?: throw new RuntimeException('GOOGLE_REDIRECT_URI is not set.');
    }
}
