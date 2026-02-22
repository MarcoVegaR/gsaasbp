---
description: Plan de ejecución detallado para la Fase 1 (Fundación & Multi-Tenancy)
---

# Fase 1 — Fundación & Multi-Tenancy (Plan de Ejecución)

**Objetivo principal:** Establecer la base mínima segura con enrutamiento y aislamiento comprobables para el boilerplate genérico SaaS.

## 1. Setup Base y Calidad (Infraestructura DX)
- [ ] Verificar y ajustar la instalación limpia de Laravel 12.
- [ ] Configurar base de datos local para usar PostgreSQL en el puerto 5434 (arranque local inicializado mediante `composer run dev`).
- [ ] Configurar herramientas de calidad en el entorno:
  - Pint (Laravel default).
  - PHPStan / Larastan configurado a nivel estricto (`level 9` recomendado o mínimo `8`).
  - Prettier / ESLint para el frontend (React/TypeScript).
- [ ] Implementar un `Makefile` con comandos estándar (`make ci`, `make test`, `make lint`, `make format`).
- [ ] Configurar GitHub Actions (`.github/workflows/ci.yml`) que ejecute `make ci` en cada PR/push.

## 2. Configuración de Tenancy (`stancl/tenancy`)
- [ ] Instalar el paquete `stancl/tenancy` v3.
- [ ] Desactivar explícitamente el `DatabaseTenancyBootstrapper` en la configuración para asegurar el modo `single-DB` por defecto.
- [ ] Desactivar los jobs de base de datos (`CreateDatabase`, `MigrateDatabase`, `SeedDatabase`) del listener `TenantCreated` para evitar fallos de aislamiento y ejecuciones inconsistentes.
- [ ] Preparar el modelo `Tenant` personalizado (extender de Stancl):
  - Añadir en migración la columna `db_connection` (nullable) para hacer el sistema "híbrido-ready".
  - Configurar los dominios asociados al Tenant.

## 3. Enrutamiento Estricto y Middleware
- [ ] Separar la definición de rutas:
  - `routes/central.php` (Landing, login central, superadmin).
  - `routes/tenant.php` (Aplicación de negocio por inquilino).
- [ ] Configurar la carga de rutas en el bootstrapper de la aplicación (`bootstrap/app.php`):
  - **Iterar** sobre `config('tenancy.central_domains')` y envolver las rutas centrales usando `Route::domain($centralDomain)->group(...)` (para evitar conflictos de route names y asegurar aislamiento).
  - Aplicar el middleware `PreventAccessFromCentralDomains` a todas las rutas del entorno tenant.
  - En tests y CI, forzar estáticamente `tenancy.central_domains` a 1 solo dominio para mantener la estabilidad de las pruebas de enrutamiento.
- [ ] Configurar `TrustHosts` para asegurar que los dominios tenant custom sean considerados *trusted* (o usar `*.localhost` en desarrollo) y no bloqueen la identificación.

## 4. Implementación de Aislamiento (Row-Level Tenancy)
- [ ] Crear el Scope Global `BelongsToTenantScope`. Definir comportamiento exacto de "Fail-closed Parcial" (falla si no hay tenant y el host no es un dominio central).
- [ ] Crear un Trait `BelongsToTenant` para ser aplicado en todos los Modelos de Negocio.
- [ ] Implementar la clase utilitaria `SystemContext` para manejar los bypass controlados (`SystemContext::execute()` con snapshot/restauración de `team_id` y `try/finally`).
- [ ] Definir configuración obligatoria de colas: si se usa Redis como queue driver, crear una conexión de cola dedicada ("central queue") para aislar los jobs centrales del `RedisTenancyBootstrapper`.
- [ ] Crear un fixture/modelo de prueba `TenantNote` (con su migración y factory) para validar el aislamiento en los tests.

## 5. Pruebas de Contrato (Obligatorias)
- [ ] `CentralRoutesAccessibleTest`: Validar que las rutas centrales cargan iteradas por dominios y no mezclan contexto tenant.
- [ ] `SingleDbModeTest`: Verificar que los datos de múltiples tenants viven en la misma BD y no hay conexiones dinámicas indeseadas.
- [ ] `SubdomainResolutionTest` y `CustomDomainResolutionTest`: Asegurar que `stancl/tenancy` identifica correctamente al inquilino según el host.
- [ ] `TenantIsolationTest`: Validar que `TenantNote::all()` solo retorna las notas del tenant activo y que un request a host tenant sin tenant identificado lanza excepción.
- [ ] `EarlyIdentificationTest`: Validar que el contexto de tenancy está disponible antes de la resolución de dependencias en constructores (evitando fugas por DI).
- [ ] `PermissionCacheIsolationTest`: Validar que al cambiar tenant/team, `initializeCache()` y `unsetRelation()` evitan contaminación de permisos entre tenants. Validar que falla si el `PermissionRegistrar` se resuelve antes que el bootstrapper de caché tenant.
- [ ] `TeamsRoleMutationDoesNotCrossTeamTest`: Validar que un usuario con roles en Team A y B, al ser revocado en A, mantiene sus accesos intactos en B (garantizando que no se caiga en la trampa del detach global).

## Criterios de Aceptación (DoD)
- `make ci` debe pasar en verde.
- 0 errores de fuga de frontera de dominio en los tests.
- Cumplimiento estricto de los guardrails definidos en `AGENTS.md` y `app/AGENTS.md`.
