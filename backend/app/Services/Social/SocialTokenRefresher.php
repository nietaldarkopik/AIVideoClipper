<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renews OAuth access tokens before they expire, rather than after a publish has
 * already failed because of one.
 *
 * Providers do refresh a token lazily when they're about to use it (see
 * YouTubeProvider::ensureFreshToken), but that only helps if something calls the
 * provider in time. Auto-publish fires from a queued job at whatever hour the
 * scheduler picked — commonly overnight — and a token that expired hours earlier
 * turned into a failed post the user only discovered the next morning. Refreshing
 * on a schedule keeps every connected account usable at the moment its slot comes
 * up.
 */
class SocialTokenRefresher
{
    /**
     * Refresh this far ahead of actual expiry. Wide enough that an account is
     * always renewed at least a couple of hourly ticks before it would lapse, so
     * one failed attempt (a blip at the platform) still leaves room to retry
     * before anything downstream notices.
     */
    private const RENEW_BEFORE_HOURS = 6;

    public function __construct(private readonly SocialProviderManager $providers) {}

    /**
     * Accounts worth a refresh call: connected, holding a refresh token, and
     * within the renewal window. Accounts already marked expired/revoked are
     * skipped — a token refresh can't fix a user-revoked grant, and retrying one
     * every hour forever just burns API calls; those need a reconnect from the
     * Social Accounts page.
     */
    public function dueQuery(): Builder
    {
        return SocialAccount::query()
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->whereNotNull('refresh_token')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addHours(self::RENEW_BEFORE_HOURS))
            ->orderBy('token_expires_at');
    }

    /**
     * Returns whether the token was actually renewed. Providers mark the account
     * expired themselves when the platform rejects the refresh token, which is
     * what surfaces the "reconnect this account" state in the UI.
     */
    public function refreshOne(SocialAccount $account): bool
    {
        try {
            return $this->providers->resolve($account->platform)->refreshToken($account);
        } catch (Throwable $e) {
            Log::warning('Scheduled token refresh failed', [
                'social_account_id' => $account->id,
                'platform' => $account->platform,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
