# AGENTS.md — AI Agents Guardrails & Instructions

## 1. Reglas Globales (Innegociables)
- Dependencias:
  - Backend: Cambios en `composer.json`/`package.json` requieren `composer.lock`/`package-lock.json`. Prohibidos pre-releases en `main`.
  - Frontend: **Solo `npm` permitido** (Node >= 20). Estrictamente prohibidos `yarn.lock` y `pnpm-lock.yaml`. Instalar siempre sin `--force`.

- Autorización:
  - Usar Gate API (`can`, `@can`, `Gate::allows`) y Policies (model/resource based).
  - Prohibido usar API directa de Spatie (`hasRole`, `hasPermissionTo`) en app code; solo tests.
  - Prohibido `Gate::allowIf`/`denyIf` inline: usar Policies.
  - **Middleware Order**: El middleware de Tenancy y Spatie Teams DEBE ejecutarse antes de `SubstituteBindings`. En Laravel 12, se configura explícitamente en `bootstrap/app.php` usando `$middleware->priority([...])` o `$middleware->prependToPriorityList(...)`.

- Superadmin:
  - Mecanismo principal: `Gate::before` (o `Policy::before` cuando aplique).
  - Invariantes de dominio (denylist):
    - Reglas que ni el superadmin puede saltar deben definirse explícitamente en `config/superadmin_denylist.php` (versionado).
    - En `Gate::before`, si la ability está en el denylist, retornar `null` para delegar a la Policy.
    - Debe existir un test que valide la integridad de este denylist.

## 2. Convenciones de Código y Frontend
- General: PHP `strict_types=1` + TypeScript `strict`.
- Formato: Pint + Prettier/ESLint.
- **Frontend Architecture (Fase 2 Guardrails)**:
  - **React 19**: Estricto. Cero duplicados en el árbol (múltiples nodos 19.x exactos son válidos).
  - **shadcn/ui**: Prohibido editar primitivas directamente (crear wrapper interno). Registry oficial únicamente validado por schema allowlist.
  - **Performance Budgets**:
    - JS Initial Bundle <= 300KB (gzip).
    - Inertia Shared Props <= 15KB (bytes reales). No inyectar diccionarios de i18n o queries masivas globales (usar Deferred Props v2 agrupadas o endpoints).

## 3. Tenancy
- Reglas específicas: `app/AGENTS.md` + Master-Plan.

## 4. Cierre Fase 1 (Operativo)
- Documentación oficial de cierre:
  - `docs/plans/phase-1-cierre-ejecucion.md`
  - `docs/manuals/phase-1-manual-usuario.md`
- Dominios centrales:
  - Local dev: `CENTRAL_DOMAINS=localhost,127.0.0.1` para evitar errores de resolución tenant al usar servidor en `127.0.0.1`.
  - Producción: usar exclusivamente dominios reales de plataforma (no loopback IPs).
- Wayfinder (local multihost):
  - Evitar URLs absolutas con host en rutas/actions generadas.
  - Mantener la normalización post-generación (`scripts/normalize-wayfinder-urls.mjs`) para prevenir errores CORS por mezcla `localhost` vs `127.0.0.1`.

## 5. Cierre Fase 2 (Operativo)
- Documentación oficial de cierre:
  - `docs/plans/phase-2-cierre-ejecucion.md`
  - `docs/manuals/phase-2-manual-usuario.md`
- Certificación mínima requerida para cambios que toquen UI base/i18n:
  - `php artisan test tests/Feature/InertiaPayloadBudgetTest.php tests/Feature/I18nLocaleCookieContractTest.php`
  - `CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0`
  - `node scripts/ci/00_guardrails.mjs`
  - `node scripts/ci/10_check_react_tree.mjs`
  - `node scripts/ci/20_check_shadcn_components_json.mjs`
  - `VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs`

## 6. Cierre Fase 3 (Operativo)
- Documentación oficial de cierre:
  - `docs/plans/phase-3-cierre-ejecucion.md`
  - `docs/manuals/phase-3-manual-usuario.md`
- Seguridad SSO obligatoria:
  - Claims IdP solo por `user_id` + S2S caller (`tenant_id` derivado del credential, nunca del request).
  - Backchannel por POST body (`code` opaco), prohibido transportar `code` en URL.
  - JWT con algorithm pinning (`RS256`), allowlist `kid`, bloqueo `jku/x5u/jwk` y skew máximo ±60s.
  - Consumo one-time con cliente Redis de escritura dedicado (`sso_write`) forzado a `primary`.
  - Callback hardening: aceptar solo paths relativos válidos; rechazar `//`, `\\`, dobles encodings.
  - Clickjacking hardening: CSP mínima con hash `sha256-...` + `X-Frame-Options: DENY`.
  - No request-body logs en superficies SSO/IdP.
- Certificación mínima requerida para cambios en Auth/SSO lifecycle:
  - `php artisan test`
  - `CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0`
  - `node scripts/ci/40_check_sso_csp_contract.mjs`
  - `node scripts/ci/50_check_sso_no_body_logs.mjs`

## 7. Cierre Fase 4 (Operativo)
- Documentación oficial de cierre:
  - `docs/plans/phase-4-cierre-ejecucion.md`
  - `docs/manuals/phase-4-manual-usuario.md`
- Contratos funcionales obligatorios:
  - `POST /invites/{inviteToken}/accept` se opera en dominio central autenticado.
  - Superficies sensibles tenant (`invites`, `rbac`, `audit`, `billing`) protegidas por stale guard + entitlement fail-closed.
  - Mutaciones RBAC obligan step-up (`423 STEP_UP_REQUIRED` si no hay re-auth reciente).
  - Auditoría forense exige rango temporal sargable (`created_at >= :from AND created_at < :to`).
  - Billing exige firma webhook válida, idempotencia por `event_id` y alerta de divergencia por `outcome_hash`.
- Certificación mínima requerida para cambios en Settings/RBAC/Audit/Billing:
  - `php artisan test`
  - `php artisan test tests/Feature/Phase4/Phase4ContractsTest.php --stop-on-failure`
  - `npm run types`
  - `node scripts/ci/00_guardrails.mjs`
  - `node scripts/ci/10_check_react_tree.mjs`
  - `node scripts/ci/20_check_shadcn_components_json.mjs`
  - `npm run build`
  - `VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs`
  - `node scripts/ci/40_check_sso_csp_contract.mjs`
  - `node scripts/ci/50_check_sso_no_body_logs.mjs`
  - `CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0`

## 11. Cierre Fase 8 (Operativo)
- Documentación oficial de cierre:
  - `docs/plans/phase-8-cierre-ejecucion.md`
  - `docs/manuals/phase-8-manual-usuario.md`
- Contratos funcionales obligatorios del Module Generator DX:
  - El comando `php artisan make:saas-module {name} --schema=...` opera bajo lock advisory global con timeout y semántica fail-closed.
  - La generación usa staging en el mismo filesystem y aplica commit por rename atómico; ante error se ejecuta rollback determinista de archivos nuevos y reemplazos.
  - El parser de esquema rechaza YAML inseguro (`!tags`), identifiers inválidos, keywords reservadas y `unique` sin `tenant_id` como primer elemento.
  - Para `soft_deletes + unique`, solo PostgreSQL (`database_driver=pgsql`) está permitido; en motores no soportados debe fallar antes de escribir en destino.
  - Los módulos generados registran rutas tenant-scoped con binding estricto (`Route::tenantResource`) y deben retornar `404` uniforme ante IDs cross-tenant (anti-BOLA).
  - El código generado pasa lint estático (PHP AST + invariantes frontend) bloqueando anti-patrones como `DB::table`, `DB::select` y `withoutGlobalScope(s)`.
- Certificación mínima requerida para cambios en Phase 8 / DX Generator:
  - `php artisan test tests/Feature/Phase8/Phase8ContractsTest.php --stop-on-failure`
  - `php artisan test`
  - `npm run types`
  - `node scripts/ci/00_guardrails.mjs`
  - `node scripts/ci/10_check_react_tree.mjs`
  - `node scripts/ci/20_check_shadcn_components_json.mjs`
  - `npm run build`
  - `VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs`
  - `node scripts/ci/40_check_sso_csp_contract.mjs`
  - `node scripts/ci/50_check_sso_no_body_logs.mjs`
  - `CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0`

## 8. Cierre Fase 5 (Operativo)
- Documentación oficial de cierre:
  - `docs/plans/phase-5-cierre-ejecucion.md`
  - `docs/manuals/phase-5-manual-usuario.md`
- Contratos funcionales obligatorios:
  - Backoffice global opera bajo guard `platform` con cookie de sesión `__Host-` estricta (`Secure`, `HttpOnly`, `Path=/`, `SameSite`, sin `Domain`).
  - Abilities de plataforma se enmarcan en `platform.*` y denylist versionado en `config/superadmin_denylist.php`.
  - Hard delete exige step-up capability atómico, single-use y scope explícito (`platform.tenants.hard-delete`).
  - Circuit breaker de `TenantStatus` protege SSO, requests críticas y jobs; aborts se registran con telemetría low-cardinality.
  - Impersonation usa claim `act` con `act.iss` allowlist, `act` anidado prohibido y derivación forense anti-spoofing.
  - Colector OTel aplica allowlist/redaction/filter/transform en `resource/spans/metrics/logs`; analytics con anti-differencing (k-anonimato + buckets + cap + rounding + cache + rate-limit).
- Certificación mínima requerida para cambios en Superadmin/Platform Lifecycle/Telemetry:
  - `php artisan test`
  - `php artisan test tests/Feature/Phase5/Phase5ContractsTest.php --stop-on-failure`
  - `npm run types`
  - `node scripts/ci/00_guardrails.mjs`
  - `node scripts/ci/10_check_react_tree.mjs`
  - `node scripts/ci/20_check_shadcn_components_json.mjs`
  - `npm run build`
  - `VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs`
  - `node scripts/ci/40_check_sso_csp_contract.mjs`
  - `node scripts/ci/50_check_sso_no_body_logs.mjs`
  - `CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0`

## 9. Cierre Fase 6 (Operativo)
- Documentación oficial de cierre:
  - `docs/plans/phase-6-cierre-ejecucion.md`
  - `docs/manuals/phase-6-manual-usuario.md`
- Contratos funcionales obligatorios de Broadcasting:
  - El endpoint `/broadcasting/auth` requiere autorización explícita fail-closed (`403` uniforme) bloqueando canales si falla validación de Origen en allowlist o mismatch de Tenant/Membresía.
  - Realtime Circuit Breaker integrado: Si el `TenantStatus` se encuentra bloqueado (Fase 5), se denegarán accesos de broadcasting y se emitirá código `TENANT_STATUS_BLOCKED`.
  - Los canales tenant-scoped confidenciales usan `authz_epoch` validable; ante una revocación de rol, este epoch se incrementa (invalidación determinista).
  - Transmisión asíncrona robusta vía Outbox pattern: Las notificaciones utilizan un job (`ProcessTenantNotificationOutboxJob`) serializando los eventos y despachándolos de forma idempotente.
- Certificación mínima requerida para cambios en Notificaciones y Eventos Tiempo Real:
  - `php artisan test`
  - `php artisan test tests/Feature/Phase6/Phase6ContractsTest.php --stop-on-failure`
  - `npm run types`
  - `node scripts/ci/00_guardrails.mjs`
  - `node scripts/ci/10_check_react_tree.mjs`
  - `node scripts/ci/20_check_shadcn_components_json.mjs`
  - `npm run build`
  - `VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs`
  - `node scripts/ci/40_check_sso_csp_contract.mjs`
  - `node scripts/ci/50_check_sso_no_body_logs.mjs`
  - `CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0`

## 10. Cierre Fase 7 (Operativo)
- Documentación oficial de cierre:
  - `docs/plans/phase-7-cierre-ejecucion.md`
  - `docs/manuals/phase-7-manual-usuario.md`
- Contratos funcionales obligatorios de Panel Administrativo B2B:
  - Todo el backoffice B2B `/admin/*` opera estrictamente sobre el guard `platform`. Los usuarios de tenant (guard `web`) reciben redirect/403 de inmediato para evitar *guard confusion*.
  - Inyección segura de layout Inertia parametrizable: Las props y el componente detectan qué contexto cargar (`web` vs `platform`) para evitar montar un layout en el ecosistema equivocado.
  - Hard Delete exige un proceso transaccional de 4-ojos asíncrono (solicitante distinto del aprobador y del ejecutor final) más un Step-Up capability temporal verificado atómicamente por la base de datos.
  - Auditoría Forense impone rangos temporales obligatorios (*Sargable Windows*) para PostgreSQL partition pruning y exporta a disco con descargas tipo One-Time Token (tokens efímeros sin PII expuestos en la URL).
  - Impersonation transaccional aísla a `actor` vs `subject` a través del claim JWT `act`. Inyecta obligatoriamente contexto en la UI Tenant (Banner Rojo `is_impersonating`) y es revocable remotamente por el JTI en cualquier momento.
  - Telemetría global monitorea ataques iterativos aplicando *Privacy Budgets* sobre los requests OTel. Costos lógicos por ventana abortan consultas intrusivas con `429 PRIVACY_BUDGET_EXHAUSTED`.
- Certificación mínima requerida para cambios en el Panel B2B (Central Admin):
  - `php artisan test`
  - `php artisan test tests/Feature/Phase7/Phase7ContractsTest.php --stop-on-failure`
  - `npm run types`
  - `node scripts/ci/00_guardrails.mjs`
  - `node scripts/ci/10_check_react_tree.mjs`
  - `node scripts/ci/20_check_shadcn_components_json.mjs`
  - `npm run build`
  - `VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs`
  - `node scripts/ci/40_check_sso_csp_contract.mjs`
  - `node scripts/ci/50_check_sso_no_body_logs.mjs`
  - `CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0`

