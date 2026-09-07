<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@clipper.test'],
            ['name' => 'Admin', 'password' => bcrypt('password'), 'role' => User::ROLE_ADMIN]
        );

        User::updateOrCreate(
            ['email' => 'demo@clipper.test'],
            ['name' => 'Demo Creator', 'password' => bcrypt('password'), 'role' => User::ROLE_USER]
        );

        $this->call([
            TemplateCategorySeeder::class,
            TemplateSeeder::class,
            // Order matters: channels reference platforms and research sources by key.
            PlatformSeeder::class,
            ResearchSourceSeeder::class,
            ChannelTemplateSeeder::class,
            ContentChannelSeeder::class,
        ]);
    }
}
