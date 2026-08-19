<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Realistic API traffic so the dashboards have something to render.
        // Local/dev only — it creates users and ~17k request rows, and the
        // seeder refuses to run in production regardless.
        if (! app()->isProduction()) {
            $this->call(ApiAnalyticsSeeder::class);
        }
    }
}
