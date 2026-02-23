---
description: Cierre técnico de la Fase 1 (Fundación & Multi-Tenancy)
---

# Fase 1 — Cierre de Ejecución (Fundación & Multi-Tenancy)

Este documento consolida lo implementado en la Fase 1, el estado final validado y las diferencias operativas entre entorno local y producción.

## 1) Objetivo de la fase (cumplido)

Establecer una base multi-tenant segura en Laravel 12 con:

- aislamiento por dominio (central vs tenant),
- contexto tenant fail-closed en modelos de negocio,
- RBAC team-scoped (`team_id = tenant_id`) con Spatie,
- soporte híbrido (single-DB por defecto + columna física `db_connection`),
- pruebas de contrato para evitar regresiones de aislamiento.

## 2) Implementación realizada

### 2.1 Tenancy base y enrutamiento estricto

- Se registró `App\Providers\TenancyServiceProvider` en bootstrap.
- Se separaron rutas en:
  - `routes/central.php`
  - `routes/tenant.php`
- Se configuró `bootstrap/app.php` para:
  - iterar dominios centrales,
  - aislar nombres de rutas para dominios centrales adicionales,
  - priorizar middleware de Tenancy + Teams antes de `SubstituteBindings`.

Archivos clave:

- `bootstrap/app.php`
- `bootstrap/providers.php`
- `app/Providers/TenancyServiceProvider.php`
- `routes/central.php`
- `routes/tenant.php`

### 2.2 Configuración Tenancy y modelo Tenant

- Se publicó/creó `config/tenancy.php` para single-DB por defecto.
- Se creó `app/Models/Tenant.php` con `HasDomains`.
- Se confirmó `db_connection` como columna física nullable en `tenants`.

Archivos clave:

- `config/tenancy.php`
- `app/Models/Tenant.php`
- `database/migrations/2019_09_15_000010_create_tenants_table.php`
- `database/migrations/2019_09_15_000020_create_domains_table.php`

### 2.3 Aislamiento row-level fail-closed

- Se implementó `BelongsToTenantScope`.
- Se implementó `BelongsToTenant` trait para modelos de negocio.
- Se definió excepción explícita para contexto tenant ausente.
- Se incorporó `TenantNote` como modelo de referencia + factory + migración.

Archivos clave:

- `app/Scopes/BelongsToTenantScope.php`
- `app/Models/Concerns/BelongsToTenant.php`
- `app/Exceptions/MissingTenantContextException.php`
- `app/Models/TenantNote.php`
- `database/factories/TenantNoteFactory.php`
- `database/migrations/2026_02_23_000100_create_tenant_notes_table.php`
- `config/tenancy_business_models.php`

### 2.4 RBAC teams con Spatie (`tenant_id` como team key)

- Se activó teams mode en `config/permission.php`.
- Se definió `team_foreign_key = tenant_id` (string UUID).
- Se adaptó migración de permissions para team key string.
- Se añadió `HasRoles` al modelo `User`.
- Se creó middleware `SetTenantTeamContext` para setear/restaurar team en cada request.

Archivos clave:

- `config/permission.php`
- `database/migrations/2026_02_22_224945_create_permission_tables.php`
- `app/Models/User.php`
- `app/Http/Middleware/SetTenantTeamContext.php`

### 2.5 Mutaciones RBAC seguras (sin cross-team detach)

- Se crearon acciones dedicadas:
  - `AssignRolesToMember`
  - `RevokeRolesFromMember`
- Se fuerza validación de contexto tenant.
- Se evita detach global y se hace limpieza de cache/relaciones en `finally`.

Archivos clave:

- `app/Actions/Rbac/AssignRolesToMember.php`
- `app/Actions/Rbac/RevokeRolesFromMember.php`
- `app/Exceptions/TenantContextMismatchException.php`

### 2.6 Superadmin denylist + Gate::before

- Se incorporó denylist versionado.
- `Gate::before` retorna `null` para abilities denylisted (delegación a Policy).

Archivos clave:

- `config/superadmin_denylist.php`
- `app/Providers/AppServiceProvider.php`

### 2.7 Utilidades de aislamiento operacional

- `SystemContext` para bypass controlado con restauración de estado.
- `TenantQuery` para query builder tenant-safe.

Archivos clave:

- `app/Support/SystemContext.php`
- `app/Support/TenantQuery.php`

## 3) Diferencias Local vs Producción

## 3.1 Aplican en local (no necesariamente en producción)

1. **Dominios centrales locales**
   - `.env`: `CENTRAL_DOMAINS=localhost,127.0.0.1`
   - Motivo: evitar `TenantCouldNotBeIdentifiedOnDomainException` al navegar desde `127.0.0.1`.

2. **Base de datos local PostgreSQL**
   - `.env`: `DB_HOST=127.0.0.1`, `DB_PORT=5434`, `DB_DATABASE=gsaasbp_central`.

3. **Mitigación Wayfinder para dev multihost**
   - Se agregó normalización para evitar URLs absolutas (`//localhost/...`) que causan CORS cuando se navega desde `127.0.0.1`.
   - `vite.config.ts` ejecuta:
     - `CENTRAL_DOMAINS=localhost php artisan wayfinder:generate --with-form`
     - `node scripts/normalize-wayfinder-urls.mjs`

4. **Store de cache de permisos en local**
   - `.env`: `PERMISSION_CACHE_STORE=array` (práctico para tests/desarrollo).

### 3.2 Recomendado para producción

1. `CENTRAL_DOMAINS` solo con dominios reales de plataforma (sin `127.0.0.1`).
2. No depender de normalización local de Wayfinder para entornos con hostname único estable.
3. Definir `PERMISSION_CACHE_STORE` en store persistente (ej. redis dedicado) según arquitectura operativa.
4. Configurar HTTPS, cookies seguras y políticas de host estrictas según entorno final.

## 4) Validación ejecutada

- `composer dump-autoload`.
- `php artisan migrate:fresh --seed` sobre PostgreSQL local.
- suite de tests de tenancy (contratos de aislamiento/ruteo/cache/RBAC).
- verificación manual en navegador:
  - acceso central en `localhost` y `127.0.0.1`,
  - navegación a Settings sin error CORS.

## 5) Lista de pruebas de contrato agregadas en Fase 1

- `tests/Feature/Tenancy/CentralRoutesAccessibleTest.php`
- `tests/Feature/Tenancy/SingleDbModeTest.php`
- `tests/Feature/Tenancy/SubdomainResolutionTest.php`
- `tests/Feature/Tenancy/CustomDomainResolutionTest.php`
- `tests/Feature/Tenancy/TenantIsolationTest.php`
- `tests/Feature/Tenancy/EarlyIdentificationTest.php`
- `tests/Feature/Tenancy/PermissionCacheIsolationTest.php`
- `tests/Feature/Tenancy/TeamsRoleMutationDoesNotCrossTeamTest.php`
- `tests/Feature/Tenancy/SuperadminDenylistIntegrityTest.php`
- `tests/Feature/Tenancy/TenancyBusinessModelsIntegrityTest.php`

## 6) Estado de cierre de fase

**Fase 1 cerrada** para objetivos de fundación multi-tenant.

La base quedó lista para continuar con Fase 2/Fase 3 sobre una arquitectura con aislamiento comprobado y guardrails alineados a `AGENTS.md` y `app/AGENTS.md`.
