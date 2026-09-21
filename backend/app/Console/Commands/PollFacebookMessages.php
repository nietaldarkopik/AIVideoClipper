<?php

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Services\Social\FacebookMessengerSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls new Messenger messages for every Facebook Page opted into message sync
 * (SocialAccount::messenger_sync_enabled), for content-research use — see
 * FacebookMessengerSync.
 *
 * Scheduled every 15 minutes from bootstrap/app.php, same as channels:poll.
 */
class PollFacebookMessages extends Command
{
    protected $signature = 'messenger:poll';

    protected $description = 'Sync new Messenger messages for Facebook Pages opted into message-sync';

    public function handle(FacebookMessengerSync $sync): int
    {
        $checked = 0;

        SocialAccount::query()
            ->where('platform', 'facebook')
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->where('messenger_sync_enabled', true)
            ->chunkById(50, function ($accounts) use ($sync, &$checked) {
                foreach ($accounts as $account) {
                    // One Page's Graph API error (expired token, revoked
                    // pages_messaging) must not stop the rest of the batch.
                    try {
                        $sync->syncAccount($account);
                    } catch (Throwable $e) {
                        Log::warning('Facebook messenger sync failed', [
                            'social_account_id' => $account->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    $checked++;
                }
            });

        $this->info("Checked {$checked} Facebook Page(s) for new messages.");

        return self::SUCCESS;
    }
}
