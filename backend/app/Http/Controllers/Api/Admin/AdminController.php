<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiRequestLogResource;
use App\Http\Resources\ProcessingJobResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\UserResource;
use App\Models\AiRequestLog;
use App\Models\Clip;
use App\Models\ProcessingJob;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function stats()
    {
        return response()->json([
            'total_users' => User::count(),
            'total_projects' => Project::count(),
            'total_videos' => Video::count(),
            'total_clips' => Clip::count(),
            'clips_completed' => Clip::where('status', Clip::STATUS_COMPLETED)->count(),
            'clips_failed' => Clip::where('status', Clip::STATUS_FAILED)->count(),
            'jobs_active' => ProcessingJob::whereIn('status', [ProcessingJob::STATUS_QUEUED, ProcessingJob::STATUS_RUNNING])->count(),
            'jobs_failed' => ProcessingJob::where('status', ProcessingJob::STATUS_FAILED)->count(),
            'avg_render_seconds' => (int) ProcessingJob::where('type', 'render_clip')
                ->where('status', ProcessingJob::STATUS_COMPLETED)
                ->whereNotNull('started_at')->whereNotNull('finished_at')
                ->get()
                ->avg(fn ($j) => abs($j->finished_at->diffInSeconds($j->started_at))),
        ]);
    }

    public function users(Request $request)
    {
        $users = User::withCount('projects')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->string('q') . '%')
                ->orWhere('email', 'like', '%' . $request->string('q') . '%'))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return UserResource::collection($users);
    }

    public function projects(Request $request)
    {
        $projects = Project::with(['user:id,name,email', 'videos' => fn ($q) => $q->latest()->limit(1)])
            ->withCount(['clips', 'clipCandidates'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return ProjectResource::collection($projects);
    }

    public function processingJobs(Request $request)
    {
        $jobs = ProcessingJob::with(['project:id,title', 'clip:id,title'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 30));

        return ProcessingJobResource::collection($jobs);
    }

    public function aiRequestLogs(Request $request)
    {
        $logs = AiRequestLog::with(['project:id,title', 'video:id,title', 'clip:id,title'])
            ->when($request->filled('capability'), fn ($q) => $q->where('capability', $request->string('capability')))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->string('provider')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 30));

        return AiRequestLogResource::collection($logs);
    }

    public function settings()
    {
        return response()->json(['settings' => Setting::allWithDefaults()]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'transcript_model' => ['sometimes', 'string', 'in:mock,openai,whisper_engine'],
            'clip_scoring_model' => ['sometimes', 'string', 'in:mock,openai,ollama,claude,gemini,nine_router'],
            'default_clip_duration' => ['sometimes', 'integer', 'min:5', 'max:180'],
            'default_template_id' => ['sometimes', 'nullable', 'exists:templates,id'],
            'max_clips_per_video' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        return response()->json(['settings' => Setting::allWithDefaults()]);
    }
}
