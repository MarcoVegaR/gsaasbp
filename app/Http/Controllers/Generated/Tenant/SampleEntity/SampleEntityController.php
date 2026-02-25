<?php

declare(strict_types=1);

namespace App\Http\Controllers\Generated\Tenant\SampleEntity;

use App\Http\Controllers\Tenant\BaseTenantController;
use App\Http\Requests\Generated\Tenant\SampleEntity\StoreSampleEntityRequest;
use App\Http\Requests\Generated\Tenant\SampleEntity\UpdateSampleEntityRequest;
use App\Models\Generated\Tenant\SampleEntity;
use App\Support\Phase4\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SampleEntityController extends BaseTenantController
{
    private const AUDIT_FIELDS = [
        'title',
        'body',
    ];

    private const REDACTED_FIELDS = [
        'body',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(Request $request): Response
    {
        $tenantId = $this->tenantId($request);

        $items = SampleEntity::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (SampleEntity $resource): array => $this->resourcePayload($resource))
            ->all();

        return Inertia::render('tenant/modules/sample-entity/index', [
            'moduleTitle' => 'Sample Entity',
            'routePath' => '/tenant/modules/sample-entities',
            'fields' => [
                ['name' => 'title', 'label' => 'Title'],
                ['name' => 'body', 'label' => 'Body'],
            ],
            'items' => $items,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('tenant/modules/sample-entity/form', [
            'moduleTitle' => 'Sample Entity',
            'routePath' => '/tenant/modules/sample-entities',
            'mode' => 'create',
            'entity' => null,
        ]);
    }

    public function store(StoreSampleEntityRequest $request): RedirectResponse
    {
        $tenantId = $this->tenantId($request);
        $validated = $request->validated();

        /** @var SampleEntity $resource */
        $resource = SampleEntity::query()->create([
            ...$validated,
            'tenant_id' => $tenantId,
        ]);

        $this->auditLogger->log(
            event: 'tenant.sample-entity.created',
            tenantId: $tenantId,
            actorId: $request->user()?->getAuthIdentifier(),
            properties: [
                'entity' => $this->auditPayload($validated),
                'id' => $resource->getKey(),
            ],
        );

        return redirect()->route('tenant.generated.sample-entity.show', ['sampleEntity' => $resource->getKey()]);
    }

    public function show(SampleEntity $sampleEntity): Response
    {
        return Inertia::render('tenant/modules/sample-entity/show', [
            'moduleTitle' => 'Sample Entity',
            'routePath' => '/tenant/modules/sample-entities',
            'entity' => $this->resourcePayload($sampleEntity),
        ]);
    }

    public function edit(SampleEntity $sampleEntity): Response
    {
        return Inertia::render('tenant/modules/sample-entity/form', [
            'moduleTitle' => 'Sample Entity',
            'routePath' => '/tenant/modules/sample-entities',
            'mode' => 'edit',
            'entity' => $this->resourcePayload($sampleEntity),
        ]);
    }

    public function update(UpdateSampleEntityRequest $request, SampleEntity $sampleEntity): RedirectResponse
    {
        $tenantId = $this->tenantId($request);
        $validated = $request->validated();

        $sampleEntity->fill($validated);
        $sampleEntity->save();

        $this->auditLogger->log(
            event: 'tenant.sample-entity.updated',
            tenantId: $tenantId,
            actorId: $request->user()?->getAuthIdentifier(),
            properties: [
                'entity' => $this->auditPayload($validated),
                'id' => $sampleEntity->getKey(),
            ],
        );

        return redirect()->route('tenant.generated.sample-entity.show', ['sampleEntity' => $sampleEntity->getKey()]);
    }

    public function destroy(Request $request, SampleEntity $sampleEntity): RedirectResponse
    {
        $tenantId = $this->tenantId($request);
        $id = $sampleEntity->getKey();

        $sampleEntity->delete();

        $this->auditLogger->log(
            event: 'tenant.sample-entity.deleted',
            tenantId: $tenantId,
            actorId: $request->user()?->getAuthIdentifier(),
            properties: [
                'id' => $id,
            ],
        );

        return redirect()->route('tenant.generated.sample-entity.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function resourcePayload(SampleEntity $resource): array
    {
        return $resource->only([
            'id',
            'title',
            'body',
            'created_at',
            'updated_at',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function auditPayload(array $validated): array
    {
        $payload = [];

        foreach (self::AUDIT_FIELDS as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $payload[$field] = in_array($field, self::REDACTED_FIELDS, true)
                ? '[REDACTED]'
                : $validated[$field];
        }

        return $payload;
    }
}
