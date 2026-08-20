<?php

namespace App\Console\Commands;

use App\Models\ClipCandidate;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Finds videos that downloaded cleanly (real source captions included, e.g. from
 * YouTube) but have no clip candidates yet — either AnalyzeVideoJob never ran, or
 * it ran and the configured AI provider (often just "mock" in dev) came back
 * empty. These are exactly the projects the "cowork" workflow targets: hand the
 * .srt to an outside Claude conversation to pick punchline moments by hand, then
 * feed the picks back in via clips:import-punchlines.
 */
class ListPendingPunchlineAnalysis extends Command
{
    protected $signature = 'clips:pending';

    protected $description = 'List downloaded videos with real source captions but no clip candidates yet';

    public function handle(): int
    {
        $disk = Storage::disk('media');

        $videos = Video::with('project')
            ->where('status', 'ready')
            ->whereNotNull('disk_path')
            ->get()
            ->filter(fn (Video $v) => ClipCandidate::where('video_id', $v->id)->count() === 0);

        if ($videos->isEmpty()) {
            $this->info('Nothing pending — every downloaded video already has clip candidates.');

            return self::SUCCESS;
        }

        $rows = $videos->map(function (Video $video) use ($disk) {
            $srtPath = $video->metadata['captions_srt_path'] ?? null;
            $hasSrt = $srtPath && $disk->exists($srtPath);

            return [
                $video->project_id,
                $video->project->title,
                $video->id,
                $hasSrt ? $disk->path($srtPath) : '(no source captions)',
                $video->metadata['captions_language'] ?? '--',
            ];
        });

        $this->table(['Project', 'Title', 'Video', 'SRT path', 'Lang'], $rows);

        return self::SUCCESS;
    }
}
