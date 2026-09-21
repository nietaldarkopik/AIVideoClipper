<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\PublicHttpUrl;
use App\Http\Resources\VideoResource;
use App\Jobs\ImportVideoJob;
use App\Models\Project;
use App\Models\Video;
use App\Services\Video\SubtitleService;
use App\Services\Video\UrlVideoDownloader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VideoController extends Controller
{
    public function store(Request $request, Project $project, UrlVideoDownloader $downloader)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'file' => ['required_without:url', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-matroska,video/webm,video/x-msvideo', 'max:5242880'],
            'url' => ['required_without:file', 'nullable', 'url', 'max:8192', new PublicHttpUrl],
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $video = $project->videos()->create([
                'source_type' => 'upload',
                'original_filename' => $file->getClientOriginalName(),
                'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                'status' => 'processing',
            ]);

            $ext = strtolower($file->getClientOriginalExtension() ?: 'mp4');
            $relativePath = Storage::disk('media')->putFileAs("videos/{$video->id}", $file, "source.{$ext}");
            $video->update(['disk_path' => $relativePath]);

            ImportVideoJob::dispatch($video->id);
        } else {
            $url = $data['url'];
            $video = $project->videos()->create([
                'source_type' => $downloader->detectSourceType($url),
                'source_url' => $url,
                'status' => 'pending',
            ]);

            ImportVideoJob::dispatch($video->id);
        }

        $project->update(['status' => \App\Models\Project::STATUS_UPLOADING, 'last_edited_at' => now()]);

        return VideoResource::make($video)->response()->setStatusCode(201);
    }

    public function show(Request $request, Video $video)
    {
        $this->authorizeProject($request, $video->project);

        return VideoResource::make($video->load('transcript'));
    }

    /**
     * The FULL source video's transcript (absolute source-video seconds, not
     * clip-relative — see Clip::caption_cues for the per-clip, already-cut-and-
     * retimed version of this used by the clip editor's own transcript panel).
     * Segments are whatever AnalyzeVideoJob saved via the configured
     * TranscriptionProvider — sentence-level {start, end, text, speaker?}.
     */
    public function transcript(Request $request, Video $video)
    {
        $this->authorizeProject($request, $video->project);
        $video->loadMissing('transcript');

        if (! $video->transcript) {
            return response()->json(['message' => 'No transcript available for this video yet.'], 404);
        }

        return response()->json([
            'language' => $video->transcript->language,
            'segments' => $video->transcript->segments ?? [],
        ]);
    }

    /**
     * Same segments as transcript() above, rendered to a plain .srt file — there
     * is no full-video .srt on disk anywhere (only per-clip ones, built from
     * clip-relative cues at render time), so this renders one on the fly from
     * the raw JSON segments rather than pointing at a stored file.
     */
    public function downloadTranscript(Request $request, Video $video, SubtitleService $subtitles)
    {
        $this->authorizeProject($request, $video->project);
        $video->loadMissing('transcript');

        if (! $video->transcript) {
            return response()->json(['message' => 'No transcript available for this video yet.'], 404);
        }

        $srt = $subtitles->toSrt($video->transcript->segments ?? []);
        $filename = Str::slug($video->title ?: "video-{$video->id}") ?: "video-{$video->id}";

        return response($srt, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}.srt\"",
        ]);
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        if ($project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
