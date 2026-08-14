<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProcessingJobResource;
use App\Models\Project;
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
}
