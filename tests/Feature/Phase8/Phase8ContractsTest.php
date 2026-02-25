<?php

declare(strict_types=1);

use App\Models\Generated\Tenant\SampleEntity;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\Phase8\GeneratorLock;
use App\Support\Phase8\ModuleGenerationService;
use App\Support\Phase8\ModuleSchemaParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'tenancy.central_domains' => ['localhost', '127.0.0.1'],
        'phase8.generator.test_fail_after_replace' => false,
        'phase8.generator.lock_timeout_seconds' => 1,
    ]);

    $this->phase8Roots = [];
});

afterEach(function (): void {
    foreach ($this->phase8Roots as $root) {
        phase8RemoveDirectory($root);
    }
});

function phase8CreateFixtureRoot(): string
{
    $root = storage_path('framework/testing/phase8/'.Str::uuid()->toString());

    phase8WriteFile($root.'/config/phase8_modules.php', <<<'PHP'
<?php

declare(strict_types=1);

return [
    'modules' => [],
    'business_models' => [],
];
PHP);

    phase8WriteFile($root.'/lang/en.json', "{}\n");
    phase8WriteFile($root.'/lang/es.json', "{}\n");

    return $root;
}

function phase8ConfigureGeneratorPaths(string $root, array $overrides = []): void
{
    config([
        'phase8.generator.root_path' => $root,
        'phase8.generator.staging_root' => $root.'/storage/framework/module-generator',
        'phase8.generator.lock_file' => $root.'/storage/framework/module-generator/.phase8.lock',
        ...$overrides,
    ]);
}

function phase8WriteFile(string $path, string $contents): void
{
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents($path, $contents);
}

function phase8WriteSchema(string $root, string $fileName, string $contents): string
{
    $path = $root.'/schemas/'.$fileName;

    phase8WriteFile($path, $contents);

    return $path;
}

function phase8CreateTenant(string $domain): Tenant
{
    $tenant = Tenant::create([
        'id' => (string) Str::uuid(),
        'status' => 'active',
    ]);

    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

function phase8AttachTenantMembership(string $tenantId, int $userId): void
{
    TenantUser::query()->updateOrCreate(
        [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ],
        [
            'is_active' => true,
            'is_banned' => false,
            'membership_status' => 'active',
            'membership_revoked_at' => null,
        ],
    );
}

function phase8RemoveDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $items = scandir($directory);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory.DIRECTORY_SEPARATOR.$item;

        if (is_dir($path)) {
            phase8RemoveDirectory($path);

            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}

test('make saas module command generates a tenant-safe stack in configured root', function (): void {
    $root = phase8CreateFixtureRoot();
    $this->phase8Roots[] = $root;

    phase8ConfigureGeneratorPaths($root);

    $schemaPath = phase8WriteSchema($root, 'contact-note.yml', <<<'YAML'
name: ContactNote
slug: contact-note
table: tenant_contact_notes
soft_deletes: true
database_driver: pgsql
fields:
  - name: title
    type: string
    unique: true
    audit: true
  - name: body
    type: text
    nullable: true
YAML);

    $this->artisan('make:saas-module', [
        'name' => 'ContactNote',
        '--schema' => $schemaPath,
    ])->assertExitCode(0);

    expect(is_file($root.'/app/Models/Generated/Tenant/ContactNote.php'))->toBeTrue();
    expect(is_file($root.'/database/factories/Generated/Tenant/ContactNoteFactory.php'))->toBeTrue();
    expect(is_file($root.'/app/Http/Controllers/Generated/Tenant/ContactNote/ContactNoteController.php'))->toBeTrue();
    expect(is_file($root.'/routes/generated/tenant/contact-note.php'))->toBeTrue();

    /** @var array<string, mixed> $modulesConfig */
    $modulesConfig = require $root.'/config/phase8_modules.php';

    expect($modulesConfig['modules'][0]['slug'] ?? null)->toBe('contact-note');
    expect($modulesConfig['modules'][0]['ability_prefix'] ?? null)->toBe('tenant.contact-note');
    expect($modulesConfig['business_models'] ?? [])->toContain('App\\Models\\Generated\\Tenant\\ContactNote');

    $enDictionary = json_decode((string) file_get_contents($root.'/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($enDictionary['core.nav.generated.contact-note'] ?? null)->toBe('Contact Note');
});

test('schema parser rejects custom yaml tags without executing payloads', function (): void {
    $root = phase8CreateFixtureRoot();
    $this->phase8Roots[] = $root;

    $schemaPath = phase8WriteSchema($root, 'malicious.yml', <<<'YAML'
name: TaggedPayload
fields:
  - name: title
    type: string
payload: !php/object "O:8:\"stdClass\":0:{}"
YAML);

    expect(fn () => app(ModuleSchemaParser::class)->parse('TaggedPayload', $schemaPath))
        ->toThrow(InvalidArgumentException::class, 'custom YAML tags');
});

test('generator fails fast on unique indexes without tenant_id and keeps destination immutable', function (): void {
    $root = phase8CreateFixtureRoot();
    $this->phase8Roots[] = $root;

    phase8ConfigureGeneratorPaths($root);

    $configBefore = (string) file_get_contents($root.'/config/phase8_modules.php');
    $enBefore = (string) file_get_contents($root.'/lang/en.json');

    $schemaPath = phase8WriteSchema($root, 'invalid-unique.yml', <<<'YAML'
name: InvalidUnique
slug: invalid-unique
table: tenant_invalid_uniques
database_driver: pgsql
fields:
  - name: title
    type: string
indexes:
  - type: unique
    columns: [title]
YAML);

    expect(fn () => app(ModuleGenerationService::class)->generate('InvalidUnique', $schemaPath))
        ->toThrow(InvalidArgumentException::class, 'must start with tenant_id');

    expect((string) file_get_contents($root.'/config/phase8_modules.php'))->toBe($configBefore);
    expect((string) file_get_contents($root.'/lang/en.json'))->toBe($enBefore);
    expect(is_dir($root.'/app/Models/Generated/Tenant'))->toBeFalse();
});

test('soft deletes with unique indexes reject non-postgresql drivers', function (): void {
    $root = phase8CreateFixtureRoot();
    $this->phase8Roots[] = $root;

    phase8ConfigureGeneratorPaths($root);

    $schemaPath = phase8WriteSchema($root, 'mysql-soft-delete.yml', <<<'YAML'
name: MysqlUniqueSoftDelete
slug: mysql-unique-soft-delete
table: tenant_mysql_unique_soft_deletes
soft_deletes: true
database_driver: mysql
fields:
  - name: code
    type: string
    unique: true
YAML);

    expect(fn () => app(ModuleGenerationService::class)->generate('MysqlUniqueSoftDelete', $schemaPath))
        ->toThrow(InvalidArgumentException::class, 'only supported for PostgreSQL');

    expect(is_dir($root.'/app/Models/Generated/Tenant'))->toBeFalse();
});

test('atomic replacement rollback restores original config and dictionaries after failpoint', function (): void {
    $root = phase8CreateFixtureRoot();
    $this->phase8Roots[] = $root;

    phase8ConfigureGeneratorPaths($root, [
        'phase8.generator.test_fail_after_replace' => true,
    ]);

    $configBefore = (string) file_get_contents($root.'/config/phase8_modules.php');
    $enBefore = (string) file_get_contents($root.'/lang/en.json');
    $esBefore = (string) file_get_contents($root.'/lang/es.json');

    $schemaPath = phase8WriteSchema($root, 'rollback.yml', <<<'YAML'
name: RollbackEntity
slug: rollback-entity
table: tenant_rollback_entities
database_driver: pgsql
fields:
  - name: title
    type: string
YAML);

    expect(fn () => app(ModuleGenerationService::class)->generate('RollbackEntity', $schemaPath))
        ->toThrow(RuntimeException::class, 'simulated failure after atomic replacements');

    expect((string) file_get_contents($root.'/config/phase8_modules.php'))->toBe($configBefore);
    expect((string) file_get_contents($root.'/lang/en.json'))->toBe($enBefore);
    expect((string) file_get_contents($root.'/lang/es.json'))->toBe($esBefore);
    expect(is_dir($root.'/app/Models/Generated/Tenant'))->toBeFalse();
});

test('generator lock times out when another process holds the advisory lock', function (): void {
    $root = phase8CreateFixtureRoot();
    $this->phase8Roots[] = $root;

    phase8ConfigureGeneratorPaths($root, [
        'phase8.generator.lock_timeout_seconds' => 1,
    ]);

    $lockFile = (string) config('phase8.generator.lock_file');

    if (! is_dir(dirname($lockFile))) {
        mkdir(dirname($lockFile), 0755, true);
    }

    $handle = fopen($lockFile, 'c+');

    expect(is_resource($handle))->toBeTrue();
    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();

    try {
        expect(fn () => app(GeneratorLock::class)->execute(static fn () => 'ok'))
            ->toThrow(RuntimeException::class, 'Timeout waiting for generator lock');
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

test('tenant resource binding returns 404 for cross-tenant ids before authorization middleware', function (): void {
    $tenantA = phase8CreateTenant('alpha.localhost');
    $tenantB = phase8CreateTenant('beta.localhost');

    $tenantAUser = User::factory()->create(['email_verified_at' => now()]);
    $tenantBUser = User::factory()->create(['email_verified_at' => now()]);

    phase8AttachTenantMembership((string) $tenantA->id, $tenantAUser->id);
    phase8AttachTenantMembership((string) $tenantB->id, $tenantBUser->id);

    $resource = SampleEntity::query()->create([
        'tenant_id' => (string) $tenantA->id,
        'title' => 'Tenant A only',
        'body' => 'Sensitive',
    ]);

    $this->actingAs($tenantAUser, 'web')
        ->get('http://alpha.localhost/tenant/modules/sample-entities/'.$resource->getKey())
        ->assertStatus(403);

    $this->actingAs($tenantBUser, 'web')
        ->get('http://beta.localhost/tenant/modules/sample-entities/'.$resource->getKey())
        ->assertNotFound();
});

test('generated factory template includes explicit forTenant fallback state', function (): void {
    $root = phase8CreateFixtureRoot();
    $this->phase8Roots[] = $root;

    phase8ConfigureGeneratorPaths($root);

    $schemaPath = phase8WriteSchema($root, 'factory-check.yml', <<<'YAML'
name: FactoryCheck
slug: factory-check
table: tenant_factory_checks
database_driver: pgsql
fields:
  - name: title
    type: string
YAML);

    app(ModuleGenerationService::class)->generate('FactoryCheck', $schemaPath);

    $factoryContents = (string) file_get_contents($root.'/database/factories/Generated/Tenant/FactoryCheckFactory.php');

    expect($factoryContents)->toContain('private ?string $explicitTenantId = null;');
    expect($factoryContents)->toContain('$tenantId = $this->explicitTenantId ?? tenant()?->getTenantKey();');
    expect($factoryContents)->toContain('$factory = clone $this;');
    expect($factoryContents)->toContain('$factory->explicitTenantId = $tenantId;');
});
