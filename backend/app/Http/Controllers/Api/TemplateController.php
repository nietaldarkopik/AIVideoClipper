<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateResource;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Services\Video\DefaultTemplateConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $query = Template::with(['category', 'currentVersion'])->where('status', '!=', 'archived');

        if ($request->filled('category_id')) {
            $query->where('template_category_id', $request->integer('category_id'));
        }
        if ($request->filled('aspect_ratio')) {
            $query->where('aspect_ratio', $request->string('aspect_ratio'));
        }
        if ($request->boolean('include_archived') && $request->user()?->isAdmin()) {
            $query = Template::with(['category', 'currentVersion']);
        }

        return TemplateResource::collection($query->orderBy('name')->get());
    }

    public function show(Template $template)
    {
        return TemplateResource::make($template->load(['category', 'currentVersion', 'versions' => fn ($q) => $q->orderByDesc('version_number')]));
    }

    /**
     * Admin only (see routes/api.php middleware): create a new template + its v1.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'template_category_id' => ['nullable', 'exists:template_categories,id'],
            'aspect_ratio' => ['required', Rule::in(['9:16', '1:1', '16:9'])],
            'resolution_width' => ['nullable', 'integer'],
            'resolution_height' => ['nullable', 'integer'],
            'config' => ['nullable', 'array'],
        ]);

        [$defaultW, $defaultH] = \App\Services\Video\AspectRatio::resolution($data['aspect_ratio']);

        $template = Template::create([
            'template_category_id' => $data['template_category_id'] ?? null,
            'created_by' => $request->user()->id,
            'name' => $data['name'],
            'slug' => Str::slug($data['name']) . '-' . Str::random(4),
            'description' => $data['description'] ?? null,
            'aspect_ratio' => $data['aspect_ratio'],
            'resolution_width' => $data['resolution_width'] ?? $defaultW,
            'resolution_height' => $data['resolution_height'] ?? $defaultH,
            'status' => 'published',
        ]);

        $version = TemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'label' => 'v1',
            'is_published' => true,
            'created_by' => $request->user()->id,
            'config' => array_replace_recursive(DefaultTemplateConfig::config(), $data['config'] ?? []),
        ]);

        $template->update(['current_version_id' => $version->id]);

        return TemplateResource::make($template->load(['category', 'currentVersion']))->response()->setStatusCode(201);
    }

    /**
     * Metadata-only fields update in place. A `config` change creates a NEW version
     * (existing clips keep pointing at their original template_version_id).
     */
    public function update(Request $request, Template $template)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'template_category_id' => ['sometimes', 'nullable', 'exists:template_categories,id'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'config' => ['sometimes', 'array'],
            'label' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $template->update(collect($data)->except(['config', 'label'])->all());

        if (array_key_exists('config', $data)) {
            $nextVersionNumber = $template->versions()->max('version_number') + 1;
            $baseConfig = $template->currentVersion?->config ?? DefaultTemplateConfig::config();

            $version = TemplateVersion::create([
                'template_id' => $template->id,
                'version_number' => $nextVersionNumber,
                'label' => $data['label'] ?? ('v' . $nextVersionNumber),
                'is_published' => true,
                'created_by' => $request->user()->id,
                'config' => array_replace_recursive($baseConfig, $data['config']),
            ]);

            $template->update(['current_version_id' => $version->id]);
        }

        return TemplateResource::make($template->fresh(['category', 'currentVersion']));
    }

    public function duplicate(Request $request, Template $template)
    {
        $copy = $template->replicate(['slug', 'current_version_id']);
        $copy->name = $template->name . ' (Copy)';
        $copy->slug = Str::slug($copy->name) . '-' . Str::random(4);
        $copy->status = 'draft';
        $copy->is_system = false;
        $copy->created_by = $request->user()->id;
        $copy->save();

        if ($template->currentVersion) {
            $version = TemplateVersion::create([
                'template_id' => $copy->id,
                'version_number' => 1,
                'label' => 'v1',
                'is_published' => true,
                'created_by' => $request->user()->id,
                'config' => $template->currentVersion->config,
            ]);
            $copy->update(['current_version_id' => $version->id]);
        }

        return TemplateResource::make($copy->load(['category', 'currentVersion']))->response()->setStatusCode(201);
    }

    public function archive(Template $template)
    {
        $template->update(['status' => 'archived']);

        return TemplateResource::make($template);
    }

    public function destroy(Template $template)
    {
        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }
}
