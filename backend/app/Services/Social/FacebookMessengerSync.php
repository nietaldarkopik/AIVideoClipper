<?php

namespace App\Services\Social;

use App\Models\FacebookMessage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pulls inbound Messenger messages for one connected Facebook Page into
 * `facebook_messages`, for content-research use (e.g. a confession/story-submission
 * Page whose DMs are raw material for clips) — not a customer-service inbox.
 *
 * Requires the Page's stored access_token to include the `pages_messaging` scope
 * (see FacebookProvider::SCOPES) and, while the app is in Development Mode, the
 * connecting Facebook user to be a registered Admin/Developer/Tester on this app —
 * otherwise Graph silently returns no conversations rather than an error.
 */
class FacebookMessengerSync
{
    /**
     * @return int number of new messages inserted
     */
    public function syncAccount(SocialAccount $account): int
    {
        $inserted = 0;
        $url = $this->graphUrl("/{$account->external_account_id}/conversations");
        $query = [
            'access_token' => $account->access_token,
            'fields' => 'id,updated_time,messages.limit(50){id,message,from,created_time}',
            'limit' => 25,
        ];

        do {
            $response = Http::get($url, $query);

            if ($response->failed()) {
                throw new RuntimeException('Failed to fetch Facebook conversations: '.$response->body());
            }

            foreach ($response->json('data') ?? [] as $conversation) {
                foreach ($conversation['messages']['data'] ?? [] as $message) {
                    // Skip the Page's own replies — only inbound messages from the
                    // public are content-research material.
                    if (($message['from']['id'] ?? null) === $account->external_account_id) {
                        continue;
                    }

                    if (empty($message['id'])) {
                        continue;
                    }

                    $row = FacebookMessage::updateOrCreate(
                        [
                            'social_account_id' => $account->id,
                            'external_message_id' => $message['id'],
                        ],
                        [
                            'external_conversation_id' => $conversation['id'],
                            'sender_name' => $message['from']['name'] ?? null,
                            'sender_psid' => $message['from']['id'] ?? null,
                            'message' => $message['message'] ?? null,
                            'sent_at' => $message['created_time'] ?? null,
                        ]
                    );

                    if ($row->wasRecentlyCreated) {
                        $inserted++;
                    }
                }
            }

            $url = $response->json('paging.next');
            $query = [];
        } while ($url);

        $account->update(['messenger_last_synced_at' => now()]);

        Log::info('Facebook messenger sync complete', [
            'social_account_id' => $account->id,
            'inserted' => $inserted,
        ]);

        return $inserted;
    }

    private function graphUrl(string $path): string
    {
        $version = (string) config('services.facebook.graph_version', 'v21.0');

        return "https://graph.facebook.com/{$version}{$path}";
    }
}
