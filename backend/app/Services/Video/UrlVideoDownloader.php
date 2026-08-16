<?php

namespace App\Services\Video;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Downloads a source video from a public URL (YouTube, TikTok, Instagram, Facebook,
 * X/Twitter, Vimeo, or a generic direct video link) using yt-dlp, which supports all
 * of those out of the box. Only use this on content the user has the right to import.
 */
class UrlVideoDownloader
{
    private const VIDEO_EXTENSIONS = ['mp4', 'mkv', 'webm', 'mov', 'avi'];

    public function __construct(
        private readonly string $ytDlpBin = 'yt-dlp',
        private readonly string $ffmpegBin = 'ffmpeg',
    ) {
    }

    /**
     * @return array{path: string, title: ?string, captions: ?array{path: string, language: string}}
     */
    public function download(string $url, string $destinationDir): array
    {
        if (! is_dir($destinationDir)) {
            mkdir($destinationDir, 0775, true);
        }

        $destinationDir = rtrim($destinationDir, '/\\');
        $outputTemplate = $destinationDir . DIRECTORY_SEPARATOR . 'source.%(ext)s';

        // Only ask yt-dlp to print the title. The output filename is already
        // deterministic via -o, and yt-dlp's --print statements aren't guaranteed
        // to appear in the order they were passed on the command line (the
        // untagged "title" print fires at metadata-extraction time, *before* the
        // "after_move:filepath" one does) — so we find the produced files on disk
        // instead of trying to parse a positional filepath out of stdout.
        //
        // Subtitles are fetched in a *separate* yt-dlp invocation (see
        // downloadCaptions()), not bundled into this command. yt-dlp aborts the
        // whole run — including the video muxing step — when a requested subtitle
        // language errors out (e.g. a 429 on the caption endpoint), so bundling
        // them here meant a caption rate-limit could fail an otherwise-healthy
        // video import with no source.mp4 ever hitting disk.
        $command = [
            $this->ytDlpBin,
            '--no-playlist',
            '--ffmpeg-location', $this->ffmpegBin,
            '-f', 'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/b',
            '--merge-output-format', 'mp4',
            '--print', 'after_move:%(title)s',
            '-o', $outputTemplate,
            $url,
        ];

        // YouTube intermittently 429-rate-limits a server's IP (nothing to do with the
        // video itself — it can hit any request, including ones for languages the video
        // was never even in), and that can knock out the whole download, not just
        // subtitles. A short retry with backoff clears most of these; a hard failure
        // (private video, deleted, invalid URL, etc.) never says "429" and fails fast.
        $backoffSeconds = [15, 45];
        $maxAttempts = count($backoffSeconds) + 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $result = Process::timeout(900)->run($command);

            $videoCandidates = array_filter(
                glob($destinationDir . DIRECTORY_SEPARATOR . 'source.*') ?: [],
                fn (string $f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true)
            );

            if ($result->successful() || ! empty($videoCandidates)) {
                if (! $result->successful()) {
                    logger()->warning('yt-dlp exited non-zero but the video file was found on disk; continuing', [
                        'url' => $url,
                        'error' => trim($result->errorOutput() ?: $result->output()),
                    ]);
                }

                break;
            }

            $errorText = trim($result->errorOutput() ?: $result->output());
            $isRateLimited = str_contains($errorText, '429') || stripos($errorText, 'Too Many Requests') !== false;

            if (! $isRateLimited || $attempt === $maxAttempts) {
                throw new RuntimeException('Failed to import video from URL: ' . $errorText);
            }

            logger()->warning('yt-dlp was rate-limited (HTTP 429); retrying import', [
                'url' => $url,
                'attempt' => $attempt,
                'retrying_in_seconds' => $backoffSeconds[$attempt - 1],
            ]);

            sleep($backoffSeconds[$attempt - 1]);
        }

        $title = trim($result->output()) ?: null;

        if (empty($videoCandidates)) {
            throw new RuntimeException('yt-dlp reported success but no output video file was found on disk.');
        }

        $this->downloadCaptions($url, $destinationDir, $outputTemplate);

        return [
            'path' => reset($videoCandidates),
            'title' => $title,
            'captions' => $this->findCaptions($destinationDir),
        ];
    }

    /**
     * Best-effort: fetch subtitles/auto-captions in a run of their own, separate
     * from the video download ("orig" = auto-captions in whatever language is
     * actually spoken, falling back to Indonesian/English). When present, these
     * are used directly as the transcript — real captions from the source are
     * faster, free, and more accurate (and, critically, don't need whisper-engine
     * at all) than re-transcribing.
     *
     * One language per yt-dlp call, stopping at the first that actually produces an
     * .srt — NOT one call with `--sub-langs 'orig,id,en,...'`. That looks like a
     * priority list but isn't: yt-dlp downloads every language in the list that
     * exists, and if any single one of them errors (most commonly YouTube
     * 429-rate-limiting that language's specific endpoint), the whole invocation
     * aborts *before* --convert-subs runs — silently discarding an earlier
     * language's .vtt that had already downloaded successfully, and both empty
     * metadata (nothing recorded as captions) *and* wasted the request that did
     * succeed. Splitting into one-language-at-a-time calls means a 429 on 'en'
     * can't discard 'id' having already worked, and also means we're not asking
     * for languages we don't need once an earlier one already succeeded.
     *
     * Never throws: any/all of these failing must not fail an otherwise-successful
     * video import. AnalyzeVideoJob transcribes with the configured provider (e.g.
     * whisper-engine) when no source captions were found.
     */
    private function downloadCaptions(string $url, string $destinationDir, string $outputTemplate): void
    {
        foreach (['orig', 'id', 'en', 'en-US', 'en-GB'] as $lang) {
            $command = [
                $this->ytDlpBin,
                '--no-playlist',
                '--ffmpeg-location', $this->ffmpegBin,
                '--skip-download',
                '--write-subs', '--write-auto-subs',
                '--sub-langs', $lang,
                '--convert-subs', 'srt',
                '-o', $outputTemplate,
                $url,
            ];

            $result = Process::timeout(90)->run($command);

            if (! empty(glob($destinationDir . DIRECTORY_SEPARATOR . 'source.*.srt'))) {
                return;
            }

            if (! $result->successful()) {
                logger()->warning('yt-dlp failed to fetch source captions for one language; trying the next', [
                    'url' => $url,
                    'language' => $lang,
                    'error' => trim($result->errorOutput() ?: $result->output()),
                ]);
            }
        }

        logger()->warning('yt-dlp found no usable source captions in any language; continuing without them', [
            'url' => $url,
        ]);
    }

    /**
     * @return array{path: string, language: string}|null
     */
    private function findCaptions(string $destinationDir): ?array
    {
        $files = glob($destinationDir . DIRECTORY_SEPARATOR . 'source.*.srt') ?: [];
        if (empty($files)) {
            return null;
        }

        // yt-dlp names these "source.<lang>.srt" — prefer Indonesian, then English,
        // then whatever else came back first.
        $preferredOrder = ['id', 'en', 'en-US', 'en-GB'];
        $byLang = [];
        foreach ($files as $file) {
            if (preg_match('/\.([a-zA-Z-]+)\.srt$/', $file, $m)) {
                $byLang[$m[1]] = $file;
            }
        }

        foreach ($preferredOrder as $lang) {
            if (isset($byLang[$lang])) {
                return ['path' => $byLang[$lang], 'language' => $lang];
            }
        }

        $lang = array_key_first($byLang) ?? 'unknown';

        return ['path' => $byLang[$lang] ?? reset($files), 'language' => $lang];
    }

    public function detectSourceType(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $host = strtolower(preg_replace('/^www\./', '', $host));

        return match (true) {
            str_contains($host, 'youtube.com'), str_contains($host, 'youtu.be') => 'youtube',
            str_contains($host, 'tiktok.com') => 'tiktok',
            str_contains($host, 'instagram.com') => 'instagram',
            str_contains($host, 'facebook.com'), str_contains($host, 'fb.watch') => 'facebook',
            str_contains($host, 'twitter.com'), str_contains($host, 'x.com') => 'twitter',
            str_contains($host, 'vimeo.com') => 'vimeo',
            default => 'url',
        };
    }
}
