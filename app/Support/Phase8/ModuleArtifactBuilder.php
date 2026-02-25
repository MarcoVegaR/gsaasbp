<?php

declare(strict_types=1);

namespace App\Support\Phase8;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class ModuleArtifactBuilder
{
    public function build(ModuleDefinition $module, string $rootPath): GeneratedFileSet
    {
        $migrationStamp = now()->format('Y_m_d_His').'_'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $newFiles = [
            sprintf('app/Models/Generated/Tenant/%s.php', $module->classBaseName()) => $this->buildModel($module),
            sprintf('database/factories/Generated/Tenant/%sFactory.php', $module->classBaseName()) => $this->buildFactory($module),
            sprintf(
                'database/migrations/%s_create_%s_table.php',
                $migrationStamp,
                $module->table,
            ) => $this->buildMigration($module),
            sprintf('app/Policies/Generated/Tenant/%sPolicy.php', $module->classBaseName()) => $this->buildPolicy($module),
            sprintf(
                'app/Http/Requests/Generated/Tenant/%1$s/Store%1$sRequest.php',
                $module->classBaseName(),
            ) => $this->buildStoreRequest($module),
            sprintf(
                'app/Http/Requests/Generated/Tenant/%1$s/Update%1$sRequest.php',
                $module->classBaseName(),
            ) => $this->buildUpdateRequest($module),
            sprintf(
                'app/Http/Controllers/Generated/Tenant/%1$s/%1$sController.php',
                $module->classBaseName(),
            ) => $this->buildController($module),
            sprintf('routes/generated/tenant/%s.php', $module->slug) => $this->buildRoutes($module),
            sprintf('resources/js/pages/%s/index.tsx', $module->frontendBasePath()) => $this->buildFrontendIndexPage($module),
            sprintf('resources/js/pages/%s/form.tsx', $module->frontendBasePath()) => $this->buildFrontendFormPage($module),
            sprintf('resources/js/pages/%s/show.tsx', $module->frontendBasePath()) => $this->buildFrontendShowPage($module),
        ];

        $replacements = [
            'config/phase8_modules.php' => $this->buildPhase8ModulesConfig($module, $rootPath),
            'lang/en.json' => $this->buildDictionaryFile('en', $module, $rootPath),
            'lang/es.json' => $this->buildDictionaryFile('es', $module, $rootPath),
        ];

        return new GeneratedFileSet($newFiles, $replacements);
    }

    private function buildModel(ModuleDefinition $module): string
    {
        $classBaseName = $module->classBaseName();
        $casts = $this->indent($this->modelCasts($module), 12);
        $softDeletesTrait = $module->softDeletes ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '';
        $softDeletesUse = $module->softDeletes ? "    use SoftDeletes;\n" : '';

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Models\Generated\Tenant;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
{$softDeletesTrait}
class {$classBaseName} extends Model
{
    use BelongsToTenant;
    use HasFactory;
{$softDeletesUse}
    protected \$table = '{$module->table}';

    protected \$fillable = [
{$this->indent($this->phpArrayLines(['tenant_id', ...array_map(static fn (ModuleFieldDefinition $field): string => $field->name, $module->fields)]), 8)}
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
{$casts}
        ];
    }
}
PHP;
    }

    private function buildFactory(ModuleDefinition $module): string
    {
        $classBaseName = $module->classBaseName();
        $definitionLines = [
            "'tenant_id' => \$tenantId,",
        ];

        foreach ($module->fields as $field) {
            $definitionLines[] = sprintf("'%s' => %s,", $field->name, $this->fakerExpression($field));
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace Database\Factories\Generated\Tenant;

use App\Models\Generated\Tenant\\$classBaseName;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use RuntimeException;

/**
 * @extends Factory<{$classBaseName}>
 */
final class {$classBaseName}Factory extends Factory
{
    protected \$model = {$classBaseName}::class;

    private ?string \$explicitTenantId = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        \$tenantId = \$this->explicitTenantId ?? tenant()?->getTenantKey();

        if (! is_string(\$tenantId) || \$tenantId === '') {
            throw new RuntimeException('{$classBaseName}Factory requires an active tenant context. Use ->forTenant(\$tenant) explicitly in global contexts.');
        }

        return [
{$this->indent(implode("\n", $definitionLines), 12)}
        ];
    }

    public function forTenant(Tenant|string \$tenant): static
    {
        \$tenantId = \$tenant instanceof Tenant
            ? (string) \$tenant->getTenantKey()
            : trim((string) \$tenant);

        if (\$tenantId === '') {
            throw new InvalidArgumentException('forTenant() expects a valid tenant id.');
        }

        \$factory = clone \$this;
        \$factory->explicitTenantId = \$tenantId;

        return \$factory->state(static fn (): array => [
            'tenant_id' => \$tenantId,
        ]);
    }
}
PHP;
    }

    private function buildMigration(ModuleDefinition $module): string
    {
        $usesDb = $module->softDeletes && $this->moduleHasUniqueIndexes($module);

        $columnLines = [
            '$table->id();',
            "\$table->string('tenant_id');",
        ];

        foreach ($module->fields as $field) {
            $columnLines[] = $this->migrationColumnLine($field);
        }

        $columnLines[] = '$table->timestamps();';

        if ($module->softDeletes) {
            $columnLines[] = '$table->softDeletes();';
        }

        $columnLines[] = "\$table->index(['tenant_id']);";

        foreach ($module->indexes as $index) {
            if ($index->isUnique() && $module->softDeletes) {
                continue;
            }

            $indexName = $index->name ?? $this->indexName($module->table, $index->columns, $index->type);
            $method = $index->isUnique() ? 'unique' : 'index';
            $columns = '['.implode(', ', array_map(static fn (string $column): string => "'{$column}'", $index->columns)).']';
            $columnLines[] = sprintf("\$table->%s(%s, '%s');", $method, $columns, $indexName);
        }

        $columnLines[] = "\$table->foreign('tenant_id')";
        $columnLines[] = "    ->references('id')";
        $columnLines[] = "    ->on('tenants')";
        $columnLines[] = '    ->cascadeOnUpdate()';
        $columnLines[] = '    ->restrictOnDelete();';

        foreach ($module->foreignKeys as $foreignKey) {
            $columnLines[] = sprintf("\$table->foreign('%s')", $foreignKey->column);
            $columnLines[] = sprintf("    ->references('%s')", $foreignKey->referencesColumn);
            $columnLines[] = sprintf("    ->on('%s')", $foreignKey->referencesTable);
            $columnLines[] = '    ->cascadeOnUpdate()';
            $columnLines[] = $foreignKey->cascadesOnDelete() ? '    ->cascadeOnDelete();' : '    ->restrictOnDelete();';
        }

        $partialIndexLines = [];

        if ($module->softDeletes) {
            foreach ($module->indexes as $index) {
                if (! $index->isUnique()) {
                    continue;
                }

                $indexName = $index->name ?? $this->indexName($module->table, $index->columns, 'live_unique');
                $columns = implode(', ', array_map(static fn (string $column): string => sprintf('"%s"', $column), $index->columns));
                $partialIndexLines[] = sprintf(
                    "DB::statement('CREATE UNIQUE INDEX \"%s\" ON \"%s\" (%s) WHERE \"deleted_at\" IS NULL');",
                    $indexName,
                    $module->table,
                    $columns,
                );
            }
        }

        $dbImport = $usesDb ? "use Illuminate\\Support\\Facades\\DB;\n" : '';

        return <<<PHP
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
{$dbImport}
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$module->table}', function (Blueprint \$table): void {
{$this->indent(implode("\n", $columnLines), 12)}
        });
{$this->indent(implode("\n", $partialIndexLines), 8)}
    }

    public function down(): void
    {
        Schema::dropIfExists('{$module->table}');
    }
};
PHP;
    }

    private function buildPolicy(ModuleDefinition $module): string
    {
        $classBaseName = $module->classBaseName();

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Policies\Generated\Tenant;

use App\Models\Generated\Tenant\\$classBaseName;
use App\Models\User;
use App\Policies\BaseTenantPolicy;

final class {$classBaseName}Policy extends BaseTenantPolicy
{
    public function viewAny(User \$user): bool
    {
        return false;
    }

    public function view(User \$user, {$classBaseName} \$resource): bool
    {
        return false;
    }

    public function create(User \$user): bool
    {
        return false;
    }

    public function update(User \$user, {$classBaseName} \$resource): bool
    {
        return false;
    }

    public function delete(User \$user, {$classBaseName} \$resource): bool
    {
        return false;
    }
}
PHP;
    }

    private function buildStoreRequest(ModuleDefinition $module): string
    {
        $classBaseName = $module->classBaseName();

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Http\Requests\Generated\Tenant\\$classBaseName;

use App\Http\Requests\Tenant\BaseTenantRequest;
use Illuminate\Validation\Rule;

final class Store{$classBaseName}Request extends BaseTenantRequest
{
    public function authorize(): bool
    {
        return \$this->user()?->can('{$module->abilityPrefix()}.create') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
{$this->indent($this->rulesBody($module, false), 12)}
        ];
    }
}
PHP;
    }

    private function buildUpdateRequest(ModuleDefinition $module): string
    {
        $classBaseName = $module->classBaseName();

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Http\Requests\Generated\Tenant\\$classBaseName;

use App\Http\Requests\Tenant\BaseTenantRequest;
use Illuminate\Validation\Rule;

final class Update{$classBaseName}Request extends BaseTenantRequest
{
    public function authorize(): bool
    {
        return \$this->user()?->can('{$module->abilityPrefix()}.update') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        \$resourceId = \$this->resourceId();

        return [
{$this->indent($this->rulesBody($module, true), 12)}
        ];
    }

    private function resourceId(): int|string|null
    {
        \$resource = \$this->route('{$module->routeParameter()}');

        if (is_object(\$resource) && method_exists(\$resource, 'getKey')) {
            /** @var int|string|null \$key */
            \$key = \$resource->getKey();

            return \$key;
        }

        if (is_scalar(\$resource)) {
            return (string) \$resource;
        }

        return null;
    }
}
PHP;
    }

    private function buildController(ModuleDefinition $module): string
    {
        $classBaseName = $module->classBaseName();
        $auditFields = array_map(
            static fn (ModuleFieldDefinition $field): string => $field->name,
            array_values(array_filter($module->fields, static fn (ModuleFieldDefinition $field): bool => $field->audit)),
        );

        $redactedFields = array_map(
            static fn (ModuleFieldDefinition $field): string => $field->name,
            array_values(array_filter($module->fields, static fn (ModuleFieldDefinition $field): bool => $field->audit && ($field->pii || $field->secret))),
        );

        $modelPayload = array_merge(
            ['id'],
            array_map(static fn (ModuleFieldDefinition $field): string => $field->name, $module->fields),
            ['created_at', 'updated_at'],
        );

        $payloadLines = $this->phpArrayLines($modelPayload);
        $fieldMetadataLines = [];
        $resourceVariable = '$'.$module->routeParameter();

        foreach ($module->fields as $field) {
            $fieldMetadataLines[] = sprintf("['name' => '%s', 'label' => '%s']", $field->name, $this->fieldLabel($field->name));
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Generated\Tenant\\$classBaseName;

use App\Http\Controllers\Tenant\BaseTenantController;
use App\Http\Requests\Generated\Tenant\\$classBaseName\\Store{$classBaseName}Request;
use App\Http\Requests\Generated\Tenant\\$classBaseName\\Update{$classBaseName}Request;
use App\Models\Generated\Tenant\\$classBaseName;
use App\Support\Phase4\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class {$classBaseName}Controller extends BaseTenantController
{
    private const AUDIT_FIELDS = [
{$this->indent($this->phpArrayLines($auditFields), 8)}
    ];

    private const REDACTED_FIELDS = [
{$this->indent($this->phpArrayLines($redactedFields), 8)}
    ];

    public function __construct(
        private readonly AuditLogger \$auditLogger,
    ) {}

    public function index(Request \$request): Response
    {
        \$tenantId = \$this->tenantId(\$request);

        \$items = {$classBaseName}::query()
            ->where('tenant_id', \$tenantId)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn ({$classBaseName} \$resource): array => \$this->resourcePayload(\$resource))
            ->all();

        return Inertia::render('{$module->frontendBasePath()}/index', [
            'moduleTitle' => '{$module->title()}',
            'routePath' => '{$module->routePath()}',
            'fields' => [
{$this->indent(implode(",\n", $fieldMetadataLines), 16)}
            ],
            'items' => \$items,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('{$module->frontendBasePath()}/form', [
            'moduleTitle' => '{$module->title()}',
            'routePath' => '{$module->routePath()}',
            'mode' => 'create',
            'entity' => null,
        ]);
    }

    public function store(Store{$classBaseName}Request \$request): RedirectResponse
    {
        \$tenantId = \$this->tenantId(\$request);
        \$validated = \$request->validated();

        /** @var {$classBaseName} \$resource */
        \$resource = {$classBaseName}::query()->create([
            ...\$validated,
            'tenant_id' => \$tenantId,
        ]);

        \$this->auditLogger->log(
            event: '{$module->abilityPrefix()}.created',
            tenantId: \$tenantId,
            actorId: \$request->user()?->getAuthIdentifier(),
            properties: [
                'entity' => \$this->auditPayload(\$validated),
                'id' => \$resource->getKey(),
            ],
        );

        return redirect()->route('{$module->routeNamePrefix()}.show', ['{$module->routeParameter()}' => \$resource->getKey()]);
    }

    public function show({$classBaseName} {$resourceVariable}): Response
    {
        return Inertia::render('{$module->frontendBasePath()}/show', [
            'moduleTitle' => '{$module->title()}',
            'routePath' => '{$module->routePath()}',
            'entity' => \$this->resourcePayload({$resourceVariable}),
        ]);
    }

    public function edit({$classBaseName} {$resourceVariable}): Response
    {
        return Inertia::render('{$module->frontendBasePath()}/form', [
            'moduleTitle' => '{$module->title()}',
            'routePath' => '{$module->routePath()}',
            'mode' => 'edit',
            'entity' => \$this->resourcePayload({$resourceVariable}),
        ]);
    }

    public function update(Update{$classBaseName}Request \$request, {$classBaseName} {$resourceVariable}): RedirectResponse
    {
        \$tenantId = \$this->tenantId(\$request);
        \$validated = \$request->validated();

        {$resourceVariable}->fill(\$validated);
        {$resourceVariable}->save();

        \$this->auditLogger->log(
            event: '{$module->abilityPrefix()}.updated',
            tenantId: \$tenantId,
            actorId: \$request->user()?->getAuthIdentifier(),
            properties: [
                'entity' => \$this->auditPayload(\$validated),
                'id' => {$resourceVariable}->getKey(),
            ],
        );

        return redirect()->route('{$module->routeNamePrefix()}.show', ['{$module->routeParameter()}' => {$resourceVariable}->getKey()]);
    }

    public function destroy(Request \$request, {$classBaseName} {$resourceVariable}): RedirectResponse
    {
        \$tenantId = \$this->tenantId(\$request);
        \$id = {$resourceVariable}->getKey();

        {$resourceVariable}->delete();

        \$this->auditLogger->log(
            event: '{$module->abilityPrefix()}.deleted',
            tenantId: \$tenantId,
            actorId: \$request->user()?->getAuthIdentifier(),
            properties: [
                'id' => \$id,
            ],
        );

        return redirect()->route('{$module->routeNamePrefix()}.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function resourcePayload({$classBaseName} \$resource): array
    {
        return \$resource->only([
{$this->indent($payloadLines, 12)}
        ]);
    }

    /**
     * @param  array<string, mixed>  \$validated
     * @return array<string, mixed>
     */
    private function auditPayload(array \$validated): array
    {
        \$payload = [];

        foreach (self::AUDIT_FIELDS as \$field) {
            if (! array_key_exists(\$field, \$validated)) {
                continue;
            }

            \$payload[\$field] = in_array(\$field, self::REDACTED_FIELDS, true)
                ? '[REDACTED]'
                : \$validated[\$field];
        }

        return \$payload;
    }
}
PHP;
    }

    private function buildRoutes(ModuleDefinition $module): string
    {
        $classBaseName = $module->classBaseName();
        $controllerClassName = $classBaseName.'Controller';

        return <<<PHP
<?php

declare(strict_types=1);

use App\Http\Controllers\Generated\Tenant\\$classBaseName\\$controllerClassName;
use App\Models\Generated\Tenant\\$classBaseName;
use Illuminate\Support\Facades\Route;

Route::tenantResource(
    'tenant/modules/{$module->routeSlug()}',
    {$controllerClassName}::class,
    {$classBaseName}::class,
    '{$module->routeParameter()}',
    '{$module->routeNamePrefix()}',
    '{$module->abilityPrefix()}',
    ['phase5.tenant.active'],
);
PHP;
    }

    private function buildFrontendIndexPage(ModuleDefinition $module): string
    {
        $classBaseName = $module->classBaseName();

        return <<<TSX
import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

type ModuleField = {
    name: string;
    label: string;
};

type ModuleItem = Record<string, unknown> & {
    id: number | string;
};

type ModuleIndexPageProps = {
    moduleTitle: string;
    routePath: string;
    fields: ModuleField[];
    items: ModuleItem[];
};

export default function {$classBaseName}IndexPage({
    moduleTitle,
    routePath,
    fields,
    items,
}: ModuleIndexPageProps) {
    return (
        <AppLayout>
            <div className="space-y-6 p-4 sm:p-6">
                <Card>
                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle>{moduleTitle}</CardTitle>
                            <CardDescription>Tenant-scoped records generated by Phase 8 DX.</CardDescription>
                        </div>
                        <Button asChild>
                            <Link href={`\${routePath}/create`}>Create {moduleTitle}</Link>
                        </Button>
                    </CardHeader>
                </Card>

                <div className="grid gap-4">
                    {items.length === 0 ? (
                        <Card>
                            <CardContent className="py-10 text-sm text-muted-foreground">
                                No records yet.
                            </CardContent>
                        </Card>
                    ) : (
                        items.map((item) => (
                            <Card key={String(item.id)}>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-base">Record #{String(item.id)}</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {fields.map((field) => (
                                        <div key={`\${String(item.id)}-\${field.name}`} className="text-sm">
                                            <span className="font-medium">{field.label}:</span>{' '}
                                            <span className="text-muted-foreground">
                                                {String(item[field.name] ?? '-')}
                                            </span>
                                        </div>
                                    ))}

                                    <div className="flex flex-wrap gap-2 pt-2">
                                        <Button variant="outline" asChild>
                                            <Link href={`\${routePath}/\${String(item.id)}`}>View</Link>
                                        </Button>
                                        <Button variant="secondary" asChild>
                                            <Link href={`\${routePath}/\${String(item.id)}/edit`}>Edit</Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
TSX;
    }

    private function buildFrontendFormPage(ModuleDefinition $module): string
    {
        $classBaseName = $module->classBaseName();
        $fieldInitializers = [];
        $fieldBindings = [];

        foreach ($module->fields as $field) {
            $default = $field->nullable ? 'null' : "''";
            if (in_array($field->type, ['integer'], true)) {
                $default = $field->nullable ? 'null' : '0';
            } elseif ($field->type === 'boolean') {
                $default = $field->nullable ? 'null' : 'false';
            }

            $fieldInitializers[] = sprintf('%s: %s,', $field->name, $default);
            $fieldBindings[] = $this->frontendFieldBlock($field);
        }

        return <<<TSX
import { FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';

type FormMode = 'create' | 'edit';

type {$classBaseName}FormPayload = {
{$this->indent($this->frontendFormPayloadType($module), 4)}
};

type {$classBaseName}FormPageProps = {
    moduleTitle: string;
    routePath: string;
    mode: FormMode;
    entity: (Record<string, unknown> & { id: number | string }) | null;
};

export default function {$classBaseName}FormPage({
    moduleTitle,
    routePath,
    mode,
    entity,
}: {$classBaseName}FormPageProps) {
    const form = useForm<{$classBaseName}FormPayload>({
{$this->indent(implode("\n", $fieldInitializers), 8)}
    });

    const isEdit = mode === 'edit' && entity !== null;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.transform((data) => data);

        if (isEdit) {
            form.patch(`\${routePath}/\${String(entity.id)}`);

            return;
        }

        form.post(routePath);
    };

    return (
        <AppLayout>
            <div className="space-y-6 p-4 sm:p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{isEdit ? `Edit \${moduleTitle}` : `Create \${moduleTitle}`}</CardTitle>
                        <CardDescription>
                            Generated by make:saas-module with tenant-safe defaults.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form className="space-y-4" onSubmit={submit}>
{$this->indent(implode("\n", $fieldBindings), 28)}

                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={form.processing}>
                                    {isEdit ? 'Save changes' : 'Create'}
                                </Button>
                                <Button type="button" variant="outline" asChild>
                                    <Link href={routePath}>Cancel</Link>
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
TSX;
    }

    private function buildFrontendShowPage(ModuleDefinition $module): string
    {
        $classBaseName = $module->classBaseName();
        $fieldRows = [];

        foreach ($module->fields as $field) {
            $fieldRows[] = sprintf(
                "<div className=\"text-sm\"><span className=\"font-medium\">%s:</span> <span className=\"text-muted-foreground\">{String(entity.%s ?? '-')}</span></div>",
                $this->fieldLabel($field->name),
                $field->name,
            );
        }

        return <<<TSX
import { Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';

type {$classBaseName}ShowEntity = {
    id: number | string;
{$this->indent($this->frontendShowType($module), 4)}
};

type {$classBaseName}ShowPageProps = {
    moduleTitle: string;
    routePath: string;
    entity: {$classBaseName}ShowEntity;
};

export default function {$classBaseName}ShowPage({ moduleTitle, routePath, entity }: {$classBaseName}ShowPageProps) {
    const destroy = () => {
        router.delete(`\${routePath}/\${String(entity.id)}`);
    };

    return (
        <AppLayout>
            <div className="space-y-6 p-4 sm:p-6">
                <Card>
                    <CardHeader>
                        <CardTitle>{moduleTitle} #{String(entity.id)}</CardTitle>
                        <CardDescription>Tenant-scoped details view.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2">
{$this->indent(implode("\n", $fieldRows), 24)}

                        <div className="flex flex-wrap gap-2 pt-4">
                            <Button variant="outline" asChild>
                                <Link href={routePath}>Back</Link>
                            </Button>
                            <Button variant="secondary" asChild>
                                <Link href={`\${routePath}/\${String(entity.id)}/edit`}>Edit</Link>
                            </Button>
                            <Button variant="destructive" type="button" onClick={destroy}>
                                Delete
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
TSX;
    }

    private function buildPhase8ModulesConfig(ModuleDefinition $module, string $rootPath): string
    {
        $path = $rootPath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'phase8_modules.php';

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Phase 8 modules config [%s] is missing.', $path));
        }

        /** @var mixed $loaded */
        $loaded = require $path;

        $modules = [];
        $businessModels = [];

        if (is_array($loaded)) {
            $modules = is_array($loaded['modules'] ?? null) ? $loaded['modules'] : [];
            $businessModels = is_array($loaded['business_models'] ?? null) ? $loaded['business_models'] : [];
        }

        foreach ($modules as $existingModule) {
            if (! is_array($existingModule)) {
                continue;
            }

            if (($existingModule['slug'] ?? null) === $module->slug) {
                throw new InvalidArgumentException(sprintf('Module [%s] already exists in config/phase8_modules.php.', $module->slug));
            }
        }

        $modules[] = [
            'slug' => $module->slug,
            'title' => $module->title(),
            'route_path' => $module->routePath(),
            'ability_prefix' => $module->abilityPrefix(),
            'model_class' => $module->modelClass(),
            'policy_class' => $module->policyClass(),
        ];

        usort($modules, static fn (array $a, array $b): int => strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? '')));

        $businessModels[] = $module->modelClass();
        $businessModels = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $businessModels,
        ), static fn (string $value): bool => $value !== '')));

        sort($businessModels);

        $payload = [
            'modules' => $modules,
            'business_models' => $businessModels,
        ];

        return "<?php\n\ndeclare(strict_types=1);\n\nreturn ".$this->exportPhpArray($payload).";\n";
    }

    private function buildDictionaryFile(string $locale, ModuleDefinition $module, string $rootPath): string
    {
        $path = $rootPath.DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.'.json';

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Missing locale dictionary [%s].', $path));
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Failed to decode locale dictionary [%s].', $path), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Locale dictionary [%s] must decode as object.', $path));
        }

        $title = $module->title();
        $description = $locale === 'es'
            ? 'Modulo de tenant generado por Phase 8 DX.'
            : 'Tenant module generated by Phase 8 DX.';

        $decoded['core.nav.generated.'.$module->slug] = $title;
        $decoded['page.tenant.modules.'.$module->slug.'.title'] = $title;
        $decoded['page.tenant.modules.'.$module->slug.'.description'] = $description;

        ksort($decoded);

        try {
            return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode locale dictionary.', previous: $exception);
        }
    }

    /**
     * @return list<string>
     */
    private function modelCasts(ModuleDefinition $module): array
    {
        $casts = [];

        foreach ($module->fields as $field) {
            $cast = match ($field->type) {
                'integer' => 'integer',
                'boolean' => 'boolean',
                'json' => 'array',
                'date' => 'date',
                'datetime', 'timestamp' => 'datetime',
                default => null,
            };

            if ($cast === null) {
                continue;
            }

            $casts[] = sprintf("'%s' => '%s',", $field->name, $cast);
        }

        return $casts;
    }

    private function migrationColumnLine(ModuleFieldDefinition $field): string
    {
        $line = match ($field->type) {
            'string' => sprintf("\$table->string('%s')", $field->name),
            'text' => sprintf("\$table->text('%s')", $field->name),
            'integer' => sprintf("\$table->integer('%s')", $field->name),
            'boolean' => sprintf("\$table->boolean('%s')", $field->name),
            'uuid' => sprintf("\$table->uuid('%s')", $field->name),
            'json' => sprintf("\$table->json('%s')", $field->name),
            'date' => sprintf("\$table->date('%s')", $field->name),
            'datetime' => sprintf("\$table->dateTime('%s')", $field->name),
            'timestamp' => sprintf("\$table->timestamp('%s')", $field->name),
            default => throw new InvalidArgumentException(sprintf('Unsupported migration type [%s].', $field->type)),
        };

        if ($field->nullable) {
            return $line.'->nullable();';
        }

        if ($field->type === 'boolean') {
            return $line.'->default(false);';
        }

        return $line.';';
    }

    private function moduleHasUniqueIndexes(ModuleDefinition $module): bool
    {
        foreach ($module->indexes as $index) {
            if ($index->isUnique()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    private function indexName(string $table, array $columns, string $suffix): string
    {
        $base = strtolower($table.'_'.implode('_', $columns).'_'.$suffix);

        return strlen($base) > 60 ? substr($base, 0, 60) : $base;
    }

    private function fakerExpression(ModuleFieldDefinition $field): string
    {
        return match ($field->type) {
            'string' => 'fake()->sentence(4)',
            'text' => 'fake()->paragraph()',
            'integer' => 'fake()->numberBetween(1, 1000)',
            'boolean' => 'fake()->boolean()',
            'uuid' => '(string) fake()->uuid()',
            'json' => "['sample' => fake()->word()]",
            'date' => 'fake()->date()',
            'datetime', 'timestamp' => 'fake()->dateTime()',
            default => 'null',
        };
    }

    private function rulesBody(ModuleDefinition $module, bool $isUpdate): string
    {
        $rulesLines = [];

        foreach ($module->fields as $field) {
            $rules = [];
            $rules[] = $field->nullable ? "'nullable'" : "'required'";

            foreach ($this->fieldValidationRules($field) as $rule) {
                $rules[] = $rule;
            }

            if ($field->unique) {
                $uniqueRule = sprintf("Rule::unique('%s', '%s')", $module->table, $field->name);

                if ($isUpdate) {
                    $uniqueRule .= '->ignore($resourceId)';
                }

                $uniqueRule .= "->where(fn (\$query) => \$query->where('tenant_id', \$this->tenantId()))";

                $rules[] = $uniqueRule;
            }

            foreach ($module->foreignKeys as $foreignKey) {
                if ($foreignKey->column !== $field->name) {
                    continue;
                }

                $existsRule = sprintf("Rule::exists('%s', '%s')", $foreignKey->referencesTable, $foreignKey->referencesColumn);

                if ($foreignKey->isTenantScoped()) {
                    $existsRule .= "->where(fn (\$query) => \$query->where('tenant_id', \$this->tenantId()))";
                }

                $rules[] = $existsRule;
            }

            $rulesLines[] = sprintf("'%s' => [%s],", $field->name, implode(', ', $rules));
        }

        return implode("\n", $rulesLines);
    }

    /**
     * @return list<string>
     */
    private function fieldValidationRules(ModuleFieldDefinition $field): array
    {
        return match ($field->type) {
            'string' => ["'string'", "'max:255'"],
            'text' => ["'string'"],
            'integer' => ["'integer'"],
            'boolean' => ["'boolean'"],
            'uuid' => ["'uuid'"],
            'json' => ["'array'"],
            'date' => ["'date'"],
            'datetime', 'timestamp' => ["'date'"],
            default => [],
        };
    }

    private function frontendFormPayloadType(ModuleDefinition $module): string
    {
        $lines = [];

        foreach ($module->fields as $field) {
            $type = match ($field->type) {
                'integer' => 'number | null',
                'boolean' => 'boolean | null',
                'json' => 'Record<string, unknown> | null',
                default => 'string | null',
            };

            $lines[] = sprintf('%s: %s;', $field->name, $type);
        }

        return implode("\n", $lines);
    }

    private function frontendShowType(ModuleDefinition $module): string
    {
        $lines = [];

        foreach ($module->fields as $field) {
            $lines[] = sprintf('%s?: unknown;', $field->name);
        }

        $lines[] = 'created_at?: string | null;';
        $lines[] = 'updated_at?: string | null;';

        return implode("\n", $lines);
    }

    private function frontendFieldBlock(ModuleFieldDefinition $field): string
    {
        $label = $this->fieldLabel($field->name);
        $fieldKey = $field->name;

        if ($field->type === 'boolean') {
            return <<<TSX
<div className="space-y-2">
    <Label htmlFor="{$fieldKey}">{$label}</Label>
    <Input
        id="{$fieldKey}"
        type="checkbox"
        checked={Boolean(form.data.{$fieldKey})}
        onChange={(event) => form.setData('{$fieldKey}', event.currentTarget.checked)}
    />
</div>
TSX;
        }

        if ($field->type === 'integer') {
            return <<<TSX
<div className="space-y-2">
    <Label htmlFor="{$fieldKey}">{$label}</Label>
    <Input
        id="{$fieldKey}"
        type="number"
        value={form.data.{$fieldKey} ?? ''}
        onChange={(event) => form.setData('{$fieldKey}', Number(event.currentTarget.value))}
    />
</div>
TSX;
        }

        return <<<TSX
<div className="space-y-2">
    <Label htmlFor="{$fieldKey}">{$label}</Label>
    <Input
        id="{$fieldKey}"
        value={String(form.data.{$fieldKey} ?? '')}
        onChange={(event) => form.setData('{$fieldKey}', event.currentTarget.value)}
    />
</div>
TSX;
    }

    private function fieldLabel(string $field): string
    {
        return ucwords(str_replace('_', ' ', $field));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function phpArrayLines(array $values): array
    {
        return array_map(static fn (string $value): string => sprintf("'%s',", $value), $values);
    }

    /**
     * @param  string|list<string>  $value
     */
    private function indent(string|array $value, int $spaces): string
    {
        $padding = str_repeat(' ', $spaces);
        $text = is_array($value) ? implode("\n", $value) : $value;

        if ($text === '') {
            return '';
        }

        return $padding.str_replace("\n", "\n".$padding, $text);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function exportPhpArray(array $payload): string
    {
        $exported = var_export($payload, true);

        if ($exported === '') {
            throw new RuntimeException('Failed to export PHP array.');
        }

        return $exported;
    }
}
