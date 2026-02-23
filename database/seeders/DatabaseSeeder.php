<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        if (app()->environment('testing')) {
            $tenant = Tenant::firstOrCreate([
                'id' => '00000000-0000-0000-0000-000000000001',
            ]);

            $tenant->domains()->firstOrCreate([
                'domain' => env('PLAYWRIGHT_TENANT_DOMAIN', 'tenant.localhost'),
            ]);
        }
    }
}
