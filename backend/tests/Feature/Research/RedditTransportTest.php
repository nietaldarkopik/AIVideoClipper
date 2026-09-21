<?php

namespace Tests\Feature\Research;

use App\Services\Research\DTOs\ResearchQuery;
use App\Services\Research\Providers\RedditResearchProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reddit blocks its public .json endpoints from datacenter and VPN IP ranges with
 * a flat 403, while answering normally on the OAuth API from the same address —
 * so the provider picks its transport from whether credentials exist. These lock
 * that choice, and the token reuse that keeps a run inside the rate limit.
 */
class RedditTransportTest extends TestCase
{
    use RefreshDatabase;

    private function listingResponse(string $title = 'Judul curhat'): array
    {
        return [
            'data' => [
                'children' => [
                    ['data' => [
                        'id' => 'abc123',
                        'title' => $title,
                        'permalink' => '/r/Advice/comments/abc123/judul/',
                        'score' => 500,
                        'num_comments' => 42,
                        'selftext' => 'Isi ceritanya.',
                        'author' => 'someone',
                        'subreddit' => 'Advice',
                    ]],
                ],
            ],
        ];
    }

    private function query(): ResearchQuery
    {
        return new ResearchQuery(
            keywords: ['curhat'],
            language: 'id',
            region: 'ID',
            limit: 5,
            config: ['subreddits' => ['Advice'], 'min_score' => 10, 'listing' => 'hot'],
        );
    }

    public function test_without_credentials_it_uses_the_public_json_endpoint(): void
    {
        config(['research.providers.reddit.client_id' => null]);
        config(['research.providers.reddit.client_secret' => null]);

        Http::fake(['www.reddit.com/*' => Http::response($this->listingResponse())]);

        $items = app(RedditResearchProvider::class)->search($this->query());

        $this->assertCount(1, $items);
        // The public API needs the .json suffix, and no token is ever requested.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'www.reddit.com/r/Advice/hot.json'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'access_token'));
    }

    public function test_with_credentials_it_authenticates_and_uses_the_oauth_host(): void
    {
        config(['research.providers.reddit.client_id' => 'id-123']);
        config(['research.providers.reddit.client_secret' => 'secret-456']);

        Http::fake([
            'www.reddit.com/api/v1/access_token' => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600]),
            'oauth.reddit.com/*' => Http::response($this->listingResponse()),
        ]);

        $items = app(RedditResearchProvider::class)->search($this->query());

        $this->assertCount(1, $items);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'access_token'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth.reddit.com/r/Advice/hot')
            // The OAuth API rejects the .json suffix the public one requires.
            && ! str_contains($request->url(), '.json')
            && $request->hasHeader('Authorization', 'Bearer tok-abc'));
    }

    public function test_the_token_is_reused_across_subreddits_in_one_run(): void
    {
        config(['research.providers.reddit.client_id' => 'id-123']);
        config(['research.providers.reddit.client_secret' => 'secret-456']);

        Http::fake([
            'www.reddit.com/api/v1/access_token' => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600]),
            'oauth.reddit.com/*' => Http::response($this->listingResponse()),
        ]);

        $query = new ResearchQuery(
            keywords: ['curhat'], language: 'id', region: 'ID', limit: 12,
            config: ['subreddits' => ['Advice', 'offmychest', 'relationship_advice'], 'min_score' => 10],
        );

        app(RedditResearchProvider::class)->search($query);

        // One token for three subreddits — authenticating per request would burn a
        // third of the rate limit on nothing.
        $tokenCalls = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'access_token'))
            ->count();

        $this->assertSame(1, $tokenCalls);
    }

    public function test_rejected_credentials_fail_loudly_instead_of_falling_back(): void
    {
        config(['research.providers.reddit.client_id' => 'bad']);
        config(['research.providers.reddit.client_secret' => 'bad']);

        Http::fake([
            'www.reddit.com/api/v1/access_token' => Http::response(['error' => 401], 200),
            'oauth.reddit.com/*' => Http::response($this->listingResponse()),
        ]);

        // Silently dropping back to the public endpoints would reintroduce the exact
        // 403 the credentials were added to escape, and hide the real cause.
        $this->expectExceptionMessage('Reddit menolak kredensial OAuth');

        app(RedditResearchProvider::class)->search($this->query());
    }

    public function test_a_cached_token_skips_the_auth_request_entirely(): void
    {
        config(['research.providers.reddit.client_id' => 'id-123']);
        config(['research.providers.reddit.client_secret' => 'secret-456']);
        Cache::put('research:reddit:token:'.md5('id-123'), 'tok-cached', 600);

        Http::fake(['oauth.reddit.com/*' => Http::response($this->listingResponse())]);

        app(RedditResearchProvider::class)->search($this->query());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'access_token'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer tok-cached'));
    }
}
