<?php

declare(strict_types=1);

namespace Database\Factories\Generated\Tenant;

use App\Models\Generated\Tenant\SampleEntity;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use RuntimeException;

/**
 * @extends Factory<SampleEntity>
 */
final class SampleEntityFactory extends Factory
{
    protected $model = SampleEntity::class;

    private ?string $explicitTenantId = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = $this->explicitTenantId ?? tenant()?->getTenantKey();

        if (! is_string($tenantId) || $tenantId === '') {
            throw new RuntimeException('SampleEntityFactory requires an active tenant context. Use ->forTenant($tenant) explicitly in global contexts.');
        }

        return [
            'tenant_id' => $tenantId,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
        ];
    }

    public function forTenant(Tenant|string $tenant): static
    {
        $tenantId = $tenant instanceof Tenant
            ? (string) $tenant->getTenantKey()
            : trim((string) $tenant);

        if ($tenantId === '') {
            throw new InvalidArgumentException('forTenant() expects a valid tenant id.');
        }

        $factory = clone $this;
        $factory->explicitTenantId = $tenantId;

        return $factory->state(static fn (): array => [
            'tenant_id' => $tenantId,
        ]);
    }
}
