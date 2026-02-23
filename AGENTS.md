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
