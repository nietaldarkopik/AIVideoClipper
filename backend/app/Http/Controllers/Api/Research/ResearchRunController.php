<?php

namespace App\Http\Controllers\Api\Research;

use App\Http\Controllers\Api\Research\Concerns\AuthorizesContentChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\Research\ResearchRunResource;
use App\Models\ContentChannel;
use App\Models\ResearchRun;
use App\Services\Research\ResearchRunLauncher;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResearchRunController extends Controller
{
    use AuthorizesContentChannel;

    public function index(Request $request)
    {
        $runs = ResearchRun::query()
            ->whereIn('content_channel_id', $request->user()->contentChannels()->select('id'))
            ->with('channel:id,name,platform_id')
            ->when($request->filled('content_channel_id'), fn ($query) => $query->where('content_channel_id', $request->integer('content_channel_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(min(100, $request->integer('per_page', 20)));

        return ResearchRunResource::collection($runs);
    }

    public function show(Request $request, ResearchRun $researchRun)
    {
        $this->authorizeRun($request, $researchRun);

        $researchRun->load(['channel.platform', 'results', 'ideas.sources']);

        return ResearchRunResource::make($researchRun);
    }

    public function forChannel(Request $request, ContentChannel $contentChannel)
    {
        $this->authorizeChannel($request, $contentChannel);

        $runs = $contentChannel->researchRuns()->latest('id')->paginate(min(100, $request->integer('per_page', 20)));

        return ResearchRunResource::collection($runs);
    }

    /**
     * Manual "Run now". Always queues — never runs the pipeline inside the request,
     * which would tie an HTTP response to a dozen network calls plus an LLM
     * generation (spec section 13).
     */
    public function store(Request $request, ContentChannel $contentChannel, ResearchRunLauncher $launcher)
    {
        $this->authorizeChannel($request, $contentChannel);

        try {
            $run = $launcher->launch($contentChannel, 'manual', $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ResearchRunResource::make($run)->response()->setStatusCode(202);
    }

    private function authorizeRun(Request $request, ResearchRun $run): void
    {
        $owned = $request->user()->contentChannels()->whereKey($run->content_channel_id)->exists();

        if (! $owned) {
            throw new NotFoundHttpException('Research run not found.');
        }
    }
}
