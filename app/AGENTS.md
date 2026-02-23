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
- **Cookie Isolation (Tenant Boundary)**:
  - Todo estado basado en cookies (ej. `locale` / i18n, preferencias) en un contexto tenant debe ser ESTRICTAMENTE `host-only`.
  - PROHIBIDO setear el atributo `Domain=` explícitamente en las cookies de inquilinos para evitar filtraciones (cross-tenant leaks) a través de subdominios compartidos.

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

---

## 6. Operación Post-Fase 1 (Reglas de Estabilidad)
- **Orden de Middleware (No Regresión):**
  - `InitializeTenancyByDomain` y `SetTenantTeamContext` deben ejecutarse antes de `SubstituteBindings`.
  - Cualquier ajuste en `bootstrap/app.php` debe preservar esta prioridad explícita.
- **Rutas Centrales Multidominio:**
  - Si existen múltiples `central_domains`, mantener nombres canónicos en el dominio principal y prefijar aliases para dominios adicionales (evita colisiones de export en Wayfinder).
- **Wayfinder en Local (multihost):**
  - En desarrollo con `localhost` + `127.0.0.1`, evitar URLs absolutas de host en artifacts generados (`resources/js/routes`, `resources/js/actions`).
  - Mantener normalización post-generación con `scripts/normalize-wayfinder-urls.mjs`.

---

## 7. Operación Post-Fase 2 (UI Base & i18n)
- **Bootstrap i18n (Fail-Fast obligatorio):**
  - `coreDictionary` DEBE viajar en shared props base en TODA navegación Inertia.
  - Frontend (CSR/SSR) DEBE impedir mount si `coreDictionary` falta o está vacío y renderizar pantalla de error explícita.
- **Diccionario por página (Deferred obligatorio):**
  - `pageDictionary` DEBE resolverse con Deferred Props agrupado (`group: 'i18n'`).
  - La hidratación debe vivir en bridge dedicado (`I18nPageDictionaryBridge`) integrado en layouts persistentes.
- **Contrato E2E mínimo (no regresión):**
  - Persistencia observable de layout (`sidebar_state`) tras navegación + reload.
  - Aislamiento de cookie `locale` entre central/tenant (host-only, sin `Domain=`, `Path=/`, `SameSite=Lax`).
- **Comandos de certificación Fase 2:**
  - `php artisan test tests/Feature/InertiaPayloadBudgetTest.php tests/Feature/I18nLocaleCookieContractTest.php`
  - `CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0`
  - `node scripts/ci/00_guardrails.mjs`
  - `node scripts/ci/10_check_react_tree.mjs`
  - `node scripts/ci/20_check_shadcn_components_json.mjs`
  - `VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs`

---

## 8. Operación Post-Fase 3 (Auth, SSO Transaccional & Lifecycle)
- **IdP Claims (contrato fijo):**
  - `tenant_id` SIEMPRE derivado del caller S2S; nunca desde input del request.
  - DTO versionado allowlist-only para claims (`version`, `tenant_id`, `user_id`, `mfa_enabled`, `email_verified`).
  - Prohibido logging de request body en endpoints SSO/IdP.
- **SSO Backchannel/Frontchannel:**
  - Inicio SSO por POST protegido (CSRF + validaciones `Origin/Referer/Sec-Fetch-*`).
  - Backchannel: `code` opaco solo en body POST (nunca por URL).
  - Redeem binding obligatorio: `tenant_id(caller) == tenant_id(code)`.
- **JWT Hardening obligatorio:**
  - Algorithm pinning (`RS256`) y allowlist estricta de `kid` (sin I/O dinámico).
  - Rechazo explícito de `jku`, `x5u`, `jwk` y `crit` no autorizado.
  - Validar `iss`, `aud`, `typ`, `exp`, `nbf`, `iat` (skew máximo ±60s) antes de consumir token one-time.
- **One-time consume:**
  - `GETDEL`/Lua sobre conexión Redis de escritura dedicada (`sso_write`) y rol estructural `primary`.
- **Callback hardening y dominios:**
  - Aceptar únicamente paths relativos con `/` único.
  - Rechazar `//`, `\\` y dobles encodings.
  - Canonizar dominios con TR46/UTS46 y comparar en forma ASCII canonizada.
- **Clickjacking/HSTS:**
  - Auto-submit con CSP hash (`sha256-...`) + `X-Frame-Options: DENY`.
  - HSTS con `includeSubDomains; preload` solo para dominios de plataforma confiables.
- **Comandos de certificación Fase 3:**
  - `php artisan test`
  - `CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0`
  - `node scripts/ci/40_check_sso_csp_contract.mjs`
  - `node scripts/ci/50_check_sso_no_body_logs.mjs`
