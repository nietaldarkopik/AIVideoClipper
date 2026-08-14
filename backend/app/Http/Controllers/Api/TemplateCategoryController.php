<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TemplateCategoryResource;
use App\Models\TemplateCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TemplateCategoryController extends Controller
{
    public function index()
    {
        $categories = TemplateCategory::withCount('templates')->orderBy('sort_order')->orderBy('name')->get();

        return TemplateCategoryResource::collection($categories);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $category = TemplateCategory::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']) . '-' . Str::random(4),
            'description' => $data['description'] ?? null,
            'is_custom' => true,
        ]);

        return TemplateCategoryResource::make($category)->response()->setStatusCode(201);
    }

    public function update(Request $request, TemplateCategory $templateCategory)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        $templateCategory->update($data);

        return TemplateCategoryResource::make($templateCategory);
    }

    public function destroy(TemplateCategory $templateCategory)
    {
        $templateCategory->delete();

        return response()->json(['message' => 'Category deleted.']);
    }
}
