<?php

namespace App\Services\Social\Providers;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Contracts\SocialProvider;
use App\Services\Video\FFmpegService;
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

    // Shared host for every "media upload" endpoint (videos.insert, thumbnails.set,
    // ...) — distinct from API_BASE, which is for plain JSON calls (videos.list,
    // channels.list). Mixing the two up is what caused thumbnails.set to 404/400.
    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/youtube/v3';

    // youtube.upload: upload videos + upload custom thumbnails via thumbnails.set
    // youtube:          broader channel management, needed on some accounts for
    //                   thumbnails.set to return 200 instead of 403/insufficientPermissions
    // youtube.readonly: fetch own channel on connect() + metrics fetch
    private const SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube https://www.googleapis.com/auth/youtube.readonly';

    public function __construct(private readonly FFmpegService $ffmpeg) {}

    public function platform(): string
    {
        return 'youtube';
    }

    public function label(): string
    {
        return 'YouTube';
    }

    public function getAuthorizationUrl(?User $user, string $redirectUri, string $state): string
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

        return self::AUTH_URL.'?'.$query;
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
            throw new RuntimeException('Google token exchange failed: '.$tokenResponse->body());
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

    public function publish(SocialPost $post, string $videoFilePath, ?string $coverImagePath = null): array
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
            trim(($post->caption ?? '')."\n\n".$hashtags->map(fn ($h) => '#'.ltrim($h, '#'))->implode(' ')),
            0,
            5000
        );

        $startResponse = Http::withToken($accessToken)
            ->withHeaders([
                'X-Upload-Content-Type' => 'video/*',
                'X-Upload-Content-Length' => (string) filesize($videoFilePath),
            ])
            ->post(self::UPLOAD_URL.'?uploadType=resumable&part=snippet,status', [
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
            return ['success' => false, 'error' => 'Failed to start YouTube upload: '.$startResponse->body()];
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
            return ['success' => false, 'error' => 'YouTube upload failed: '.$uploadResponse->body()];
        }

        $videoId = $uploadResponse->json('id');
        if (! $videoId) {
            return ['success' => false, 'error' => 'YouTube did not return a video id after upload.'];
        }

        $thumbnailResult = $coverImagePath
            ? $this->uploadThumbnail($accessToken, $videoId, $coverImagePath)
            : null;

        return [
            'success' => true,
            'post_url' => "https://www.youtube.com/shorts/{$videoId}",
            'external_post_id' => $videoId,
            'thumbnail_status' => $thumbnailResult === null
                ? null
                : ($thumbnailResult['success'] ? SocialPost::THUMBNAIL_STATUS_UPLOADED : SocialPost::THUMBNAIL_STATUS_FAILED),
            'thumbnail_error' => $thumbnailResult['error'] ?? null,
        ];
    }

    /**
     * Re-applies a custom thumbnail after YouTube has actually finished processing
     * the video.
     *
     * Why this exists: thumbnails.set called right after upload (inside publish()
     * above) is accepted with a 200 and shows correctly in Studio immediately —
     * but YouTube's own transcode/analysis pipeline keeps running in the
     * background for a while after that, and once it finishes it can silently
     * overwrite a thumbnail that was set too early with an auto-picked video
     * frame. There is no webhook for "processing finished", so
     * ReapplyYoutubeThumbnailJob polls this method with backoff and re-sets the
     * thumbnail once the video is actually done — which is the point after which
     * YouTube stops touching it on its own.
     *
     * @return array{done: bool, success?: bool, error?: ?string, request?: array, response?: ?array}
     */
    public function reapplyThumbnail(SocialAccount $account, string $videoId, string $coverImagePath): array
    {
        if (! file_exists($coverImagePath)) {
            return [
                'done' => true, 'success' => false, 'error' => 'Cover image file no longer exists.',
                'request' => ['video_id' => $videoId, 'cover_path' => $coverImagePath],
            ];
        }

        try {
            $accessToken = $this->ensureFreshToken($account);
        } catch (RuntimeException $e) {
            // Account disconnected/token dead — nothing to retry against.
            return [
                'done' => true, 'success' => false, 'error' => $e->getMessage(),
                'request' => ['video_id' => $videoId, 'social_account_id' => $account->id],
            ];
        }

        $status = $this->fetchProcessingStatus($accessToken, $videoId);

        // No status at all (video deleted, or a transient API error) means there is
        // nothing more this can do — stop polling rather than retrying forever.
        if ($status === null) {
            return [
                'done' => true, 'success' => false,
                'error' => 'Could not fetch video processing status (video may have been deleted).',
                'request' => ['endpoint' => self::API_BASE.'/videos', 'video_id' => $videoId],
            ];
        }

        // uploadStatus stays 'uploaded' (not yet 'processed') while YouTube's own
        // pipeline is still running, which is exactly the window during which it
        // can still reset the thumbnail. Once that pipeline finishes, re-setting it
        // sticks for good.
        if ($status['upload_status'] === 'uploaded' && $status['processing_status'] !== 'failed') {
            return ['done' => false];
        }

        $result = $this->uploadThumbnail($accessToken, $videoId, $coverImagePath);

        return [
            'done' => true,
            'success' => $result['success'],
            'error' => $result['error'],
            'request' => $result['request'] + ['video_processing_status' => $status],
            'response' => $result['response'],
        ];
    }

    /**
     * @return array{upload_status: ?string, processing_status: ?string}|null
     */
    private function fetchProcessingStatus(string $accessToken, string $videoId): ?array
    {
        $response = Http::withToken($accessToken)
            ->get(self::API_BASE.'/videos', [
                'part' => 'status,processingDetails',
                'id' => $videoId,
            ]);

        if ($response->failed()) {
            return null;
        }

        $item = $response->json('items.0');
        if (! $item) {
            return null;
        }

        return [
            'upload_status' => $item['status']['uploadStatus'] ?? null,
            'processing_status' => $item['processingDetails']['processingStatus'] ?? null,
        ];
    }

    /**
     * Validates and uploads one custom thumbnail. Used both right after upload
     * (publish()) and by the later reapplyThumbnail() re-check — the validation
     * and request shape must stay identical between the two call sites, since a
     * cover that was fine the first time is still the same file the second time.
     *
     * @return array{success: bool, error: ?string, request: array, response: ?array}
     */
    private function uploadThumbnail(string $accessToken, string $videoId, string $coverImagePath): array
    {
        $endpoint = self::UPLOAD_BASE.'/thumbnails/set?videoId='.$videoId;

        if (! file_exists($coverImagePath)) {
            return [
                'success' => false,
                'error' => 'Cover image file not found.',
                'request' => ['endpoint' => $endpoint, 'video_id' => $videoId, 'cover_path' => $coverImagePath],
                'response' => null,
            ];
        }

        // thumbnails.set only ever produces the classic 16:9 derivative sizes
        // (see the fixed set YouTube always returns: 120x90 up to 2560x1440) — a
        // raw vertical Shorts cover uploaded as-is gets pillarboxed by YouTube
        // itself against a plain blurred VIDEO frame. Composite our own
        // blurred-cover pillarbox first so the padding matches the cover's own
        // branding instead. Falls back to the original file if ffmpeg fails for
        // any reason — a slightly-cropped upload beats none at all.
        $landscapePath = null;
        try {
            $dimensions = @getimagesize($coverImagePath);
            if ($dimensions && $dimensions[1] > $dimensions[0]) {
                $landscapePath = sys_get_temp_dir().'/yt_thumb_'.uniqid().'.jpg';
                $this->ffmpeg->renderPillarboxedLandscapeThumbnail($coverImagePath, $landscapePath);
                $coverImagePath = $landscapePath;
            }
        } catch (\Throwable $e) {
            Log::warning('YouTube thumbnail: pillarbox composite failed, uploading original cover as-is', [
                'video_id' => $videoId, 'error' => $e->getMessage(),
            ]);
            $landscapePath = null;
        }

        try {
            return $this->uploadThumbnailFile($accessToken, $videoId, $coverImagePath, $endpoint);
        } finally {
            if ($landscapePath && file_exists($landscapePath)) {
                @unlink($landscapePath);
            }
        }
    }

    /**
     * @return array{success: bool, error: ?string, request: array, response: ?array}
     */
    private function uploadThumbnailFile(string $accessToken, string $videoId, string $coverImagePath, string $endpoint): array
    {
        // YouTube thumbnail requirements (from developers.google.com/youtube/v3/docs/thumbnails/set):
        //  - Max file size: 2 MB
        //  - Accepted formats: JPG, PNG, GIF (first frame), BMP, WebP
        //  - Recommended resolution: 1280x720 (min 640x360), aspect ratio 16:9
        //  - Channel must be phone-verified, otherwise thumbnails.set returns 403 forbidden
        $coverSize = filesize($coverImagePath);
        $baseRequest = ['endpoint' => $endpoint, 'video_id' => $videoId, 'cover_path' => $coverImagePath];

        if ($coverSize === false) {
            Log::warning('YouTube thumbnail skipped — could not stat cover file', [
                'video_id' => $videoId, 'cover_path' => $coverImagePath,
            ]);

            return ['success' => false, 'error' => 'Could not read cover image file.', 'request' => $baseRequest, 'response' => null];
        }

        if ($coverSize > 2 * 1024 * 1024) {
            Log::warning('YouTube thumbnail skipped — cover file exceeds 2MB limit, publishing without custom cover', [
                'video_id' => $videoId, 'cover_size_bytes' => $coverSize, 'cover_path' => $coverImagePath,
            ]);

            return [
                'success' => false,
                'error' => 'Cover image exceeds YouTube\'s 2MB thumbnail limit.',
                'request' => $baseRequest + ['cover_size_bytes' => $coverSize],
                'response' => null,
            ];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $coverImagePath) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        $acceptedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
        if ($mime && ! in_array($mime, $acceptedMime, true)) {
            Log::warning('YouTube thumbnail skipped — cover file has non-image MIME type', [
                'video_id' => $videoId, 'mime' => $mime, 'cover_path' => $coverImagePath,
            ]);

            return [
                'success' => false,
                'error' => "Cover image has an unsupported file type ({$mime}).",
                'request' => $baseRequest + ['cover_size_bytes' => $coverSize, 'mime' => $mime],
                'response' => null,
            ];
        }

        // thumbnails.set is a "simple media upload": the request body IS the raw
        // image bytes with Content-Type set to the image's mime type — NOT a
        // multipart/form-data field. Sending it via ->attach() produces Google's
        // "mediaBodyRequired" 400 every time, because it's looking for the raw
        // body, not a form field named "media". It also lives under the /upload/
        // host, not API_BASE — the plain API_BASE path 404s independently of the
        // body format. Read fully into memory rather than streaming a file handle:
        // the 2MB cap enforced above makes that cheap, and it avoids Guzzle needing
        // a seekable/rewindable stream to retry or record the request.
        $requestMeta = $baseRequest + [
            'cover_size_bytes' => $coverSize,
            'mime' => $mime ?: 'unknown',
            'method' => 'POST',
        ];

        $thumbResponse = Http::withToken($accessToken)
            ->withBody(file_get_contents($coverImagePath), $mime ?: 'image/jpeg')
            ->post($endpoint);

        $responseMeta = [
            'status' => $thumbResponse->status(),
            'body' => $this->truncateForStorage($thumbResponse->body()),
        ];

        if ($thumbResponse->failed()) {
            Log::warning('YouTube thumbnail upload failed', [
                'video_id' => $videoId,
                'error' => $thumbResponse->body(),
                'status' => $thumbResponse->status(),
                'cover_size_bytes' => $coverSize,
                'mime' => $mime ?: 'unknown',
            ]);

            return [
                'success' => false,
                'error' => 'YouTube rejected the thumbnail upload: '.$thumbResponse->body(),
                'request' => $requestMeta,
                'response' => $responseMeta,
            ];
        }

        Log::info('YouTube thumbnail uploaded successfully', [
            'video_id' => $videoId,
            'cover_size_bytes' => $coverSize,
            'mime' => $mime ?: 'unknown',
        ]);

        return ['success' => true, 'error' => null, 'request' => $requestMeta, 'response' => $responseMeta];
    }

    /**
     * Keeps a stored response body from ballooning the processing_jobs row — the
     * useful debug info (status, error message) is always near the start of a
     * YouTube error body, and successful thumbnails.set bodies are already small.
     */
    private function truncateForStorage(string $body, int $limit = 4000): string
    {
        return mb_strlen($body) > $limit ? mb_substr($body, 0, $limit).'... (truncated)' : $body;
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
            ->get(self::API_BASE.'/videos', [
                'part' => 'statistics',
                'id' => $post->external_post_id,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch YouTube metrics: '.$response->body());
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
            ->get(self::API_BASE.'/channels', [
                'part' => 'snippet',
                'mine' => 'true',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch YouTube channel info: '.$response->body());
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
