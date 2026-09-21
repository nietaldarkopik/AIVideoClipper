<?php

namespace Database\Seeders;

use App\Models\TemplateCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TemplateCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Podcast', 'Gaming', 'Education', 'Motivation', 'Business',
            'News', 'Interview', 'Comedy', 'Vlog', 'Storytelling', 'Product', 'Personal Branding',
            'Sports', 'Finance', 'Beauty', 'Travel', 'Food', 'Fitness', 'Tech Review', 'Music', 'True Crime',
        ];

        foreach ($categories as $i => $name) {
            TemplateCategory::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $i, 'is_custom' => false]
            );
        }
    }
}
