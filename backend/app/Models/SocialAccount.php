<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialAccount extends Model
{
    use HasFactory;

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'user_id', 'platform', 'account_name', 'username', 'avatar_url',
        'external_account_id', 'status', 'access_token', 'refresh_token',
        'token_expires_at', 'permissions', 'auto_publish_enabled', 'last_synced_at',
        'default_cover_template_id',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'permissions' => 'array',
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'auto_publish_enabled' => 'boolean',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialPosts(): HasMany
    {
        return $this->hasMany(SocialPost::class);
    }

    public function defaultCoverTemplate(): BelongsTo
    {
        return $this->belongsTo(CoverTemplate::class, 'default_cover_template_id');
    }
}
