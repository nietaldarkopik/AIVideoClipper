<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Contracts\SocialProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Real TikTok integration via TikTok for Developers (Login Kit + Content
 * Posting API), replacing the previous AbstractMockSocialProvider stand-in.
 * Endpoints/fields below were confirmed against developers.tiktok.com's
 * published docs at implementation time; see each method for what's
 * high-confidence (directly documented) vs. best-effort (see the caveats on
 * publish()/fetchMetrics() specifically — TikTok's docs did not expose the
 * exact status/fetch response schema, so that one step is handled
 * defensively rather than assumed).
 *
 * CRITICAL CAVEAT, unlike this app's YouTube/Facebook integrations: TikTok
 * restricts any app that hasn't passed their audit to PRIVATE-only posts (or
 * routes them to the creator's TikTok inbox for manual review instead of
 * publishing directly) — see
 * https://developers.tiktok.com/doc/content-posting-api-get-started. A freshly
 * registered TikTok app is unaudited by default, so publish() here is
 * genuinely real (the video really does upload to TikTok's servers), but the
 * RESULT will not be a live public post until TikTok approves the app.
 */
class TikTokProvider implements SocialProvider
{
    private const AUTH_URL = 'https://www.tiktok.com/v2/auth/authorize/';

    private const TOKEN_URL = 'https://open.tiktokapis.com/v2/oauth/token/';

    private const REVOKE_URL = 'https://open.tiktokapis.com/v2/oauth/revoke/';

    private const USER_INFO_URL = 'https://open.tiktokapis.com/v2/user/info/';

    private const CREATOR_INFO_URL = 'https://open.tiktokapis.com/v2/post/publish/creator_info/query/';

    private const PUBLISH_INIT_URL = 'https://open.tiktokapis.com/v2/post/publish/video/init/';

    private const PUBLISH_STATUS_URL = 'https://open.tiktokapis.com/v2/post/publish/status/fetch/';

    private const VIDEO_LIST_URL = 'https://open.tiktokapis.com/v2/video/list/';

    // user.info.basic: connect() reads creator profile open_id & avatar.
    // video.publish, video.upload: Content Posting API scopes requested during authorization.
    // video.list: fetchMetrics() reads view/like/comment/share counts via video.list.
    private const SCOPES = 'user.info.basic,video.publish,video.upload,video.list';

    public function platform(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok';
    }

    public function getAuthorizationUrl(?User $user, string $redirectUri, string $state): string
    {
        // PKCE: generate code_verifier and code_challenge (S256)
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // Store code_verifier in state payload (encoded by SocialAccountController) or cache
        session(['tiktok_code_verifier_' => $codeVerifier]);

        $query = http_build_query([
            'client_key' => $this->clientKey(),
            'scope' => self::SCOPES,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ]);

        return self::AUTH_URL.'?'.$query;
    }

    public function connect(User $user, array $payload): SocialAccount
    {
        return $this->storeAccount($user, $this->exchangeAuthorizationCode($payload));
    }

    /**
     * Exchanges an OAuth `code` for tokens + the connecting TikTok profile,
     * without needing an already-known app User — split out of connect() so
     * AuthController's "Login/Sign up with TikTok" flow (no app user exists
     * yet when the code arrives) can resolve/create the user first, then call
     * storeAccount() itself. connect() above is just this + storeAccount() for
     * the ordinary "link TikTok to my existing account" case.
     *
     * @return array{open_id: string, access_token: string, refresh_token: ?string, expires_in: int, scope: string, profile: array}
     */
    public function exchangeAuthorizationCode(array $payload): array
    {
        $code = $payload['code'] ?? throw new RuntimeException('Missing authorization code from TikTok.');
        $redirectUri = $payload['redirect_uri'] ?? $this->redirectUri();
        $codeVerifier = $payload['code_verifier'] ?? session('tiktok_code_verifier_');

        $body = [
            'client_key' => $this->clientKey(),
            'client_secret' => $this->clientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ];

        if ($codeVerifier) {
            $body['code_verifier'] = $codeVerifier;
        }

        $tokenResponse = Http::asForm()->post(self::TOKEN_URL, $body);

        if ($tokenResponse->failed()) {
            throw new RuntimeException('TikTok token exchange failed: '.$tokenResponse->body());
        }

        $tokens = $tokenResponse->json();
        $accessToken = $tokens['access_token'] ?? throw new RuntimeException('TikTok did not return an access token.');
        $openId = $tokens['open_id'] ?? throw new RuntimeException('TikTok did not return an open_id.');

        return [
            'open_id' => $openId,
            'access_token' => $accessToken,
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_in' => (int) ($tokens['expires_in'] ?? 86400),
            'scope' => (string) ($tokens['scope'] ?? self::SCOPES),
            'profile' => $this->fetchUserInfo($accessToken),
        ];
    }

    /**
     * @param  array{open_id: string, access_token: string, refresh_token: ?string, expires_in: int, scope: string, profile: array}  $exchanged
     */
    public function storeAccount(User $user, array $exchanged): SocialAccount
    {
        $profile = $exchanged['profile'];

        return SocialAccount::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'external_account_id' => $exchanged['open_id'],
            ],
            [
                'account_name' => $profile['display_name'] ?? 'TikTok account',
                'username' => isset($profile['username']) ? '@'.ltrim($profile['username'], '@') : null,
                'avatar_url' => $profile['avatar_url'] ?? null,
                'status' => SocialAccount::STATUS_CONNECTED,
                'access_token' => $exchanged['access_token'],
                'refresh_token' => $exchanged['refresh_token'],
                'token_expires_at' => now()->addSeconds($exchanged['expires_in']),
                'permissions' => explode(',', $exchanged['scope']),
                'last_synced_at' => now(),
            ]
        );
    }

    /**
     * Uploads the rendered clip to TikTok via the Content Posting API's
     * FILE_UPLOAD path (init -> PUT the file to the returned upload_url), sent
     * as one single chunk — this app's rendered clips are short-form and well
     * under any practical chunk-size ceiling, so the multi-chunk path isn't
     * needed. Privacy level is picked from whatever creator_info/query actually
     * offers this account (an unaudited app is only ever offered
     * SELF_ONLY/private options — see this class's docblock) rather than
     * hardcoded, since requesting an option the account doesn't have would
     * just fail the init call.
     *
     * The status/fetch response schema (exact `status` enum values, and where a
     * final public post id lands once processing completes) is NOT clearly
     * published in TikTok's docs as of this implementation — that one check
     * below is deliberately read defensively (never used to flip a successful
     * upload into a reported failure) rather than assumed to be exactly right;
     * verify/adjust it against a real response once a real posted video exists.
     */
    public function publish(SocialPost $post, string $videoFilePath, ?string $coverImagePath = null): array
    {
        if (! file_exists($videoFilePath)) {
            return ['success' => false, 'error' => 'Rendered clip file not found.'];
        }

        // $coverImagePath is intentionally unused: TikTok's Content Posting API
        // only supports picking video_cover_timestamp_ms (a frame WITHIN the
        // uploaded video) for an organic post, not uploading an arbitrary custom
        // cover image — see this class's docblock for what's confirmed vs.
        // best-effort against TikTok's published docs.

        $account = $post->socialAccount;

        try {
            $accessToken = $this->ensureFreshToken($account);
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        try {
            $creatorInfo = $this->queryCreatorInfo($accessToken);
        } catch (RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $privacyOptions = $creatorInfo['privacy_level_options'] ?? [];
        $privacyLevel = in_array('PUBLIC_TO_EVERYONE', $privacyOptions, true)
            ? 'PUBLIC_TO_EVERYONE'
            : ($privacyOptions[0] ?? 'SELF_ONLY');

        $title = mb_substr($post->title ?: ($post->clip->title ?? 'Untitled'), 0, 150);
        $hashtags = collect($post->hashtags ?? [])->filter()->values();
        $caption = mb_substr(
            trim(($post->caption ?? '').' '.$hashtags->map(fn ($h) => '#'.ltrim($h, '#'))->implode(' ')),
            0,
            2200
        );

        $videoSize = filesize($videoFilePath);
        $clipDurationMs = (int) round(($post->clip?->duration ?? 0) * 1000);
        // TikTok's Content Posting API does NOT accept arbitrary custom cover images
        // (only video_cover_timestamp_ms — a frame WITHIN the uploaded video).
        // Pick the same 30% timestamp we use for clip.thumbnail_path — that frame
        // was deliberately chosen as a non-transitional, visually-representative
        // one in CoverGeneratorService / RenderClipJob, so it's the best available
        // frame even if we can't upload the styled/AI-generated cover itself.
        $coverTimestampMs = max(0, (int) round($clipDurationMs * 0.3));

        $buildInitPayload = fn (string $privacy) => [
            'post_info' => [
                'title' => $title !== '' ? $title : $caption,
                'privacy_level' => $privacy,
                'disable_duet' => (bool) ($creatorInfo['duet_disabled'] ?? false),
                'disable_comment' => (bool) ($creatorInfo['comment_disabled'] ?? false),
                'disable_stitch' => (bool) ($creatorInfo['stitch_disabled'] ?? false),
                // Best-effort pick-a-frame cover (the closest TikTok's organic API
                // gets to a "custom thumbnail" — silently ignored if the field
                // isn't supported on this app version / account tier).
                'video_cover_timestamp_ms' => $coverTimestampMs,
            ],
            'source_info' => [
                'source' => 'FILE_UPLOAD',
                'video_size' => $videoSize,
                'chunk_size' => $videoSize,
                'total_chunk_count' => 1,
            ],
        ];

        $initResponse = Http::withToken($accessToken)->post(self::PUBLISH_INIT_URL, $buildInitPayload($privacyLevel));

        // creator_info/query can list PUBLIC_TO_EVERYONE as an option even for an
        // unaudited app (see this class's docblock) — the real restriction only
        // surfaces here, at init time. Fall back to SELF_ONLY once rather than
        // failing the whole publish over a privacy level TikTok never actually
        // honors for this app.
        if ($privacyLevel !== 'SELF_ONLY' && $initResponse->json('error.code') === 'unaudited_client_can_only_post_to_private_accounts') {
            $privacyLevel = 'SELF_ONLY';
            $initResponse = Http::withToken($accessToken)->post(self::PUBLISH_INIT_URL, $buildInitPayload($privacyLevel));
        }

        if ($initResponse->failed() || $initResponse->json('error.code') !== 'ok') {
            return ['success' => false, 'error' => 'TikTok publish init failed: '.$initResponse->body()];
        }

        $publishId = $initResponse->json('data.publish_id');
        $uploadUrl = $initResponse->json('data.upload_url');
        if (! $publishId || ! $uploadUrl) {
            return ['success' => false, 'error' => 'TikTok did not return a publish_id/upload_url.'];
        }

        $handle = fopen($videoFilePath, 'r');
        try {
            $uploadResponse = Http::withHeaders([
                'Content-Type' => 'video/mp4',
                'Content-Range' => 'bytes 0-'.($videoSize - 1)."/{$videoSize}",
            ])->withBody(stream_get_contents($handle), 'video/mp4')->put($uploadUrl);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if ($uploadResponse->failed()) {
            return ['success' => false, 'error' => 'TikTok video upload failed: '.$uploadResponse->body()];
        }

        // Best-effort enrichment only — see this method's docblock. The upload
        // itself already succeeded above, so a parse miss here never turns a
        // real success into a reported failure.
        [$status, $publicPostId] = $this->checkPublishStatusBestEffort($accessToken, $publishId);

        $postUrl = null;
        if ($publicPostId && $account->username) {
            $postUrl = 'https://www.tiktok.com/'.$account->username.'/video/'.$publicPostId;
        }

        Log::info('TikTok clip uploaded', [
            'post_id' => $post->id, 'publish_id' => $publishId, 'status' => $status, 'privacy_level' => $privacyLevel,
        ]);

        return [
            'success' => true,
            'post_url' => $postUrl,
            'external_post_id' => $publicPostId ?? $publishId,
        ];
    }

    /**
     * Matching a SPECIFIC post's stats through TikTok's video.list is
     * best-effort: that endpoint lists the creator's own videos (paginated, no
     * documented single-video-by-id filter), so this pages through it looking
     * for `external_post_id` among the returned ids rather than fetching it
     * directly — reasonable for an account with a normal posting cadence, not
     * guaranteed for one with a very large video library. Throws (rather than
     * returning fabricated numbers, unlike the mock this replaced) when the
     * post can't be found or the API call fails, so a metrics-sync job surfaces
     * a real error instead of silently recording zeros.
     */
    public function fetchMetrics(SocialPost $post): array
    {
        $account = $post->socialAccount;
        $accessToken = $this->ensureFreshToken($account);

        if (! $post->external_post_id) {
            throw new RuntimeException('This post has no TikTok video id recorded yet.');
        }

        $cursor = 0;
        for ($page = 0; $page < 5; $page++) {
            $response = Http::withToken($accessToken)
                ->post(self::VIDEO_LIST_URL.'?fields=id,view_count,like_count,comment_count,share_count', [
                    'max_count' => 20,
                    'cursor' => $cursor,
                ]);

            if ($response->failed()) {
                throw new RuntimeException('Failed to fetch TikTok metrics: '.$response->body());
            }

            $videos = $response->json('data.videos') ?? [];
            foreach ($videos as $video) {
                if (($video['id'] ?? null) === $post->external_post_id) {
                    return [
                        'views' => (int) ($video['view_count'] ?? 0),
                        'likes' => (int) ($video['like_count'] ?? 0),
                        'comments' => (int) ($video['comment_count'] ?? 0),
                        'shares' => (int) ($video['share_count'] ?? 0),
                        // Not exposed by this endpoint per TikTok's published
                        // docs at implementation time.
                        'saves' => 0,
                        'watch_time' => 0.0,
                        'retention' => 0.0,
                    ];
                }
            }

            if (! ($response->json('data.has_more') ?? false)) {
                break;
            }
            $cursor = $response->json('data.cursor') ?? ($cursor + 20);
        }

        throw new RuntimeException('Could not find this post among the account\'s recent TikTok videos.');
    }

    public function refreshToken(SocialAccount $account): bool
    {
        if (! $account->refresh_token) {
            return false;
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_key' => $this->clientKey(),
            'client_secret' => $this->clientSecret(),
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]);

        if ($response->failed()) {
            Log::warning('TikTok token refresh failed', ['account_id' => $account->id, 'body' => $response->body()]);
            $account->update(['status' => SocialAccount::STATUS_EXPIRED]);

            return false;
        }

        $tokens = $response->json();
        $account->update([
            'access_token' => $tokens['access_token'],
            // TikTok may rotate the refresh_token on every use — keep the new
            // one if given one, otherwise the existing one stays valid.
            'refresh_token' => $tokens['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 86400)),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);

        return true;
    }

    public function disconnect(SocialAccount $account): void
    {
        if ($account->access_token) {
            // Best-effort — a failed revoke shouldn't block disconnecting locally.
            Http::asForm()->post(self::REVOKE_URL, [
                'client_key' => $this->clientKey(),
                'client_secret' => $this->clientSecret(),
                'token' => $account->access_token,
            ]);
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
            throw new RuntimeException('TikTok access token expired and could not be refreshed. Reconnect the account.');
        }

        return $account->access_token;
    }

    /**
     * @return array{display_name?: string, username?: string, avatar_url?: string}
     */
    private function fetchUserInfo(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get(self::USER_INFO_URL, ['fields' => 'open_id,avatar_url,display_name']);

            if ($response->successful()) {
                return $response->json('data.user') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('TikTok fetchUserInfo warning: '.$e->getMessage());
        }

        return [];
    }

    /**
     * @return array{privacy_level_options?: array<int, string>, comment_disabled?: bool, duet_disabled?: bool, stitch_disabled?: bool}
     */
    private function queryCreatorInfo(string $accessToken): array
    {
        // TikTok's endpoint expects a JSON *object* body ("{}"), even for a
        // no-params query — an empty PHP array here would json_encode() to "[]"
        // instead, which TikTok rejects with invalid_params ("The request
        // parameter type is incorrect").
        $response = Http::withToken($accessToken)
            ->post(self::CREATOR_INFO_URL, new \stdClass);

        if ($response->failed() || $response->json('error.code') !== 'ok') {
            throw new RuntimeException('Failed to query TikTok creator info: '.$response->body());
        }

        return $response->json('data') ?? [];
    }

    /**
     * @return array{0: ?string, 1: ?string} [status, public post id] — either may be null if the response shape didn't match what was expected (see publish()'s docblock)
     */
    private function checkPublishStatusBestEffort(string $accessToken, string $publishId): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
                ->post(self::PUBLISH_STATUS_URL, ['publish_id' => $publishId]);

            if ($response->failed()) {
                return [null, null];
            }

            $data = $response->json('data') ?? [];
            $status = $data['status'] ?? null;
            // Field name for the resulting public video id isn't confirmed from
            // docs — tries the couple of plausible shapes a "publicly available
            // post ids" field could take without assuming either is correct.
            $publicIds = $data['publicaly_available_post_id'] ?? $data['publicly_available_post_id'] ?? null;
            $publicId = is_array($publicIds) ? ($publicIds[0] ?? null) : $publicIds;

            return [$status, $publicId];
        } catch (Throwable) {
            return [null, null];
        }
    }

    private function clientKey(): string
    {
        return (string) config('services.tiktok.client_key')
            ?: throw new RuntimeException('TIKTOK_CLIENT_KEY is not set.');
    }

    private function clientSecret(): string
    {
        return (string) config('services.tiktok.client_secret')
            ?: throw new RuntimeException('TIKTOK_CLIENT_SECRET is not set.');
    }

    private function redirectUri(): string
    {
        return (string) config('services.tiktok.redirect_uri')
            ?: throw new RuntimeException('TIKTOK_REDIRECT_URI is not set.');
    }
}
