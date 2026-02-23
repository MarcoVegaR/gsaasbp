<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantUser>
 */
class TenantUserFactory extends Factory
{
    protected $model = TenantUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'is_active' => true,
            'is_banned' => false,
            'last_sso_at' => null,
        ];
    }
}
