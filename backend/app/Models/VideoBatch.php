<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VideoBatch extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id', 'name', 'status', 'settings', 'total_items', 'completed_items',
        'failed_items', 'cancel_requested', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'total_items' => 'integer',
        'completed_items' => 'integer',
        'failed_items' => 'integer',
        'cancel_requested' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VideoBatchItem::class)->orderBy('position');
    }

    /**
     * Refreshes completed/failed counters and, once every item has reached a
     * terminal status, settles the batch's own final status. Called after every
     * item settles — whether that item just finished as part of the orchestrator's
     * normal run, as a standalone retry, or (since downloading and processing now
     * run as separately-dispatched steps, see ProcessVideoBatchJob) failed during
     * the download step before a processing job ever got dispatched for it. Safe
     * to call redundantly — a no-op whenever other items are still in flight.
     */
    public function settleStatusIfAllItemsTerminal(): void
    {
        $this->update([
            'completed_items' => $this->items()->where('status', VideoBatchItem::STATUS_COMPLETED)->count(),
            'failed_items' => $this->items()->where('status', VideoBatchItem::STATUS_FAILED)->count(),
        ]);

        if ($this->status === self::STATUS_CANCELLED) {
            return;
        }

        $stillActive = $this->items()->whereNotIn('status', VideoBatchItem::TERMINAL_STATUSES)->exists();
        if ($stillActive) {
            return;
        }

        $this->refresh();
        $this->update([
            'status' => match (true) {
                $this->failed_items > 0 && $this->completed_items === 0 => self::STATUS_FAILED,
                $this->failed_items > 0 => self::STATUS_COMPLETED_WITH_ERRORS,
                default => self::STATUS_COMPLETED,
            },
            'finished_at' => now(),
        ]);
    }
}
