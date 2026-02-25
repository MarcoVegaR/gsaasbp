<?php

declare(strict_types=1);

namespace App\Http\Requests\Generated\Tenant\SampleEntity;

use App\Http\Requests\Tenant\BaseTenantRequest;
use Illuminate\Validation\Rule;

final class StoreSampleEntityRequest extends BaseTenantRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('tenant.sample-entity.create') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255', Rule::unique('tenant_sample_entities', 'title')->where(fn ($query) => $query->where('tenant_id', $this->tenantId()))],
            'body' => ['nullable', 'string'],
        ];
    }
}
