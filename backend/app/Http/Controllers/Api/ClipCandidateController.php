<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClipCandidateResource;
use App\Models\ClipCandidate;
use App\Models\Project;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ClipCandidateController extends Controller
{
    public function index(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $candidates = $project->clipCandidates()
            ->with('clip:id,clip_candidate_id,status')
            ->orderByDesc('overall_score')
            ->get();

        return ClipCandidateResource::collection($candidates);
    }

    public function show(Request $request, ClipCandidate $clipCandidate)
    {
        $this->authorizeProject($request, $clipCandidate->project);

        return ClipCandidateResource::make($clipCandidate->load('clip:id,clip_candidate_id,status'));
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        if ($project->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            throw new NotFoundHttpException();
        }
    }
}
