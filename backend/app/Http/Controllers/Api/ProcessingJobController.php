<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProcessingJobResource;
use App\Models\Clip;
use App\Models\Project;
use App\Models\ProcessingJob;
use App\Models\Video;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProcessingJobController extends Controller
{
    /**
     * Polled by the frontend to drive progress bars ("Analyzing video... 82%").
     */
    public function forProject(Request $request, Project $project)
    {
        if ($project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }

        $jobs = $project->processingJobs()->latest()->limit(20)->get();

        return ProcessingJobResource::collection($jobs);
    }

    /**
     * User-initiated stop for a running/queued job. This can only flag the row —
     * the job's own cooperative check (App\Jobs\Concerns\ChecksCancellation) is what
     * actually makes the running PHP process notice and stop, at its next phase
     * boundary. We flip the Project/Video/Clip status here immediately rather than
     * waiting for that, so the UI reflects the stop right away instead of however
     * long the job takes to next check in.
     */
    public function cancel(Request $request, Project $project, ProcessingJob $processingJob)
    {
        if ($project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }

        if ($processingJob->project_id !== $project->id) {
            throw new NotFoundHttpException();
        }

        if (! in_array($processingJob->status, [ProcessingJob::STATUS_RUNNING, ProcessingJob::STATUS_QUEUED], true)) {
            return response()->json(['message' => 'This job already finished.'], 422);
        }

        $processingJob->cancel();

        match ($processingJob->type) {
            'render_clip' => $processingJob->clip_id && Clip::where('id', $processingJob->clip_id)
                ->update(['status' => Clip::STATUS_FAILED, 'failure_reason' => 'Cancelled by user.']),
            'import_video' => $processingJob->video_id && Video::where('id', $processingJob->video_id)
                ->update(['status' => 'failed', 'failure_reason' => 'Cancelled by user.']),
            default => null,
        };

        $project->update(['status' => Project::STATUS_FAILED, 'failure_reason' => 'Cancelled by user.']);

        return ProcessingJobResource::make($processingJob);
    }
}
