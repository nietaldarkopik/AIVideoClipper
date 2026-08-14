<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Clip;
use App\Models\ProcessingJob;
use App\Models\Project;
use App\Models\Video;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $projectIds = Project::where('user_id', $userId)->pluck('id');

        return response()->json([
            'stats' => [
                'total_projects' => $projectIds->count(),
                'total_videos' => Video::whereIn('project_id', $projectIds)->count(),
                'total_clips_generated' => Clip::whereIn('project_id', $projectIds)->count(),
                'clips_completed' => Clip::whereIn('project_id', $projectIds)->where('status', Clip::STATUS_COMPLETED)->count(),
                'clips_processing' => Clip::whereIn('project_id', $projectIds)->whereIn('status', [Clip::STATUS_QUEUED, Clip::STATUS_RENDERING])->count(),
                'clips_failed' => Clip::whereIn('project_id', $projectIds)->where('status', Clip::STATUS_FAILED)->count(),
                'jobs_active' => ProcessingJob::whereIn('project_id', $projectIds)->whereIn('status', [ProcessingJob::STATUS_QUEUED, ProcessingJob::STATUS_RUNNING])->count(),
                'jobs_failed' => ProcessingJob::whereIn('project_id', $projectIds)->where('status', ProcessingJob::STATUS_FAILED)->count(),
            ],
            'recent_projects' => [
                'data' => ProjectResource::collection(
                    Project::where('user_id', $userId)
                        ->withCount(['clips', 'clipCandidates'])
                        ->with(['videos' => fn ($q) => $q->latest()->limit(1)])
                        ->orderByDesc('last_edited_at')
                        ->limit(6)
                        ->get()
                ),
            ],
        ]);
    }
}
