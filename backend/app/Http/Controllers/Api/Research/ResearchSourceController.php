<?php

namespace App\Http\Controllers\Api\Research;

use App\Http\Controllers\Controller;
use App\Http\Resources\Research\ChannelTemplateResource;
use App\Http\Resources\Research\PlatformResource;
use App\Http\Resources\Research\ResearchSourceResource;
use App\Models\ChannelTemplate;
use App\Models\Platform;
use App\Models\ResearchSource;
use App\Services\Research\ResearchProviderManager;
use Illuminate\Http\Request;

/**
 * Settings > Research Sources, plus the two read-only catalogs (platforms and
 * channel templates) the "Add Channel" form needs.
 *
 * Mutations are admin-only (see routes/api.php): research_sources is global state
 * shared by every user's channels, exactly like templates and settings in this app.
 */
class ResearchSourceController extends Controller
{
    public function index(Request $request)
    {
        $sources = ResearchSource::query()
            ->when($request->filled('enabled'), fn ($query) => $query->where('enabled', $request->boolean('enabled')))
            ->orderBy('name')
            ->get();

        return ResearchSourceResource::collection($sources);
    }

    public function show(ResearchSource $researchSource)
    {
        return ResearchSourceResource::make($researchSource);
    }

    public function update(Request $request, ResearchSource $researchSource)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'enabled' => ['sometimes', 'boolean'],
            'configuration' => ['sometimes', 'nullable', 'array'],
        ]);

        $researchSource->update($data);

        return ResearchSourceResource::make($researchSource->fresh());
    }

    /**
     * "Test Connection". Also the way a source with an open failure circuit is
     * brought back: a passing test resets consecutive_failures, so the engine starts
     * calling it again on the next run.
     */
    public function test(ResearchSource $researchSource, ResearchProviderManager $manager)
    {
        if (! $manager->has($researchSource->provider)) {
            return response()->json([
                'ok' => false,
                'configured' => false,
                'message' => "Provider [{$researchSource->provider}] tidak terdaftar di aplikasi.",
            ], 422);
        }

        $health = $manager->resolve($researchSource->provider)->healthCheck();

        if ($health->ok) {
            $researchSource->markSuccess();
        } elseif ($health->configured) {
            // A provider that is merely unconfigured is not a failure — counting it as
            // one would trip the circuit breaker on a source the user simply has not
            // set up yet.
            $researchSource->markFailure($health->message);
        }

        return response()->json([
            'ok' => $health->ok,
            'configured' => $health->configured,
            'message' => $health->message,
            'latency_ms' => $health->latencyMs,
            'source' => ResearchSourceResource::make($researchSource->fresh()),
        ]);
    }

    public function platforms()
    {
        return PlatformResource::collection(
            Platform::where('enabled', true)->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function templates()
    {
        return ChannelTemplateResource::collection(
            ChannelTemplate::where('enabled', true)->orderBy('sort_order')->get()
        );
    }
}
