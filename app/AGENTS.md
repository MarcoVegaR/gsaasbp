# app/AGENTS.md — Multi-Tenant Guardrails & Architecture (Hard Rules)

## 0. Definitions
- **Tenant Context**: tenant_id activo en el request/job actual.
- **Business Models**: modelos con datos pertenecientes a tenants. Su definición estricta debe mantenerse en `config/tenancy_business_models.php` y validarse mediante tests para evitar omisiones.
- **System Context**: ejecución con bypass controlado para tareas administrativas/forenses.

---

## 1. Scope y Seguridad (Row-Level Tenancy)
- **BelongsToTenant (Mandatory)**:
  - Todo Business Model DEBE:
    - tener `tenant_id` (UUID) NOT NULL
    - aplicar Global Scope `BelongsToTenant` por defecto
- **Comportamiento Fail-Closed Parcial**:
  - Si no hay tenant activo (`tenant() === null`):
    - Si el request proviene de un host definido en `config('tenancy.central_domains')`: el scope no aplica (permitido).
    - Si el request proviene de cualquier otro host (tenant no identificado): **lanzar excepción inmediata**.
- **Enrutamiento Estricto y Early Identification**:
  - Central routes se aíslan iterando sobre `config('tenancy.central_domains')` y envolviendo en `Route::domain($centralDomain)`. 
  - Tenant routes DEBEN incluir el middleware `PreventAccessFromCentralDomains`.
  - **Early Identification**: Prohibido acceder a Business Models en los `__construct()` de los controladores o en el `boot()` de Service Providers. El contexto de Tenancy debe resolverse antes de que se inyecten dependencias que lean datos tenant.
- **Bypass de Scope (Controlled)**:
  - PROHIBIDO `withoutGlobalScopes()` y `DB::table()` en controladores/commands “de negocio”.
  - ÚNICA excepción de negocio: si se requiere Query Builder complejo, usar wrapper obligatorio `TenantQuery::table('x')->forTenant($tenantId)`. 
    - **Invariante de Exfiltración**: En contexto tenant, `$tenantId` debe ser ESTRICTAMENTE IGUAL al `tenant()->id` actual. Si no coinciden, lanzar excepción.
  - Excepción Administrativa: `SystemContext::execute()` con:
    - permiso explícito de superadmin
    - `try/finally` que restaure tenant + cache + registrar
    - logging/auditoría del bypass

---

## 2. RBAC Mutations (Spatie Teams)
- **Invariante de Modelo**:
  - El Team ID de Spatie ES el Tenant ID del sistema (deben ser exactamente el mismo UUID).
- **Aislamiento Estricto**:
  - PROHIBIDO usar `syncRoles()`, `syncPermissions()`, `roles()->detach()` para remover/replace roles en escenarios Teams:
    - puede impactar roles de otros teams (cross-team detaches).
- **Acción Obligatoria**:
  - Toda mutación roles/permisos pasa por acción dedicada (`AssignRolesToMember` / `RevokeRolesFromMember`):
    - filtra pivotes por `team_id = tenant_id` 
    - nunca ejecuta “detach global”
- **Restauración Obligatoria de Team ID (Snapshotting)**:
  - Cada request/job debe setear team activo (middleware) antes de cualquier check/mutación RBAC.
  - Al cambiar de team vía `setPermissionsTeamId()`, es OBLIGATORIO:
    1. Guardar snapshot del team anterior (`getPermissionsTeamId()`).
    2. Ejecutar inmediatamente `unsetRelation('roles')` y `unsetRelation('permissions')` en el usuario (y modelos relevantes) para evitar contaminación de caché en memoria.
    3. Restaurar el snapshot en un bloque `finally`.

---

## 3. Permission Cache Lifecycle (Tenant Switching)
- **Mecanismo Exclusivo de Aislamiento**:
  - Prohibido modificar `config('cache.prefix')` dinámicamente durante el request/job (genera fugas en Octane/Workers).
  - Usar un bootstrapper dedicado de `stancl/tenancy` (ej. `RedisTenancyBootstrapper` o un custom cache resolver por tenant) para el store de permisos.
- **Orden de Resolución (Cache vs Bootstrapper)**:
  - Después de inicializar tenancy (middleware), se DEBE ejecutar `app(PermissionRegistrar::class)->initializeCache()` una vez por ciclo tenant.
  - El `PermissionRegistrar` nunca debe resolverse ni usarse antes de que el bootstrapper de tenant haya ajustado el entorno de caché.
- Restaurar estado neutral en `finally`.

---

## 4. Estado en Memoria (Octane / Workers / Queues)
- Prohibido `static` / singletons stateful para `tenant_id`.
- Todo “Tenant Context” debe ser:
  - scoped al request/job
  - reseteado en `finally` 
- Evitar memoización tenant-aware.
- Si se usa `RedisTenancyBootstrapper` con un queue driver de Redis, los jobs centrales DEBEN usar una conexión de cola/worker separada estrictamente de la cola tenant para evitar estado global residual ("leftover global state").

---

## 5. Auditoría (Forensics)
- Consultas sobre `activity_log` solo vía repositorio forense.
- En despliegues de PostgreSQL (ver Master-Plan), deben incluir rango explícito `created_at` para soporte de particionado/pruning.
