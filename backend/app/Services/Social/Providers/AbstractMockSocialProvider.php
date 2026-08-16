<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Contracts\SocialProvider;
use Illuminate\Support\Str;

abstract class AbstractMockSocialProvider implements SocialProvider
{
    /**
     * Simulates connecting an account without ever leaving the app: the frontend
     * shows a "Connect {Platform}" dialog asking for a display handle, then calls
     * the connect endpoint directly. Swap this whole class for a real OAuth
     * provider (TikTokProvider, YouTubeProvider, ...) once API keys are available —
     * everything downstream (publish jobs, controllers) only depends on the interface.
     */
    public function getAuthorizationUrl(User $user, string $redirectUri, string $state): string
    {
        return sprintf(
            '%s/social-accounts/mock-connect?platform=%s&state=%s&redirect_uri=%s',
            config('app.frontend_url'),
            $this->platform(),
            urlencode($state),
            urlencode($redirectUri)
        );
    }

    public function connect(User $user, array $payload): SocialAccount
    {
        $username = $payload['username'] ?? Str::slug($payload['account_name'] ?? 'user' . random_int(1000, 9999));
        $accountName = $payload['account_name'] ?? ('@' . $username);

        return SocialAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'external_account_id' => 'mock_' . $this->platform() . '_' . $username,
            ],
            [
                'account_name' => $accountName,
                'username' => $username,
                'avatar_url' => 'https://api.dicebear.com/9.x/initials/svg?seed=' . urlencode($accountName),
                'status' => SocialAccount::STATUS_CONNECTED,
                'access_token' => Str::random(48),
                'refresh_token' => Str::random(48),
                'token_expires_at' => now()->addDays(60),
                'permissions' => ['publish_video', 'read_insights'],
                'last_synced_at' => now(),
            ]
        );
    }

    public function publish(SocialPost $post, string $videoFilePath): array
    {
        if (! file_exists($videoFilePath)) {
            return ['success' => false, 'error' => 'Rendered clip file not found.'];
        }

        // Simulate an occasional transient failure so the retry/backoff path is exercised.
        if (random_int(1, 100) <= 6) {
            return ['success' => false, 'error' => 'Platform API timed out while uploading.'];
        }

        $externalId = strtoupper(Str::random(10));

        return [
            'success' => true,
            'post_url' => $this->buildPostUrl($post, $externalId),
            'external_post_id' => $externalId,
        ];
    }

    public function fetchMetrics(SocialPost $post): array
    {
        $ageHours = $post->published_at ? abs($post->published_at->diffInHours(now())) : 1;
        $reach = min(500000, (int) (pow($ageHours + 1, 1.6) * random_int(80, 400)));

        return [
            'views' => $reach,
            'likes' => (int) round($reach * (random_int(4, 9) / 100)),
            'comments' => (int) round($reach * (random_int(1, 3) / 1000)),
            'shares' => (int) round($reach * (random_int(2, 6) / 1000)),
            'saves' => (int) round($reach * (random_int(2, 8) / 1000)),
            'watch_time' => round(($post->clip->duration ?? 30) * (random_int(55, 92) / 100), 1),
            'retention' => round(random_int(45, 92) + (random_int(0, 99) / 100), 2),
        ];
    }

    public function refreshToken(SocialAccount $account): bool
    {
        $account->update([
            'access_token' => Str::random(48),
            'token_expires_at' => now()->addDays(60),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        return true;
    }

    public function disconnect(SocialAccount $account): void
    {
        $account->update([
            'status' => SocialAccount::STATUS_REVOKED,
            'access_token' => null,
            'refresh_token' => null,
            'auto_publish_enabled' => false,
        ]);
    }

    abstract protected function buildPostUrl(SocialPost $post, string $externalId): string;
}
