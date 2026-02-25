<?php

declare(strict_types=1);

namespace App\Http\Requests\Generated\Tenant\SampleEntity;

use App\Http\Requests\Tenant\BaseTenantRequest;
use Illuminate\Validation\Rule;

final class UpdateSampleEntityRequest extends BaseTenantRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tenant.sample-entity.update') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $resourceId = $this->resourceId();

        return [
            'title' => ['required', 'string', 'max:255', Rule::unique('tenant_sample_entities', 'title')->ignore($resourceId)->where(fn ($query) => $query->where('tenant_id', $this->tenantId()))],
            'body' => ['nullable', 'string'],
        ];
    }

    private function resourceId(): int|string|null
    {
        $resource = $this->route('sampleEntity');

        if (is_object($resource) && method_exists($resource, 'getKey')) {
            /** @var int|string|null $key */
            $key = $resource->getKey();

            return $key;
        }

        if (is_scalar($resource)) {
            return (string) $resource;
        }

        return null;
    }
}
