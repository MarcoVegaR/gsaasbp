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
        if (app()->environment('testing')) {
            User::query()->firstOrCreate([
                'email' => 'test@example.com',
            ], [
                'name' => 'Test User',
                'email_verified_at' => now(),
                'password' => 'password',
            ]);

            $tenant = Tenant::firstOrCreate([
                'id' => '00000000-0000-0000-0000-000000000001',
            ]);

            $tenant->domains()->firstOrCreate([
                'domain' => env('PLAYWRIGHT_TENANT_DOMAIN', 'tenant.localhost'),
            ]);

            return;
        }

        $this->call(DemoDataSeeder::class);
    }
}
