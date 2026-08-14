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
        // Also grab subtitles when the platform has them ("orig" = auto-captions in
        // whatever language is actually spoken, falling back to Indonesian/English).
        // When present, these are used directly as the transcript — real captions
        // from the source are faster, free, and more accurate than re-transcribing.
        $result = Process::timeout(900)->run([
            $this->ytDlpBin,
            '--no-playlist',
            '--ffmpeg-location', $this->ffmpegBin,
            '-f', 'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/b',
            '--merge-output-format', 'mp4',
            '--write-subs', '--write-auto-subs',
            '--sub-langs', 'orig,id,en,en-US,en-GB',
            '--convert-subs', 'srt',
            '--print', 'after_move:%(title)s',
            '-o', $outputTemplate,
            $url,
        ]);

        $videoCandidates = array_filter(
            glob($destinationDir . DIRECTORY_SEPARATOR . 'source.*') ?: [],
            fn (string $f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true)
        );

        if (! $result->successful()) {
            // yt-dlp downloads the video before fetching subtitles, so a subtitle-stage
            // failure (e.g. YouTube 429-rate-limiting the caption endpoint) can leave a
            // perfectly good video file on disk despite a non-zero exit code. Captions
            // are optional — AnalyzeVideoJob transcribes with the configured provider
            // when no source captions are found — so don't fail the whole import for it.
            if (empty($videoCandidates)) {
                throw new RuntimeException('Failed to import video from URL: ' . trim($result->errorOutput() ?: $result->output()));
            }

            logger()->warning('yt-dlp exited non-zero but the video file downloaded successfully; continuing without source captions', [
                'url' => $url,
                'error' => trim($result->errorOutput() ?: $result->output()),
            ]);
        }

        $title = trim($result->output()) ?: null;

        if (empty($videoCandidates)) {
            throw new RuntimeException('yt-dlp reported success but no output video file was found on disk.');
        }

        return [
            'path' => reset($videoCandidates),
            'title' => $title,
            'captions' => $this->findCaptions($destinationDir),
        ];
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
