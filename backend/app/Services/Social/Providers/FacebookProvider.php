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
 * publishes to a Page the connecting user manages. A user can manage several
 * Pages, so connect() creates one SocialAccount per Page returned by
 * /me/accounts rather than only the first.
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

    // pages_messaging is Advanced Access on Meta's side; in Development Mode it only
    // works for a Page whose connecting user is registered as this App's
    // Admin/Developer/Tester — see FacebookMessengerSync for what consumes it.
    private const SCOPES = 'pages_show_list,pages_manage_posts,pages_read_engagement,pages_messaging';

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
        $params = [
            'client_id' => $this->appId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
        ];

        // A Login Configuration (config_id) fully replaces `scope` — Meta rejects
        // the pair together on some app configs — and is what drives the
        // Business Portfolio asset-picker dialog; see config('services.facebook.
        // login_config_id') above.
        $configId = $this->loginConfigId();
        if ($configId) {
            $params['config_id'] = $configId;
        } else {
            $params['scope'] = self::SCOPES;
        }

        return sprintf(self::AUTH_URL, $this->graphVersion()).'?'.http_build_query($params);
    }

    public function connect(User $user, array $payload): SocialAccount
    {
        $code = $payload['code'] ?? throw new RuntimeException('Missing authorization code from Facebook.');
        $redirectUri = $payload['redirect_uri'] ?? throw new RuntimeException('Missing redirect_uri.');

        $shortLivedToken = $this->exchangeCodeForToken($code, $redirectUri);
        $longLivedUserToken = $this->exchangeForLongLivedToken($shortLivedToken);

        $pages = $this->fetchManagedPages($longLivedUserToken);

        Log::info('Facebook connect: /me/accounts returned', [
            'user_id' => $user->id,
            'count' => count($pages),
            'ids' => collect($pages)->pluck('id')->all(),
        ]);

        // A page missing `id`/`access_token` (Facebook omits the token for a Page
        // whose task/role grant is too narrow, or one still pending business-asset
        // approval) must not abort every OTHER page in the same batch — skip and
        // log it instead of letting an array-key error bubble out of the loop.
        $accounts = collect($pages)
            ->filter(function ($page) {
                if (empty($page['id']) || empty($page['access_token'])) {
                    Log::warning('Facebook connect: skipping Page missing id/access_token', [
                        'page_id' => $page['id'] ?? null,
                        'page_name' => $page['name'] ?? null,
                    ]);

                    return false;
                }

                return true;
            })
            ->map(fn ($page) => SocialAccount::updateOrCreate(
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
            ));

        if ($accounts->isEmpty()) {
            throw new RuntimeException('None of your Facebook Pages returned a usable access token. Check that this app has been granted access to those Pages in Business Settings.');
        }

        // Interface requires a single SocialAccount return value (used by callers
        // that connect exactly one account); every managed Page is still persisted
        // above, this just picks one to hand back.
        return $accounts->first();
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
    /**
     * `external_post_id` here is a VIDEO id (publish() posts to /{page}/videos and
     * stores the id Graph returns), so the fields available are the Video node's,
     * not the Post node's. That distinction matters: `shares` only exists on a
     * Post, and asking a Video for it fails the whole request with
     * "(#100) Tried accessing nonexisting field (shares)" — which is why no
     * Facebook post ever got metrics. Shares are reported as 0 rather than fetched;
     * getting them would mean resolving the video's owning Page post first, an
     * extra call per post per sync for one number.
     *
     * FIELDS_MINIMAL is the fallback for the same class of problem: the field set a
     * Video node exposes shifts between Graph versions (FACEBOOK_GRAPH_VERSION is
     * configurable), so rather than have one unsupported field zero out everything,
     * a field error retries with only the edges that have always been there.
     */
    private const METRIC_FIELDS = 'likes.summary(true).limit(0),comments.summary(true).limit(0),views';

    private const METRIC_FIELDS_MINIMAL = 'likes.summary(true).limit(0),comments.summary(true).limit(0)';

    public function fetchMetrics(SocialPost $post): array
    {
        $account = $post->socialAccount;

        $response = Http::get($this->graphUrl("/{$post->external_post_id}"), [
            'fields' => self::METRIC_FIELDS,
            'access_token' => $account->access_token,
        ]);

        if ($response->failed() && $this->isUnknownFieldError($response->json())) {
            Log::info('Facebook metrics: falling back to minimal field set', [
                'social_post_id' => $post->id,
                'graph_error' => $response->json('error.message'),
            ]);

            $response = Http::get($this->graphUrl("/{$post->external_post_id}"), [
                'fields' => self::METRIC_FIELDS_MINIMAL,
                'access_token' => $account->access_token,
            ]);
        }

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Facebook metrics: '.$response->body());
        }

        $data = $response->json();

        return [
            'views' => (int) ($data['views'] ?? 0),
            'likes' => (int) ($data['likes']['summary']['total_count'] ?? 0),
            'comments' => (int) ($data['comments']['summary']['total_count'] ?? 0),
            'shares' => 0,
            'saves' => 0,
            'watch_time' => 0.0,
            'retention' => 0.0,
        ];
    }

    /**
     * Graph reports an unsupported field as a generic code-100 OAuthException, so
     * the message text is the only thing that distinguishes it from a genuine
     * permission or id problem — which must keep throwing rather than silently
     * degrading to fewer metrics.
     */
    private function isUnknownFieldError(?array $body): bool
    {
        return (int) ($body['error']['code'] ?? 0) === 100
            && str_contains((string) ($body['error']['message'] ?? ''), 'nonexisting field');
    }

    public function refreshToken(SocialAccount $account): bool
    {
        if (! $account->refresh_token) {
            return false;
        }

        try {
            $pages = $this->fetchManagedPages($account->refresh_token);
            $page = collect($pages)->firstWhere('id', $account->external_account_id)
                ?? throw new RuntimeException("Facebook account no longer manages Page {$account->external_account_id}.");
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
     * @return list<array{id: string, name: string, access_token: string}>
     */
    private function fetchManagedPages(string $userToken): array
    {
        $pages = [];
        $url = $this->graphUrl('/me/accounts');
        $query = ['access_token' => $userToken, 'limit' => 100];

        // /me/accounts paginates (default page size well under what a busy Business
        // Manager account can manage) — follow `paging.next` until Facebook stops
        // returning one, or a Page beyond the first page silently never gets fetched.
        do {
            $response = Http::get($url, $query);

            if ($response->failed()) {
                throw new RuntimeException('Failed to fetch managed Facebook Pages: '.$response->body());
            }

            $pages = array_merge($pages, $response->json('data') ?? []);
            $url = $response->json('paging.next');
            $query = [];
        } while ($url);

        if (empty($pages)) {
            throw new RuntimeException('This Facebook account does not manage any Pages. Publishing requires a Page.');
        }

        return $pages;
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

    private function loginConfigId(): ?string
    {
        return config('services.facebook.login_config_id') ?: null;
    }
}
