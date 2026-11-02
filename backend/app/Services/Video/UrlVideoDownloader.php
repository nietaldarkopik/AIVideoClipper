<?php

namespace App\Services\Video;

use Closure;
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

    /**
     * Prefix stamped on every --progress-template line so progress output stays
     * distinguishable from the rest of yt-dlp's stdout (and from the --print
     * title line) when both are read off the same stream.
     */
    private const PROGRESS_MARKER = '@@CLIPPER_PROGRESS@@';

    /**
     * Same idea for yt-dlp's post-processing progress, which is a separate
     * --progress-template type. Merging the separately-downloaded video and audio
     * streams is a post-processor ("Merger"), and its own [Merger] status line is
     * suppressed by the quiet mode --print forces — so the machine-readable
     * template is the only way to see that stage at all.
     */
    private const POSTPROCESS_MARKER = '@@CLIPPER_PP@@';

    /**
     * Fastest a 'downloading' reading is forwarded to $onProgress. yt-dlp emits
     * progress many times a second; every consumer so far writes it to the
     * database, so throttling belongs here rather than in each of them. Stage
     * changes and the final reading of a stream ignore this and emit immediately.
     */
    private const PROGRESS_THROTTLE_SECONDS = 1.0;

    public function __construct(
        private readonly string $ytDlpBin = 'yt-dlp',
        private readonly string $ffmpegBin = 'ffmpeg',
        // Routes every yt-dlp request through this proxy when set (yt-dlp --proxy
        // syntax, e.g. "http://user:pass@host:port" or "socks5://host:port") — an
        // IP block from a platform (observed: TikTok blocking this server's IP
        // outright, independent of the video/account requested) can't be fixed by
        // updating yt-dlp; only a different egress IP gets past it.
        private readonly ?string $proxy = null,
        // Ceiling for the downloaded source's height. This matters more here than
        // for a normal downloader: a 9:16 clip is cropped out of a 16:9 source, so
        // only ~56% of the source's width survives and then gets scaled up to
        // 1080x1920 — a 360p source ends up as a ~202px-wide strip stretched 5x.
        // Capped rather than "always best" because 4K sources (usually AV1) are
        // slow to decode in every ffmpeg pass this pipeline runs, for no visible
        // gain once the result is a 1080x1920 clip.
        private readonly int $maxHeight = 1440,
        // YouTube increasingly gates a video behind "Sign in to confirm you're not
        // a bot" for requests with no session — yt-dlp can only get past that with
        // real cookies. "chrome"/"edge"/"firefox" reads them straight out of that
        // browser's own cookie store on this machine (no export step, but the
        // browser must be logged into YouTube and this only works where a real
        // browser profile exists — not in a headless/Docker deployment); a path
        // instead is treated as a Netscape-format cookies.txt file exported from
        // a browser extension, which is what a headless deployment needs instead.
        private readonly ?string $cookiesFromBrowser = null,
        // Wall-clock ceiling for ONE yt-dlp attempt — see
        // services.ytdlp.process_timeout for why this isn't the old hardcoded 900.
        private readonly int $processTimeout = 3600,
    ) {}

    private function proxyArgs(): array
    {
        return $this->proxy ? ['--proxy', $this->proxy] : [];
    }

    private function cookieArgs(): array
    {
        if (! $this->cookiesFromBrowser) {
            return [];
        }

        return is_file($this->cookiesFromBrowser)
            ? ['--cookies', $this->cookiesFromBrowser]
            : ['--cookies-from-browser', $this->cookiesFromBrowser];
    }

    /**
     * @return list<string> tried in order; '' means "pass no --extractor-args at
     *                      all", i.e. let yt-dlp choose its own player clients.
     *
     * yt-dlp's own default always goes FIRST, and configured clients are only
     * fallbacks for when that fails. Pinning a specific client used to be the
     * default (android), which silently capped every YouTube download at 360p:
     * YouTube now serves the android client a single progressive itag-18 stream
     * and no adaptive formats at all, so the download "succeeded" on the first
     * client and the loop never tried another. yt-dlp tracks which clients
     * currently get served real formats; this list shouldn't second-guess it
     * unless the default actually errors.
     */
    private function playerClients(): array
    {
        $clients = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.ytdlp.player_clients', ''))
        )));

        return ['', ...array_diff($clients, [''])];
    }

    /**
     * @param  ?Closure(DownloadProgress): void  $onProgress  called as yt-dlp reports
     *         progress, already throttled (see progressHandler) so a consumer can
     *         write straight to the database without rate-limiting it itself.
     * @return array{path: string, title: ?string, channel_name: ?string, captions: ?array{path: string, language: string}}
     */
    public function download(string $url, string $destinationDir, ?Closure $onProgress = null): array
    {
        if (! is_dir($destinationDir)) {
            mkdir($destinationDir, 0775, true);
        }

        $destinationDir = rtrim($destinationDir, '/\\');
        $outputTemplate = $destinationDir.DIRECTORY_SEPARATOR.'source.%(ext)s';

        // Subtitles are fetched in a *separate* yt-dlp invocation (see
        // downloadCaptions()), not bundled into the command below. yt-dlp aborts
        // the whole run — including the video muxing step — when a requested
        // subtitle language errors out (e.g. a 429 on the caption endpoint), so
        // bundling them meant a caption rate-limit could fail an otherwise-healthy
        // video import with no source.mp4 ever hitting disk.
        $videoCandidates = [];
        $result = null;
        $lastErrorText = '';

        foreach ($this->attemptStrategies() as $strategy) {
            [$result, $videoCandidates, $error] = $this->runAttempts(
                $url,
                $destinationDir,
                $this->buildCommand($strategy, $outputTemplate),
                $onProgress
            );

            if (! empty($videoCandidates)) {
                break;
            }

            $lastErrorText = $error ?: $lastErrorText;

            logger()->warning('yt-dlp strategy failed; falling back to the next one', [
                'url' => $url,
                'failed_strategy' => $strategy['label'],
                'error' => $lastErrorText,
            ]);
        }

        if (empty($videoCandidates)) {
            $this->emit($onProgress, new DownloadProgress(DownloadProgress::STAGE_FAILED));

            throw new RuntimeException('Failed to import video from URL: '.$lastErrorText);
        }

        [$title, $channelName] = $this->parseTitleAndChannel($result?->output() ?? '');

        $this->downloadCaptions($url, $destinationDir, $outputTemplate);
        $this->emit($onProgress, new DownloadProgress(DownloadProgress::STAGE_COMPLETED));

        return [
            'path' => reset($videoCandidates),
            'title' => $title,
            'channel_name' => $channelName,
            'captions' => $this->findCaptions($destinationDir),
        ];
    }

    /**
     * The ladder download() walks until one rung produces a file. Each rung
     * trades something away for a better chance of getting *a* download at all:
     *
     *  1. no cookies, adaptive — the clean path. Cookies come second, not first,
     *     because a rotated/expired cookie file is itself a failure mode (yt-dlp
     *     warns "the provided YouTube account cookies are no longer valid" and
     *     then gets challenged anyway), so a video that needs no session
     *     shouldn't be made to depend on one.
     *  2. cookies, adaptive — for anything gated behind "Sign in to confirm
     *     you're not a bot", which only a real session gets past.
     *  3. progressive — one pre-muxed file instead of separate video+audio
     *     streams, so there's no second download and no ffmpeg merge. Last
     *     resort rather than the default because YouTube only publishes
     *     progressive formats up to 720p, and every clip here is cropped to 9:16
     *     and upscaled (see $maxHeight), which punishes a low-res source hard.
     *
     * @return list<array{cookies: bool, progressive: bool, label: string}>
     */
    private function attemptStrategies(): array
    {
        $hasCookies = (bool) $this->cookiesFromBrowser;

        $strategies = [
            ['cookies' => false, 'progressive' => false, 'label' => 'adaptive, no cookies'],
        ];

        // Skipped entirely when no cookie source is configured — it would be a
        // byte-for-byte repeat of the rung above.
        if ($hasCookies) {
            $strategies[] = ['cookies' => true, 'progressive' => false, 'label' => 'adaptive, with cookies'];
        }

        $strategies[] = [
            'cookies' => $hasCookies,
            'progressive' => true,
            'label' => $hasCookies ? 'progressive, with cookies' : 'progressive, no cookies',
        ];

        return $strategies;
    }

    /**
     * @param  array{cookies: bool, progressive: bool, label: string}  $strategy
     * @return list<string>
     */
    private function buildCommand(array $strategy, string $outputTemplate): array
    {
        return [
            $this->ytDlpBin,
            '--no-playlist',
            '--ffmpeg-location', $this->ffmpegBin,
            ...$this->formatArgs($strategy['progressive']),
            // Ask yt-dlp to print the title and channel/uploader name, tab-separated
            // on one line so they can't get split across separate --print events
            // (yt-dlp's --print statements aren't guaranteed to appear in the order
            // they were passed on the command line — the untagged "title" print used
            // to fire at metadata-extraction time, *before* the "after_move:filepath"
            // one does). The output filename is already deterministic via -o, so the
            // produced files are found on disk rather than by parsing a positional
            // filepath out of stdout. "%(channel,uploader|)s" falls back to the
            // uploader/handle when a platform has no separate "channel" concept
            // (e.g. TikTok), and to an empty string when neither is available (e.g.
            // a generic direct link) — used to credit the source in clip captions,
            // see ClipCreditFormatter.
            '--print', "after_move:%(title)s\t%(channel,uploader|)s",
            '-o', $outputTemplate,
            // Mimics a real Chrome TLS/HTTP fingerprint (via curl_cffi) instead of
            // yt-dlp's default client signature — several sites' anti-bot systems
            // (TikTok, and Cloudflare-protected generic pages) block the default
            // signature outright regardless of IP, even though a real browser from
            // the exact same IP/network is never challenged. No observed downside
            // on sites that don't need it (verified against YouTube).
            '--impersonate', 'chrome',
            // Progress reporting, via yt-dlp's own mechanism rather than anything
            // inferred here. --newline turns the progress line into real newline-
            // terminated lines (it normally redraws one line with \r, which can't
            // be read incrementally), and --progress-template replaces that line
            // with a machine-readable one. The MARKER prefix is what keeps this
            // separable from yt-dlp's other stdout chatter — including the
            // --print above, whose tab-separated title line must still be findable
            // afterwards (see parseTitleAndChannel).
            //
            // info.vcodec distinguishes the video stream from the audio one: with
            // the "bv*+ba" format above, yt-dlp downloads two streams back to back
            // and each runs its own 0-100%, so without this a consumer can't tell
            // "93% of the video" from "93% of the audio".
            // --progress is load-bearing, not decorative: the --print above puts
            // yt-dlp into quiet mode, which otherwise suppresses progress output
            // entirely (verified — the template emitted nothing at all without
            // this, while the --print line still came through).
            '--newline',
            '--progress',
            '--progress-template',
            'download:'.self::PROGRESS_MARKER.'%(progress.status)s|%(progress._percent_str)s|%(progress._downloaded_bytes_str)s|'
                .'%(progress._total_bytes_str,progress._total_bytes_estimate_str)s|%(progress._speed_str)s|'
                .'%(progress._eta_str)s|%(info.vcodec)s',
            '--progress-template',
            'postprocess:'.self::POSTPROCESS_MARKER.'%(progress.status)s|%(progress.postprocessor)s',
            ...$this->proxyArgs(),
            ...($strategy['cookies'] ? $this->cookieArgs() : []),
        ];
    }

    /**
     * Finished video files in the destination, best candidate first.
     *
     * The ordering is load-bearing. yt-dlp names each separately-downloaded
     * stream "source.f<format-id>.<ext>" and only the merged result "source.mp4",
     * and a run that downloads the video stream but then fails (or is superseded
     * by the next strategy) leaves its fragment behind. Plain alphabetical order
     * puts "source.f136.mp4" BEFORE "source.mp4", so taking the first match would
     * hand back a silent, video-only fragment instead of the real merged file —
     * a failure that looks like a success all the way through to a rendered clip
     * with no audio. Fragments therefore sort last, and are only ever used when
     * nothing else exists.
     *
     * @return list<string>
     */
    private function findVideoCandidates(string $destinationDir): array
    {
        $candidates = array_values(array_filter(
            glob($destinationDir.DIRECTORY_SEPARATOR.'source.*') ?: [],
            fn (string $f) => in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true)
        ));

        usort($candidates, fn (string $a, string $b) => $this->isStreamFragment($a) <=> $this->isStreamFragment($b));

        return $candidates;
    }

    private function isStreamFragment(string $path): bool
    {
        return (bool) preg_match('/^source\.f\d+\./i', basename($path));
    }

    /**
     * Adaptive (the default) downloads the best video and best audio separately
     * and merges them; progressive takes a single file that already contains
     * both. See attemptStrategies() for when each is used.
     *
     * @return list<string>
     */
    private function formatArgs(bool $progressive): array
    {
        if ($progressive) {
            // One stream, already muxed: no second download and no merge pass,
            // which is the whole point of this fallback. No --merge-output-format
            // either, since nothing gets merged. The height cap is kept, but
            // YouTube's progressive formats top out at 720p regardless.
            return ['-f', 'b[ext=mp4]/b', '-S', "res:{$this->maxHeight}"];
        }

        return [
            // Separate video+audio streams merged locally, falling back to a
            // progressive stream only when no adaptive pair exists. Deliberately
            // NOT restricted to [ext=mp4]+[ext=m4a] any more: YouTube only offers
            // its higher resolutions as VP9/AV1 (webm) or with opus audio, so
            // demanding mp4/m4a threw away every format above 360-720p and fell
            // through to a progressive stream. The result is re-encoded by
            // FFmpegService on every render anyway, so the source container and
            // codec don't need to be web-friendly — only decodable.
            '-f', 'bv*+ba/b',
            // Preference order within that: cap the height (see $maxHeight),
            // then prefer H.264 video and m4a audio so the merge below usually
            // stays in a real mp4 rather than being remuxed to mkv. These are
            // preferences, not filters — a video that only publishes VP9 at the
            // wanted height still downloads at that height.
            '-S', "res:{$this->maxHeight},vcodec:h264,acodec:m4a",
            '--merge-output-format', 'mp4',
        ];
    }

    /**
     * Runs one strategy's player-client ladder. Never throws: running out of
     * clients simply reports the failure back so download() can drop to the next
     * strategy instead of giving up here.
     *
     * @param  list<string>  $baseCommand
     * @return array{0: ?\Illuminate\Contracts\Process\ProcessResult, 1: list<string>, 2: string} [result, video files found, error]
     */
    private function runAttempts(string $url, string $destinationDir, array $baseCommand, ?Closure $onProgress): array
    {
        // YouTube intermittently 429-rate-limits a server's IP (nothing to do with the
        // video itself — it can hit any request, including ones for languages the video
        // was never even in), and that can knock out the whole download, not just
        // subtitles. A short retry with backoff clears most of these; a hard failure
        // (private video, deleted, invalid URL, etc.) never says "429" and fails fast.
        //
        // Kept to a single retry (not e.g. [15, 45], two retries): each *additional*
        // attempt adds another services.ytdlp.process_timeout to this call's worst
        // case. That used to also have to stay under PROCESSING_JOB_STALL_MINUTES,
        // since a download reported nothing until it finished and so read as
        // "stalled" to ReapStalledProcessingJobs; now that it streams live progress
        // (see progressHandler below), the reaper only fires when yt-dlp really has
        // gone quiet, and the retry count is purely about not hammering the source.
        $backoffSeconds = [20];
        $maxAttemptsPerClient = count($backoffSeconds) + 1;

        // YouTube increasingly 403s the default client without a Proof-of-Origin
        // token yt-dlp can't supply — see services.ytdlp.player_clients. Unlike a
        // 429, retrying the *same* client on a 403 just fails again immediately, so
        // that error moves on to the next client in the list instead of backing off.
        $playerClients = $this->playerClients();
        $videoCandidates = [];
        $result = null;
        $lastErrorText = '';

        foreach ($playerClients as $clientPosition => $client) {
            $command = $client === ''
                ? [...$baseCommand, $url]
                : [...$baseCommand, '--extractor-args', "youtube:player_client={$client}", $url];
            $isLastClient = $clientPosition === array_key_last($playerClients);

            for ($attempt = 1; $attempt <= $maxAttemptsPerClient; $attempt++) {
                // Everything up to the first byte — resolving the URL, picking
                // formats, and on YouTube solving the player challenge — is
                // reported from here rather than read off yt-dlp's output: its own
                // "[youtube] Extracting URL" line is suppressed by the quiet mode
                // --print forces, and unlike progress there's no machine-readable
                // template for it. This is also the honest state of an attempt
                // that dies before ever downloading anything.
                $this->emit($onProgress, new DownloadProgress(DownloadProgress::STAGE_EXTRACTING));

                // A fresh handler per attempt: a retry starts its own stream at 0%,
                // and carrying the previous attempt's throttle/stage state over
                // would swallow the first readings of the new one.
                $result = Process::timeout($this->processTimeout)
                    ->run($command, $this->progressHandler($onProgress));

                $videoCandidates = $this->findVideoCandidates($destinationDir);

                if (! empty($videoCandidates)) {
                    if (! $result->successful()) {
                        logger()->warning('yt-dlp exited non-zero but the video file was found on disk; continuing', [
                            'url' => $url,
                            'error' => trim($result->errorOutput() ?: $result->output()),
                        ]);
                    }

                    return [$result, array_values($videoCandidates), ''];
                }

                // Exit code 0 with nothing on disk. Another player client won't
                // change that (yt-dlp is reporting success), but another strategy
                // might — a progressive format lands as a single file where an
                // adaptive pair can silently produce nothing mergeable.
                if ($result->successful()) {
                    return [$result, [], 'yt-dlp reported success but no output video file was found on disk.'];
                }

                // yt-dlp's own stderr text (e.g. its "Sign in to confirm you're not
                // a bot" message) has shown up with byte sequences that aren't valid
                // UTF-8 — same class of crash as the title/channel name below, just
                // from a different source. Scrub here too since this string ends up
                // saved as failure_reason on Video/VideoBatchItem (Postgres columns
                // are strict UTF8).
                $lastErrorText = $this->sanitizeUtf8(trim($result->errorOutput() ?: $result->output()));
                $isRateLimited = str_contains($lastErrorText, '429') || stripos($lastErrorText, 'Too Many Requests') !== false;
                $isForbidden = str_contains($lastErrorText, '403') || stripos($lastErrorText, 'Forbidden') !== false;
                // Which formats exist depends on which client asked: verified that
                // for the same video, "android" and "tv_simply" are served a muxed
                // progressive format (itag 18) while yt-dlp's own default client
                // and "web" are served none at all. So "no such format" is a
                // statement about this client, not about the video, and is worth
                // another client rather than ending the strategy — without this the
                // progressive rung below gives up on its very first client every
                // time and never reaches the ones that could actually serve it.
                $isFormatUnavailable = stripos($lastErrorText, 'Requested format is not available') !== false;

                if ($isRateLimited && $attempt < $maxAttemptsPerClient) {
                    logger()->warning('yt-dlp was rate-limited (HTTP 429); retrying import', [
                        'url' => $url,
                        'player_client' => $client ?: '(default)',
                        'attempt' => $attempt,
                        'retrying_in_seconds' => $backoffSeconds[$attempt - 1],
                    ]);
                    sleep($backoffSeconds[$attempt - 1]);

                    continue;
                }

                if (($isRateLimited || $isForbidden || $isFormatUnavailable) && ! $isLastClient) {
                    logger()->warning('yt-dlp was blocked; retrying import with a different player client', [
                        'url' => $url,
                        'failed_player_client' => $client ?: '(default)',
                        'next_player_client' => $playerClients[$clientPosition + 1] ?? null,
                        'error' => $lastErrorText,
                    ]);

                    continue 2;
                }

                // Out of options for this strategy — hand the error back so
                // download() can try the next one (cookies, then progressive)
                // rather than ending the whole import here.
                break 2;
            }
        }

        return [$result, [], $lastErrorText];
    }

    /**
     * Streams yt-dlp's stdout/stderr as it runs and turns it into DownloadProgress
     * readings. Returns null when nobody is listening, so a caller that doesn't
     * want progress costs nothing.
     *
     * Process hands over arbitrary chunks, not whole lines, so partial lines are
     * buffered until their newline arrives — without that, a reading split across
     * two chunks would be parsed as two malformed ones.
     */
    private function progressHandler(?Closure $onProgress): ?Closure
    {
        if (! $onProgress) {
            return null;
        }

        // One buffer per stream, not one shared: yt-dlp writes progress to stdout
        // and warnings to stderr, and a warning arriving mid-progress-line would
        // otherwise be spliced into the middle of that line and parsed as garbage.
        $buffers = ['out' => '', 'err' => ''];
        $lastEmittedAt = 0.0;
        $lastStage = null;

        return function (string $type, string $chunk) use ($onProgress, &$buffers, &$lastEmittedAt, &$lastStage): void {
            $stream = isset($buffers[$type]) ? $type : 'out';
            $buffers[$stream] = str_replace("\r", "\n", $buffers[$stream].$chunk);
            $lines = explode("\n", $buffers[$stream]);
            // Whatever follows the final newline is an incomplete line; hold it
            // back for the next chunk rather than parsing half a reading.
            $buffers[$stream] = array_pop($lines) ?? '';

            foreach ($lines as $line) {
                $progress = $this->parseProgressLine(trim($line));
                if (! $progress) {
                    continue;
                }

                $isStageChange = $progress->stage !== $lastStage;
                $isStreamFinished = $progress->percent !== null && $progress->percent >= 100.0;
                $throttled = (microtime(true) - $lastEmittedAt) < self::PROGRESS_THROTTLE_SECONDS;

                if ($throttled && ! $isStageChange && ! $isStreamFinished) {
                    continue;
                }

                $lastStage = $progress->stage;
                $lastEmittedAt = microtime(true);
                $onProgress($progress);
            }
        };
    }

    /**
     * @return ?DownloadProgress null for the lines that say nothing about progress
     */
    private function parseProgressLine(string $line): ?DownloadProgress
    {
        if (str_starts_with($line, self::PROGRESS_MARKER)) {
            $fields = explode('|', substr($line, strlen(self::PROGRESS_MARKER)));
            [$status, $percent, $downloaded, $total, $speed, $eta, $vcodec] = array_pad($fields, 7, '');

            // yt-dlp reports its own failures through the exit code and stderr,
            // which the retry/error handling in download() already reads; a
            // progress line saying "error" would only duplicate that.
            if (trim($status) === 'error') {
                return null;
            }

            return new DownloadProgress(
                stage: DownloadProgress::STAGE_DOWNLOADING,
                percent: $this->progressPercent($percent),
                downloaded: $this->progressField($downloaded),
                total: $this->progressField($total),
                speed: $this->progressField($speed),
                eta: $this->progressField($eta),
                isAudio: trim($vcodec) === 'none',
            );
        }

        if (str_starts_with($line, self::POSTPROCESS_MARKER)) {
            [$status, $postprocessor] = array_pad(
                explode('|', substr($line, strlen(self::POSTPROCESS_MARKER))),
                2,
                ''
            );

            // Merging is the only post-processor worth a stage of its own here:
            // it's the one that takes real time on a large file (ffmpeg muxing
            // hundreds of MB), while the rest finish instantly. Its "finished"
            // reading is dropped so the stage doesn't flicker back and forth —
            // completion is announced once, by download() itself.
            return (trim($postprocessor) === 'Merger' && trim($status) === 'started')
                ? new DownloadProgress(DownloadProgress::STAGE_MERGING)
                : null;
        }

        return null;
    }

    private function progressPercent(string $value): ?float
    {
        $value = trim($value, " \t%");

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * yt-dlp has a placeholder for anything it can't report yet — "N/A" for an
     * unknown total (a format whose size it hasn't been told), "Unknown" for an
     * ETA it can't estimate yet — and spells it differently per field. Nulled
     * here so DownloadProgress leaves those pieces out of its message rather
     * than showing "ETA Unknown".
     */
    private function progressField(string $value): ?string
    {
        $value = trim($value);

        // "Unknown" is a prefix, not a whole value, for the speed field — yt-dlp
        // renders an unknown rate as "Unknown B/s" while an unknown ETA is a bare
        // "Unknown" (both observed on the same download).
        return ($value === '' || $value === 'NA' || $value === 'N/A' || str_starts_with($value, 'Unknown'))
            ? null
            : $value;
    }

    private function emit(?Closure $onProgress, DownloadProgress $progress): void
    {
        if ($onProgress) {
            $onProgress($progress);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string} [title, channel_name]
     */
    private function parseTitleAndChannel(string $output): array
    {
        // The --print template is tab-separated, so the last TAB-bearing line is
        // ours (a "player client" retry can emit several across attempts; only the
        // final successful one matters). Matching on the tab rather than simply
        // taking the last line keeps this working now that --newline/--progress-
        // template put many more lines on the same stream.
        $lines = array_values(array_filter(
            explode("\n", str_replace("\r", "\n", trim($output))),
            fn (string $l) => str_contains($l, "\t") && ! str_starts_with(trim($l), self::PROGRESS_MARKER)
        ));
        $lastLine = end($lines) ?: '';
        [$title, $channelName] = array_pad(explode("\t", $lastLine, 2), 2, null);

        return [
            $this->sanitizeUtf8(trim((string) $title)) ?: null,
            $this->sanitizeUtf8(trim((string) $channelName)) ?: null,
        ];
    }

    /**
     * yt-dlp occasionally emits titles/channel names with byte sequences that
     * aren't valid UTF-8 (e.g. a mis-decoded Windows-1252 curly quote, 0x92).
     * Postgres columns are strict UTF8, so saving one of these raw crashes the
     * whole import with an uncatchable-looking PDOException. Scrub instead of
     * rejecting the video outright.
     */
    private function sanitizeUtf8(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
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
                '--impersonate', 'chrome',
                ...$this->proxyArgs(),
                ...$this->cookieArgs(),
                $url,
            ];

            $result = Process::timeout(90)->run($command);

            if (! empty(glob($destinationDir.DIRECTORY_SEPARATOR.'source.*.srt'))) {
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
        $files = glob($destinationDir.DIRECTORY_SEPARATOR.'source.*.srt') ?: [];
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
