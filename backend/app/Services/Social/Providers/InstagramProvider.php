<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Contracts\SocialProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real Instagram publishing via a self-hosted Puppeteer service (tools/instagram-
 * automation), not the official API — Instagram's Content Publishing API requires
 * a Business/Creator account linked to a Facebook Page plus Meta App Review, which
 * is a lot of friction for personal use. Automation logs in with a stored
 * username/password instead of OAuth, so this platform doesn't participate in the
 * getAuthorizationUrl()/callback redirect flow the way YouTube/Facebook do —
 * connect() is called directly with real credentials (see ConnectAccountModal).
 *
 * `access_token` (encrypted at rest, existing SocialAccount cast) holds the raw
 * password — needed to re-login when the session expires, since there's no OAuth
 * refresh token for a scraped session. This is a real security tradeoff, made
 * knowingly: see the risk discussion this feature shipped with. `refresh_token`
 * (also encrypted) holds the current session cookies as JSON.
 *
 * Two-factor authentication is NOT supported yet — connect() fails with a clear
 * error if the account has it enabled. The underlying service (POST /login)
 * already reports `requires_2fa`; wiring up a code-entry step is future work.
 */
class InstagramProvider implements SocialProvider
{
    public function platform(): string
    {
        return 'instagram';
    }

    public function label(): string
    {
        return 'Instagram';
    }

    public function getAuthorizationUrl(?User $user, string $redirectUri, string $state): string
    {
        throw new RuntimeException('Instagram does not use an OAuth redirect — connect with a username/password instead.');
    }

    public function connect(User $user, array $payload): SocialAccount
    {
        $username = $payload['username'] ?? throw new RuntimeException('Instagram username is required.');
        $password = $payload['password'] ?? throw new RuntimeException('Instagram password is required.');

        $response = Http::timeout($this->timeout())->post($this->baseUrl().'/login', [
            'username' => $username,
            'password' => $password,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Instagram automation service error: '.$response->body());
        }

        $result = $response->json();

        if (! ($result['success'] ?? false)) {
            if ($result['requires_2fa'] ?? false) {
                throw new RuntimeException('This Instagram account has two-factor authentication enabled, which isn\'t supported yet — disable 2FA temporarily to connect it.');
            }

            throw new RuntimeException($result['error'] ?? 'Instagram login failed.');
        }

        return SocialAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'external_account_id' => 'ig_'.$username,
            ],
            [
                'account_name' => '@'.$username,
                'username' => $username,
                'avatar_url' => null,
                'status' => SocialAccount::STATUS_CONNECTED,
                'access_token' => $password,
                'refresh_token' => json_encode($result['cookies'] ?? []),
                'token_expires_at' => now()->addDays(30),
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
        $caption = trim(($post->caption ?? '')."\n\n".$hashtags->map(fn ($h) => '#'.ltrim($h, '#'))->implode(' '));

        $response = Http::timeout($this->timeout())->post($this->baseUrl().'/publish', [
            'cookies' => json_decode($account->refresh_token ?? '[]', true),
            'video_path' => $videoFilePath,
            // Best-effort: forwarded to the automation service so it can set a
            // custom cover frame via Instagram's own upload UI if the script
            // supports it — silently ignored server-side otherwise, same as
            // any other extra field an older automation build doesn't read.
            'cover_path' => $coverImagePath,
            'caption' => $caption,
            'username' => $account->username,
        ]);

        if ($response->failed()) {
            return ['success' => false, 'error' => 'Instagram automation service error: '.$response->body()];
        }

        $result = $response->json();

        if (! ($result['success'] ?? false)) {
            // A rejected session cookie means it expired — flag it so a future
            // refreshToken() (or a manual reconnect) is what's needed, not a retry.
            if (str_contains((string) ($result['error'] ?? ''), 'expired')) {
                $account->update(['status' => SocialAccount::STATUS_EXPIRED]);
            }

            return ['success' => false, 'error' => $result['error'] ?? 'Instagram publish failed.'];
        }

        $postUrl = $result['post_url'] ?? null;

        return [
            'success' => true,
            'post_url' => $postUrl,
            'external_post_id' => $postUrl ? trim(parse_url($postUrl, PHP_URL_PATH) ?? '', '/') : null,
        ];
    }

    /**
     * Not implemented — scraping like/comment counts would need its own Puppeteer
     * flow on top of the publish one. Returns zeros rather than faking numbers.
     */
    public function fetchMetrics(SocialPost $post): array
    {
        return [
            'views' => 0,
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'saves' => 0,
            'watch_time' => 0.0,
            'retention' => 0.0,
        ];
    }

    public function refreshToken(SocialAccount $account): bool
    {
        if (! $account->access_token || ! $account->username) {
            return false;
        }

        $response = Http::timeout($this->timeout())->post($this->baseUrl().'/login', [
            'username' => $account->username,
            'password' => $account->access_token,
        ]);

        $result = $response->successful() ? $response->json() : ['success' => false];

        if (! ($result['success'] ?? false)) {
            $account->update(['status' => SocialAccount::STATUS_EXPIRED]);

            return false;
        }

        $account->update([
            'refresh_token' => json_encode($result['cookies'] ?? []),
            'token_expires_at' => now()->addDays(30),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        return true;
    }

    public function disconnect(SocialAccount $account): void
    {
        // No OAuth token to revoke — this is a scraped session, so disconnecting
        // is purely local (the account itself stays logged in on Instagram's side
        // until the session naturally expires or the user logs out elsewhere).
        $account->update([
            'status' => SocialAccount::STATUS_REVOKED,
            'access_token' => null,
            'refresh_token' => null,
            'auto_publish_enabled' => false,
        ]);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.instagram_automation.base_url'), '/');
    }

    private function timeout(): int
    {
        return (int) config('services.instagram_automation.timeout', 180);
    }
}
