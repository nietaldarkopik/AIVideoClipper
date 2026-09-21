<?php

namespace App\Console\Commands;

use App\Services\Social\SocialTokenRefresher;
use Illuminate\Console\Command;

/**
 * Renews OAuth tokens that are about to expire (see SocialTokenRefresher for the
 * renewal window and why lazy refresh at publish time wasn't enough).
 *
 * Scheduled hourly from bootstrap/app.php; requires `php artisan schedule:work`
 * to actually be running (start-all.ps1/.bat starts it), same caveat as
 * jobs:reap-stalled and channels:poll.
 */
class RefreshSocialTokens extends Command
{
    protected $signature = 'social:refresh-tokens';

    protected $description = 'Renew social account OAuth tokens before they expire';

    public function handle(SocialTokenRefresher $refresher): int
    {
        $accounts = $refresher->dueQuery()->get();

        if ($accounts->isEmpty()) {
            $this->info('No tokens due for renewal.');

            return self::SUCCESS;
        }

        $renewed = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            if ($refresher->refreshOne($account)) {
                $renewed++;
                continue;
            }

            $failed++;
            $this->warn("Could not refresh {$account->platform} account #{$account->id} ({$account->account_name}) — it may need reconnecting.");
        }

        $this->info("Renewed {$renewed} token(s).");

        // Deliberately SUCCESS even with failures: an account whose grant the user
        // revoked will fail every hour, and a red scheduled task every hour trains
        // people to ignore it. The account's own status is what surfaces this.
        return self::SUCCESS;
    }
}
