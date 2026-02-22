# AGENTS.md — AI Agents Guardrails & Instructions

## 1. Reglas Globales (Innegociables)
- Dependencias:
  - Cambios en `composer.json`/`package.json` requieren `composer.lock`/`package-lock.json`.
  - Prohibidos pre-releases en `main`.

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

## 2. Convenciones de Código
- PHP strict_types + TS strict.
- Pint + Prettier.

## 3. Tenancy
- Reglas específicas: `app/AGENTS.md` + Master-Plan.
