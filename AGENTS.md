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
