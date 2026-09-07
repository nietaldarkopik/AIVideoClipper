<?php

namespace App\Http\Controllers\Api\Research;

use App\Http\Controllers\Api\Research\Concerns\AuthorizesContentChannel;
use App\Http\Controllers\Controller;
use App\Http\Resources\Research\ContentChannelResource;
use App\Models\ContentChannel;
use App\Models\Platform;
use App\Models\ResearchSource;
use App\Services\Research\ResearchRunLauncher;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class ContentChannelController extends Controller
{
    use AuthorizesContentChannel;

    public function index(Request $request)
    {
        $channels = $request->user()->contentChannels()
            ->with('platform')
            ->withCount(['contentIdeas', 'researchRuns'])
            ->when($request->filled('platform_id'), fn ($query) => $query->where('platform_id', $request->integer('platform_id')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->get();

        return ContentChannelResource::collection($channels);
    }

    public function show(Request $request, ContentChannel $contentChannel)
    {
        $this->authorizeChannel($request, $contentChannel);

        $contentChannel->load(['platform', 'researchSources'])->loadCount(['contentIdeas', 'researchRuns']);

        return ContentChannelResource::make($contentChannel);
    }

    public function store(Request $request, ResearchRunLauncher $launcher)
    {
        $data = $request->validate($this->rules(creating: true));

        $platform = Platform::findOrFail($data['platform_id']);

        // Platform defaults fill only what the user left blank, so a channel created
        // from the UI is immediately usable without the platform ever overriding an
        // explicit choice.
        $data = $this->applyPlatformDefaults($data, $platform);

        $channel = $request->user()->contentChannels()->create(Arr::except($data, ['research_sources']));

        $this->syncSources($channel, $data['research_sources'] ?? $this->defaultSourcesFor($platform));

        if ($channel->scheduler_enabled) {
            // Stamped now so the channel joins the schedule immediately instead of
            // waiting for the next hourly tick to notice it.
            $launcher->stampSchedule($channel);
        }

        return ContentChannelResource::make($channel->fresh(['platform', 'researchSources']))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, ContentChannel $contentChannel, ResearchRunLauncher $launcher)
    {
        $this->authorizeChannel($request, $contentChannel);

        $data = $request->validate($this->rules(creating: false));

        $contentChannel->update(Arr::except($data, ['research_sources']));

        if (array_key_exists('research_sources', $data)) {
            $this->syncSources($contentChannel, $data['research_sources']);
        }

        // Any schedule-shaped change invalidates next_research_at — recompute rather
        // than leaving the channel pointing at a slot its new schedule no longer has.
        if ($this->touchesSchedule($data)) {
            $launcher->stampSchedule($contentChannel->refresh());
        }

        return ContentChannelResource::make($contentChannel->fresh(['platform', 'researchSources']));
    }

    public function destroy(Request $request, ContentChannel $contentChannel)
    {
        $this->authorizeChannel($request, $contentChannel);

        $contentChannel->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'platform_id' => [$required, 'integer', 'exists:platforms,id'],
            'name' => [$required, 'string', 'max:255'],
            'handle' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'language' => ['sometimes', 'string', 'max:16'],
            'timezone' => ['sometimes', 'string', 'max:64', 'timezone'],
            'is_active' => ['sometimes', 'boolean'],

            'niche' => ['nullable', 'string', 'max:255'],
            'sub_niches' => ['sometimes', 'array', 'max:50'],
            'sub_niches.*' => ['string', 'max:120'],
            'keywords' => ['sometimes', 'array', 'max:100'],
            'keywords.*' => ['string', 'max:120'],
            'excluded_keywords' => ['sometimes', 'array', 'max:100'],
            'excluded_keywords.*' => ['string', 'max:120'],
            'target_audience' => ['nullable', 'string', 'max:1000'],

            'content_style' => ['sometimes', 'array', 'max:30'],
            'content_style.*' => ['string', 'max:120'],
            'content_types' => ['sometimes', 'array', 'max:30'],
            'content_types.*' => ['string', 'max:120'],
            'content_formats' => ['sometimes', 'array', 'max:30'],
            'content_formats.*' => ['string', 'max:120'],
            'tone' => ['nullable', 'string', 'max:255'],
            'hook_styles' => ['sometimes', 'array', 'max:30'],
            'hook_styles.*' => ['string', 'max:120'],

            'scheduler_enabled' => ['sometimes', 'boolean'],
            'research_frequency' => ['sometimes', Rule::in(ContentChannel::FREQUENCIES)],
            'research_times' => ['sometimes', 'array', 'max:24'],
            // HH:MM in the channel's own timezone. Validated by pattern rather than
            // date_format so "6:00" is rejected outright — scheduleTimes() parses on a
            // strict two-digit shape and would silently drop a loose value.
            'research_times.*' => ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'interval_hours' => ['nullable', 'integer', 'min:1', 'max:24'],
            'ideas_per_run' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('research.ideas.max_per_run', 20)],
            'min_relevance_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'min_trend_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'scoring_weights' => ['sometimes', 'nullable', 'array'],
            'scoring_weights.*' => ['numeric', 'min:0', 'max:1'],

            'research_sources' => ['sometimes', 'array'],
            'research_sources.*.research_source_id' => ['required', 'integer', 'exists:research_sources,id'],
            'research_sources.*.enabled' => ['sometimes', 'boolean'],
            'research_sources.*.weight' => ['sometimes', 'numeric', 'min:0', 'max:5'],
            'research_sources.*.priority' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'research_sources.*.configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyPlatformDefaults(array $data, Platform $platform): array
    {
        $defaults = is_array($platform->default_strategy) ? $platform->default_strategy : [];

        foreach (['content_formats', 'content_types', 'hook_styles', 'tone'] as $field) {
            if (! isset($defaults[$field])) {
                continue;
            }

            if (! array_key_exists($field, $data) || $data[$field] === [] || $data[$field] === null) {
                $data[$field] = $defaults[$field];
            }
        }

        return $data;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function syncSources(ContentChannel $channel, array $sources): void
    {
        $payload = [];

        foreach ($sources as $index => $source) {
            $id = (int) ($source['research_source_id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $payload[$id] = [
                'enabled' => (bool) ($source['enabled'] ?? true),
                'weight' => (float) ($source['weight'] ?? 1.0),
                // Falls back to declaration order so the UI can reorder sources by simply
                // sending them in the desired order, with no explicit priority field.
                'priority' => (int) ($source['priority'] ?? (($index + 1) * 10)),
                // Passed as an array, NOT json_encode()d: the relation declares
                // ->using(ChannelResearchSource::class), so sync() runs these attributes
                // through that pivot model's casts. Encoding here too stores a
                // double-encoded string that reads back as a string, not an array.
                'configuration' => $source['configuration'] ?? [],
            ];
        }

        $channel->researchSources()->sync($payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultSourcesFor(Platform $platform): array
    {
        // A channel created with no sources selected would silently never produce
        // anything. Two keyless, always-available sources make it work immediately;
        // the user refines from there.
        return ResearchSource::whereIn('key', ['google_trends', 'google_news'])
            ->where('enabled', true)
            ->get()
            ->values()
            ->map(fn (ResearchSource $source, int $index) => [
                'research_source_id' => $source->id,
                'enabled' => true,
                'weight' => 1.0,
                'priority' => ($index + 1) * 10,
                'configuration' => [],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function touchesSchedule(array $data): bool
    {
        return array_intersect(
            ['scheduler_enabled', 'research_frequency', 'research_times', 'interval_hours', 'timezone', 'is_active'],
            array_keys($data),
        ) !== [];
    }
}
