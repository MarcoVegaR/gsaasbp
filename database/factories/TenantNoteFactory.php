<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TenantNote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantNote>
 */
class TenantNoteFactory extends Factory
{
    protected $model = TenantNote::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->sentence(),
        ];
    }
}
