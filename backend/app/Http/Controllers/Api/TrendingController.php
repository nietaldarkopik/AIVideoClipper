<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrendingItemResource;
use App\Services\Trending\TrendingProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class TrendingController extends Controller
{
    public function __construct(private readonly TrendingProviderManager $providers)
    {
    }

    public function index(Request $request)
    {
        $request->validate([
            'platform' => ['sometimes', Rule::in($this->providers->supportedPlatforms())],
        ]);

        $providers = $request->filled('platform')
            ? [$this->providers->resolve($request->string('platform')->toString())]
            : $this->providers->all();

        $ttl = (int) config('services.trending.cache_ttl', 900);

        $items = collect($providers)
            ->flatMap(fn ($provider) => Cache::remember(
                "trending:{$provider->platform()}",
                $ttl,
                fn () => $provider->fetchTrending()
            ))
            ->sortByDesc('view_count')
            ->values();

        return TrendingItemResource::collection($items);
    }

    public function platforms()
    {
        return response()->json([
            'platforms' => collect($this->providers->all())->map(fn ($p) => [
                'key' => $p->platform(),
                'label' => $p->label(),
                'is_mocked' => $p->isMocked(),
            ])->values(),
        ]);
    }
}
