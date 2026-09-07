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
 * Real Facebook Page publishing via Meta OAuth + Graph API. Facebook has no API
 * for posting to a personal profile (deprecated years ago) — everything here
 * publishes to the first Page the connecting user manages.
 *
 * Token model differs from Google's: `access_token` stores a long-lived PAGE
 * access token (used for publish/metrics — these don't expire under normal use),
 * while `refresh_token` stores the long-lived USER token (kept only so
 * refreshToken() can re-derive a fresh Page token if the stored one is ever
 * rejected — there is no separate Page-token refresh endpoint).
 */
class FacebookProvider implements SocialProvider
{
    private const AUTH_URL = 'https://www.facebook.com/%s/dialog/oauth';

    private const SCOPES = 'pages_show_list,pages_manage_posts,pages_read_engagement';

    public function platform(): string
    {
        return 'facebook';
    }

    public function label(): string
    {
        return 'Facebook';
    }

    public function getAuthorizationUrl(?User $user, string $redirectUri, string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
        ]);

        return sprintf(self::AUTH_URL, $this->graphVersion()).'?'.$query;
    }

    public function connect(User $user, array $payload): SocialAccount
    {
        $code = $payload['code'] ?? throw new RuntimeException('Missing authorization code from Facebook.');
        $redirectUri = $payload['redirect_uri'] ?? throw new RuntimeException('Missing redirect_uri.');

        $shortLivedToken = $this->exchangeCodeForToken($code, $redirectUri);
        $longLivedUserToken = $this->exchangeForLongLivedToken($shortLivedToken);

        $page = $this->fetchFirstManagedPage($longLivedUserToken);

        return SocialAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'external_account_id' => $page['id'],
            ],
            [
                'account_name' => $page['name'] ?? 'Facebook Page',
                'username' => null,
                'avatar_url' => "https://graph.facebook.com/{$page['id']}/picture?type=square",
                'status' => SocialAccount::STATUS_CONNECTED,
                'access_token' => $page['access_token'],
                'refresh_token' => $longLivedUserToken,
                // Page tokens derived from a long-lived user token don't have a
                // fixed expiry; this is a conservative reminder, not a hard cutoff.
                'token_expires_at' => now()->addDays(60),
                'permissions' => ['publish_video'],
                'last_synced_at' => now(),
            ]
        );
    }

    public function publish(SocialPost $post, string $videoFilePath, ?string $coverImagePath = null): array
    {
        if (! file_exists($videoFilePath)) {
            return ['success' => false, 'error' => 'Rendered clip file not found.'];
        }

        $account = $post->socialAccount;

        $hashtags = collect($post->hashtags ?? [])->filter()->values();
        $description = trim(
            ($post->title ? $post->title."\n\n" : '').
            ($post->caption ?? '')."\n\n".
            $hashtags->map(fn ($h) => '#'.ltrim($h, '#'))->implode(' ')
        );

        $request = Http::attach('source', fopen($videoFilePath, 'r'), basename($videoFilePath));
        // `thumb` is a documented Graph API video field: a photo to use as this
        // video's thumbnail (it also gets published as a regular Page photo —
        // a known side effect of this field, not a bug here).
        if ($coverImagePath && file_exists($coverImagePath)) {
            $request = $request->attach('thumb', fopen($coverImagePath, 'r'), basename($coverImagePath));
        }

        $response = $request->post($this->graphUrl("/{$account->external_account_id}/videos"), [
            'access_token' => $account->access_token,
            'description' => $description,
        ]);

        if ($response->failed()) {
            return ['success' => false, 'error' => 'Facebook upload failed: '.$response->body()];
        }

        $videoId = $response->json('id');
        if (! $videoId) {
            return ['success' => false, 'error' => 'Facebook did not return a video id after upload.'];
        }

        return [
            'success' => true,
            'post_url' => "https://www.facebook.com/{$account->external_account_id}/videos/{$videoId}",
            'external_post_id' => $videoId,
        ];
    }

    /**
     * Full view/watch-time analytics need the Page Insights API (extra permission,
     * Page-level not video-level for some metrics) — not wired up yet. This uses
     * the simpler per-object summary fields, which cover likes/comments/shares.
     */
    public function fetchMetrics(SocialPost $post): array
    {
        $account = $post->socialAccount;

        $response = Http::get($this->graphUrl("/{$post->external_post_id}"), [
            'fields' => 'likes.summary(true).limit(0),comments.summary(true).limit(0),shares',
            'access_token' => $account->access_token,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Facebook metrics: '.$response->body());
        }

        $data = $response->json();

        return [
            'views' => 0,
            'likes' => (int) ($data['likes']['summary']['total_count'] ?? 0),
            'comments' => (int) ($data['comments']['summary']['total_count'] ?? 0),
            'shares' => (int) ($data['shares']['count'] ?? 0),
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

        try {
            $page = $this->fetchFirstManagedPage($account->refresh_token, $account->external_account_id);
        } catch (RuntimeException $e) {
            Log::warning('Facebook token refresh failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);
            $account->update(['status' => SocialAccount::STATUS_EXPIRED]);

            return false;
        }

        $account->update([
            'access_token' => $page['access_token'],
            'token_expires_at' => now()->addDays(60),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        return true;
    }

    public function disconnect(SocialAccount $account): void
    {
        if ($account->refresh_token) {
            // Best-effort — revokes every permission granted to this app by the user.
            Http::delete($this->graphUrl('/me/permissions'), ['access_token' => $account->refresh_token]);
        }

        $account->update([
            'status' => SocialAccount::STATUS_REVOKED,
            'access_token' => null,
            'refresh_token' => null,
            'auto_publish_enabled' => false,
        ]);
    }

    private function exchangeCodeForToken(string $code, string $redirectUri): string
    {
        $response = Http::get($this->graphUrl('/oauth/access_token'), [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook token exchange failed: '.$response->body());
        }

        return $response->json('access_token') ?? throw new RuntimeException('Facebook did not return an access token.');
    }

    private function exchangeForLongLivedToken(string $shortLivedToken): string
    {
        $response = Http::get($this->graphUrl('/oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to exchange for a long-lived Facebook token: '.$response->body());
        }

        return $response->json('access_token') ?? throw new RuntimeException('Facebook did not return a long-lived token.');
    }

    /**
     * @return array{id: string, name: string, access_token: string}
     */
    private function fetchFirstManagedPage(string $userToken, ?string $preferPageId = null): array
    {
        $response = Http::get($this->graphUrl('/me/accounts'), [
            'access_token' => $userToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch managed Facebook Pages: '.$response->body());
        }

        $pages = $response->json('data') ?? [];
        if (empty($pages)) {
            throw new RuntimeException('This Facebook account does not manage any Pages. Publishing requires a Page.');
        }

        if ($preferPageId) {
            $match = collect($pages)->firstWhere('id', $preferPageId);
            if ($match) {
                return $match;
            }
        }

        return $pages[0];
    }

    private function graphUrl(string $path): string
    {
        return "https://graph.facebook.com/{$this->graphVersion()}{$path}";
    }

    private function appId(): string
    {
        return (string) config('services.facebook.app_id')
            ?: throw new RuntimeException('FACEBOOK_APP_ID is not set.');
    }

    private function appSecret(): string
    {
        return (string) config('services.facebook.app_secret')
            ?: throw new RuntimeException('FACEBOOK_APP_SECRET is not set.');
    }

    private function graphVersion(): string
    {
        return (string) config('services.facebook.graph_version', 'v21.0');
    }
}
