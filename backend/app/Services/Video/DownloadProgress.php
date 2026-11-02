<?php

namespace App\Services\Video;

/**
 * One progress reading from a running yt-dlp download (see UrlVideoDownloader::
 * download, which emits these to its $onProgress callback).
 *
 * Exists so the "Downloading 93.1% - 1.89MiB/s - ETA 00:16" wording lives in one
 * place rather than being re-derived by every caller that wants to show it:
 * ImportVideoJob writes describe() straight into ProcessingJob->message, which
 * the project page already renders verbatim (see ProcessingStatus.tsx).
 *
 * Every string field is taken from yt-dlp's own --progress-template output rather
 * than being recomputed here, so the numbers shown are exactly the ones yt-dlp
 * itself reports — including on a resumed download, where its percentage already
 * accounts for the bytes an earlier attempt left in the .part file.
 */
class DownloadProgress
{
    public const STAGE_EXTRACTING = 'extracting';
    public const STAGE_DOWNLOADING = 'downloading';
    public const STAGE_MERGING = 'merging';
    public const STAGE_COMPLETED = 'completed';
    public const STAGE_FAILED = 'failed';

    public function __construct(
        public readonly string $stage,
        // 0-100, or null when the stage has no meaningful percentage of its own
        // (extracting/merging report no byte counts).
        public readonly ?float $percent = null,
        public readonly ?string $downloaded = null,
        public readonly ?string $total = null,
        public readonly ?string $speed = null,
        public readonly ?string $eta = null,
        // The "bv*+ba" format downloads two streams back to back, each with its
        // own 0-100%, so a bare percentage is ambiguous without knowing which one
        // is running. Derived from yt-dlp's own info.vcodec ('none' = audio).
        public readonly bool $isAudio = false,
    ) {}

    /**
     * Human-readable one-liner for a status/message field. Pieces that yt-dlp
     * couldn't report (no known total on a live stream, no ETA on the last chunk)
     * are dropped rather than shown as "NA".
     */
    public function describe(): string
    {
        return match ($this->stage) {
            self::STAGE_EXTRACTING => 'Extracting video info...',
            self::STAGE_MERGING => 'Merging video and audio...',
            self::STAGE_COMPLETED => 'Download complete.',
            self::STAGE_FAILED => 'Download failed.',
            default => $this->describeDownloading(),
        };
    }

    private function describeDownloading(): string
    {
        $what = $this->isAudio ? 'Downloading audio' : 'Downloading video';

        $parts = array_filter([
            $this->percent !== null ? $what.' '.rtrim(rtrim(number_format($this->percent, 1), '0'), '.').'%' : $what,
            $this->total !== null ? "of {$this->total}" : null,
            $this->speed !== null ? "at {$this->speed}" : null,
            $this->eta !== null ? "ETA {$this->eta}" : null,
        ]);

        return implode(' • ', $parts);
    }
}
