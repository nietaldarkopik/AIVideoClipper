<?php

namespace Tests\Unit;

use App\Support\Media;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Signed media URLs must keep pointing at APP_URL on the public path, but follow a
 * loopback/LAN address when the API is reached that way (so media still loads when the
 * public domain's DNS or reverse proxy is down) — without ever trusting an arbitrary Host.
 */
class MediaBaseUrlTest extends TestCase
{
    private const PUBLIC = 'https://existcode.excoder.site';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => self::PUBLIC]);
    }

    /** @dataProvider hosts */
    public function test_base_url_for_request_host(string $requestUrl, string $expected): void
    {
        $this->assertSame($expected, Media::baseUrl(Request::create($requestUrl)));
    }

    public static function hosts(): array
    {
        return [
            'public domain keeps APP_URL' => ['https://existcode.excoder.site/api/clips', self::PUBLIC],
            'localhost with port' => ['http://localhost:8000/api/clips', 'http://localhost:8000'],
            'loopback ip' => ['http://127.0.0.1:8000/api/clips', 'http://127.0.0.1:8000'],
            'LAN 192.168' => ['http://192.168.1.20:8000/api/clips', 'http://192.168.1.20:8000'],
            'LAN 10.x' => ['http://10.0.0.5:8000/api/clips', 'http://10.0.0.5:8000'],
            'LAN 172.16/12' => ['http://172.20.1.2:8000/api/clips', 'http://172.20.1.2:8000'],
            'mDNS .local name' => ['http://mypc.local:8000/api/clips', 'http://mypc.local:8000'],
            'arbitrary hostname is NOT trusted' => ['http://evil.example.com/api/clips', self::PUBLIC],
            'public IP is NOT trusted' => ['http://8.8.8.8/api/clips', self::PUBLIC],
            '172.32 is outside the private range' => ['http://172.32.0.1/api/clips', self::PUBLIC],
        ];
    }

    public function test_tunnel_hosts_are_ignored_unless_explicitly_trusted(): void
    {
        $request = Request::create('http://random-words.trycloudflare.com/api/clips');

        $this->assertSame(self::PUBLIC, Media::baseUrl($request));

        config(['integrations.media_trusted_host_suffixes' => ['.trycloudflare.com']]);

        // https is forced: the tunnel reaches us over plain http but serves clients over https.
        $this->assertSame('https://random-words.trycloudflare.com', Media::baseUrl($request));
        // A lookalike that merely contains the suffix text is not trusted.
        $this->assertSame(self::PUBLIC, Media::baseUrl(Request::create('http://trycloudflare.com.evil.example/api/clips')));
        $this->assertSame(self::PUBLIC, Media::baseUrl(Request::create('http://evil.example/api/clips')));
    }

    public function test_no_request_context_uses_app_url(): void
    {
        // CLI, queue jobs and scheduled commands have no meaningful Host.
        $this->assertSame(self::PUBLIC, Media::baseUrl(null));
    }

    public function test_trailing_slash_on_app_url_is_ignored(): void
    {
        config(['app.url' => self::PUBLIC . '/']);

        $this->assertSame(self::PUBLIC, Media::baseUrl(null));
    }

    public function test_url_in_console_context_is_unchanged(): void
    {
        // PHPUnit runs in the console, where url() must ignore the request entirely.
        $url = Media::url('clips/1/final.mp4');

        $this->assertStringStartsWith(self::PUBLIC . '/api/media/clips/1/final.mp4?', $url);
    }
}
