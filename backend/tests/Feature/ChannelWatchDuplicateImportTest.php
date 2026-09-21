<?php

namespace Tests\Feature;

use App\Models\ChannelWatch;
use App\Models\User;
use App\Models\VideoBatch;
use App\Models\VideoBatchItem;
use App\Services\Channel\ChannelWatchPoller;
use App\Services\Channel\YouTubeChannelMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A channel watch decides "is this upload new?" with one timestamp comparison
 * against a value that round-trips through the database. That round trip is
 * fragile: Eloquent writes a Carbon's wall clock verbatim and reads it back as
 * the app timezone, so a UTC-flavoured timestamp stored under a non-UTC app
 * timezone comes back as a different instant — which made every poll re-import
 * the same uploads, each one a fresh project and a fresh multi-hundred-MB
 * download.
 */
class ChannelWatchDuplicateImportTest extends TestCase
{
    use RefreshDatabase;

    private const VIDEO_URL = 'https://www.youtube.com/watch?v=abc123XYZ';

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        // A non-UTC app timezone is the whole point of these tests, and it has to
        // be set the way booting does it: config() alone leaves PHP's default
        // timezone untouched, and that default is what Eloquent reads a datetime
        // column back with — so without this the test wouldn't reproduce
        // production's round trip at all.
        $this->originalTimezone = date_default_timezone_get();
        config([
            'app.timezone' => 'Asia/Jakarta',
            'services.trending.youtube_api_key' => 'test-key',
        ]);
        date_default_timezone_set('Asia/Jakarta');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);

        parent::tearDown();
    }

    private function fakeYouTube(string $publishedAtUtc = '2026-09-05T15:08:48Z'): void
    {
        Http::fake([
            '*/playlistItems*' => Http::response([
                'items' => [[
                    'contentDetails' => ['videoId' => 'abc123XYZ'],
                    'snippet' => ['title' => 'An upload', 'publishedAt' => $publishedAtUtc],
                ]],
            ]),
            // Longer than the Shorts cutoff so it isn't filtered out.
            '*/videos*' => Http::response([
                'items' => [['id' => 'abc123XYZ', 'contentDetails' => ['duration' => 'PT10M']]],
            ]),
        ]);
    }

    private function watch(User $user): ChannelWatch
    {
        return ChannelWatch::create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'channel_id' => 'UC_test',
            'channel_title' => 'Test Channel',
            'channel_url' => 'https://www.youtube.com/channel/UC_test',
            'uploads_playlist_id' => 'UU_test',
            'is_active' => true,
        ]);
    }

    public function test_a_published_timestamp_survives_the_database_round_trip(): void
    {
        $this->fakeYouTube();

        $videos = app(YouTubeChannelMonitor::class)->fetchNewUploads('UU_test', null);
        $publishedAt = $videos[0]['published_at'];

        $watch = $this->watch(User::factory()->create());
        $watch->update(['last_video_published_at' => $publishedAt]);

        // The same instant must come back out — not the same wall clock under a
        // different offset, which is what silently moved the watermark 7 hours.
        $this->assertSame(
            $publishedAt->getTimestamp(),
            $watch->fresh()->last_video_published_at->getTimestamp(),
            'The stored watermark is a different instant than the API reported.'
        );
    }

    public function test_an_upload_already_at_the_watermark_is_not_treated_as_new(): void
    {
        $this->fakeYouTube();
        $monitor = app(YouTubeChannelMonitor::class);

        $watch = $this->watch(User::factory()->create());
        $watch->update(['last_video_published_at' => $monitor->fetchNewUploads('UU_test', null)[0]['published_at']]);

        $stillNew = $monitor->fetchNewUploads('UU_test', $watch->fresh()->last_video_published_at);

        $this->assertEmpty($stillNew, 'The same upload came back as "new" on the next poll.');
    }

    public function test_a_video_already_imported_is_skipped_instead_of_queued_again(): void
    {
        Queue::fake();
        $this->fakeYouTube();

        $user = User::factory()->create();
        $watch = $this->watch($user);

        // Simulate the earlier import: a batch item for this URL already exists.
        $batch = $user->videoBatches()->create(['status' => VideoBatch::STATUS_PENDING, 'total_items' => 1]);
        $batch->items()->create([
            'position' => 0, 'source_url' => self::VIDEO_URL, 'status' => VideoBatchItem::STATUS_PENDING,
        ]);

        app(ChannelWatchPoller::class)->pollOne($watch);

        $this->assertSame(1, VideoBatchItem::where('source_url', self::VIDEO_URL)->count(), 'The video was imported a second time.');
        // Skipping must still move the watermark on, or the same video gets
        // re-examined on every poll forever.
        $this->assertSame('abc123XYZ', $watch->fresh()->last_video_id);
    }

    public function test_a_genuinely_new_video_is_still_imported(): void
    {
        Queue::fake();
        $this->fakeYouTube();

        $user = User::factory()->create();
        $watch = $this->watch($user);

        app(ChannelWatchPoller::class)->pollOne($watch);

        $this->assertSame(1, VideoBatchItem::where('source_url', self::VIDEO_URL)->count());
        $this->assertSame('abc123XYZ', $watch->fresh()->last_video_id);
    }

    public function test_another_users_import_does_not_block_this_users(): void
    {
        Queue::fake();
        $this->fakeYouTube();

        $other = User::factory()->create();
        $otherBatch = $other->videoBatches()->create(['status' => VideoBatch::STATUS_PENDING, 'total_items' => 1]);
        $otherBatch->items()->create([
            'position' => 0, 'source_url' => self::VIDEO_URL, 'status' => VideoBatchItem::STATUS_PENDING,
        ]);

        $user = User::factory()->create();
        app(ChannelWatchPoller::class)->pollOne($this->watch($user));

        $this->assertSame(2, VideoBatchItem::where('source_url', self::VIDEO_URL)->count());
    }
}
