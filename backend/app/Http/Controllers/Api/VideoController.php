<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Jobs\ImportVideoJob;
use App\Models\Project;
use App\Models\Video;
use App\Services\Video\UrlVideoDownloader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class VideoController extends Controller
{
    public function store(Request $request, Project $project, UrlVideoDownloader $downloader)
    {
        $this->authorizeProject($request, $project);

        $data = $request->validate([
            'file' => ['required_without:url', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-matroska,video/webm,video/x-msvideo', 'max:5242880'],
            'url' => ['required_without:file', 'nullable', 'url'],
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

    private function authorizeProject(Request $request, Project $project): void
    {
        if ($project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
