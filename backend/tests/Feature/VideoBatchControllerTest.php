<?php

namespace Tests\Feature;

use App\Jobs\ProcessBatchItemJob;
use App\Jobs\ProcessVideoBatchJob;
use App\Models\User;
use App\Models\VideoBatch;
use App\Models\VideoBatchItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VideoBatchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/video-batches', ['urls' => ['https://example.com/a']])
            ->assertUnauthorized();
    }

    public function test_store_rejects_empty_url_list(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/video-batches', ['urls' => []])
            ->assertUnprocessable();
    }

    public function test_store_creates_batch_with_ordered_items_and_dispatches_the_worker(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/video-batches', [
            'urls' => ['https://youtube.com/watch?v=one', ' https://youtube.com/watch?v=two ', ''],
            'clip_mode' => 'top_3',
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data.items');
        $response->assertJsonPath('data.total_items', 2);
        $response->assertJsonPath('data.items.0.source_url', 'https://youtube.com/watch?v=one');
        $response->assertJsonPath('data.items.1.source_url', 'https://youtube.com/watch?v=two');
        $response->assertJsonPath('data.settings.clip_mode', 'top_3');

        $batch = VideoBatch::firstOrFail();
        $this->assertSame($user->id, $batch->user_id);

        Queue::assertPushed(ProcessVideoBatchJob::class, fn ($job) => $job->videoBatchId === $batch->id);
    }

    public function test_users_cannot_see_each_others_batches(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $batch = $owner->videoBatches()->create([
            'status' => VideoBatch::STATUS_COMPLETED,
            'settings' => [],
        ]);

        $this->actingAs($stranger)
            ->getJson("/api/video-batches/{$batch->id}")
            ->assertNotFound();

        $this->actingAs($owner)
            ->getJson("/api/video-batches/{$batch->id}")
            ->assertOk();
    }

    public function test_cancel_marks_a_pending_batch_cancelled_immediately(): void
    {
        $user = User::factory()->create();
        $batch = $user->videoBatches()->create([
            'status' => VideoBatch::STATUS_PENDING,
            'settings' => [],
        ]);

        $this->actingAs($user)
            ->postJson("/api/video-batches/{$batch->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', VideoBatch::STATUS_CANCELLED);
    }

    public function test_cancel_flags_a_running_batch_instead_of_stopping_it_immediately(): void
    {
        $user = User::factory()->create();
        $batch = $user->videoBatches()->create([
            'status' => VideoBatch::STATUS_RUNNING,
            'settings' => [],
        ]);

        $this->actingAs($user)
            ->postJson("/api/video-batches/{$batch->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', VideoBatch::STATUS_RUNNING);

        $this->assertTrue($batch->fresh()->cancel_requested);
    }

    public function test_retry_item_requires_the_item_to_have_failed(): void
    {
        $user = User::factory()->create();
        $batch = $user->videoBatches()->create(['status' => VideoBatch::STATUS_COMPLETED, 'settings' => []]);
        $item = $batch->items()->create(['position' => 0, 'source_url' => 'https://x.test/a', 'status' => VideoBatchItem::STATUS_COMPLETED]);

        $this->actingAs($user)
            ->postJson("/api/video-batches/{$batch->id}/items/{$item->id}/retry")
            ->assertUnprocessable();
    }

    public function test_retry_item_resets_it_and_dispatches_the_item_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $batch = $user->videoBatches()->create(['status' => VideoBatch::STATUS_COMPLETED_WITH_ERRORS, 'settings' => []]);
        $item = $batch->items()->create([
            'position' => 0, 'source_url' => 'https://x.test/a',
            'status' => VideoBatchItem::STATUS_FAILED, 'failure_reason' => 'HTTP 403',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/video-batches/{$batch->id}/items/{$item->id}/retry")
            ->assertOk();

        $response->assertJsonPath('data.status', VideoBatchItem::STATUS_PENDING);
        $this->assertNull($item->fresh()->failure_reason);
        $this->assertSame(VideoBatch::STATUS_RUNNING, $batch->fresh()->status);

        Queue::assertPushed(ProcessBatchItemJob::class, fn ($job) => $job->videoBatchItemId === $item->id);
    }

    public function test_users_cannot_retry_items_on_each_others_batches(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $batch = $owner->videoBatches()->create(['status' => VideoBatch::STATUS_COMPLETED_WITH_ERRORS, 'settings' => []]);
        $item = $batch->items()->create(['position' => 0, 'source_url' => 'https://x.test/a', 'status' => VideoBatchItem::STATUS_FAILED]);

        $this->actingAs($stranger)
            ->postJson("/api/video-batches/{$batch->id}/items/{$item->id}/retry")
            ->assertNotFound();
    }
}
