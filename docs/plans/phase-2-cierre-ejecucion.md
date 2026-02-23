---
description: Cierre técnico de la Fase 2 (UI Base & i18n)
---

# Fase 2 — Cierre de Ejecución (UI Base & i18n)

Este documento consolida el cierre técnico de la Fase 2, incluyendo implementación, validación y certificación operativa.

## 1) Objetivo de la fase (cumplido)

Establecer una base frontend estable para central/tenant con:

- React 19 + Inertia v2 + Tailwind v4,
- arquitectura i18n sin FOUC (core dictionary obligatorio + fail-fast),
- diccionarios por página mediante Deferred Props agrupadas,
- contratos automáticos de budget (payload/shared props y bundle JS),
- certificación E2E multihost para aislamiento tenant/central.

## 2) Implementación realizada

### 2.1 Bootstrap i18n (CSR/SSR) y fail-fast

- Se inicializa i18next antes de montar React en cliente.
- Se inicializa i18next en SSR por request.
- Si `coreDictionary` falta o es inválido, la app no monta y renderiza pantalla de error explícita.

Archivos clave:

- `resources/js/app.tsx`
- `resources/js/ssr.tsx`
- `resources/js/i18n/index.ts`
- `resources/js/i18n/bootstrap-fail-fast.tsx`

### 2.2 Deferred i18n por página (group: `i18n`)

- `pageDictionary` se comparte como Deferred Prop agrupada.
- Se creó bridge de hidratación para mezclar diccionarios por navegación y sincronizar locale runtime.
- Se integró el bridge en layouts persistentes y welcome.

Archivos clave:

- `app/Http/Middleware/HandleInertiaRequests.php`
- `resources/js/i18n/page-dictionary-bridge.tsx`
- `resources/js/layouts/app-layout.tsx`
- `resources/js/layouts/auth-layout.tsx`
- `resources/js/pages/welcome.tsx`

### 2.3 Source of truth i18n en Laravel

- Se consolidaron traducciones JSON en `lang/*.json`.

Archivos clave:

- `lang/en.json`
- `lang/es.json`

### 2.4 Rutas/páginas canónicas tenant para contratos

- Se incorporaron páginas tenant necesarias para contratos de payload y E2E:
  - `/tenant/dashboard`
  - `/tenant/settings`

Archivos clave:

- `resources/js/pages/tenant/dashboard.tsx`
- `resources/js/pages/tenant/settings.tsx`

### 2.5 Contratos backend (Pest) endurecidos

- `InertiaPayloadBudgetTest`:
  - request Inertia real (`X-Inertia: true`),
  - validación de protocolo,
  - `coreDictionary` obligatorio,
  - budget <= 15KB,
  - keys prohibidas.
- `I18nLocaleCookieContractTest`:
  - cookie host-only (`sin Domain=`),
  - `Path=/`, `SameSite=Lax`, `Secure` según esquema,
  - rechazo `lang` inválido (422) sin setear cookie.

Archivos clave:

- `tests/Feature/InertiaPayloadBudgetTest.php`
- `tests/Feature/I18nLocaleCookieContractTest.php`

### 2.6 E2E Playwright multihost certificable

- Projects separados `central` y `tenant` con baseURL explícita.
- webServer determinista con DB aislada y seed tenant de pruebas.
- Validación de:
  - persistencia de estado de layout (`sidebar_state`) tras navegación + reload,
  - aislamiento de cookie `locale` entre hosts central/tenant.

Archivos clave:

- `playwright.config.ts`
- `database/seeders/DatabaseSeeder.php`
- `tests/e2e/layout-persistence.spec.ts`
- `tests/e2e/i18n-cookie-isolation.spec.ts`

## 3) Certificación ejecutada

### 3.1 Contratos i18n/payload (backend)

```bash
php artisan test tests/Feature/InertiaPayloadBudgetTest.php tests/Feature/I18nLocaleCookieContractTest.php
```

Resultado: **PASS** (3 tests, 54 assertions).

### 3.2 Guardrails CI de Fase 2

```bash
node scripts/ci/00_guardrails.mjs
node scripts/ci/10_check_react_tree.mjs
node scripts/ci/20_check_shadcn_components_json.mjs
VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs
```

Resultado: **PASS**.

### 3.3 E2E multihost

```bash
CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0
```

Resultado: **PASS** (4 tests):

- central: `i18n-cookie-isolation.spec.ts`
- central: `layout-persistence.spec.ts`
- tenant: `i18n-cookie-isolation.spec.ts`
- tenant: `layout-persistence.spec.ts`

## 4) Notas operativas

- Para evitar drift local y asegurar entorno limpio, la certificación E2E se ejecuta con `CI=1`.
- El budget JS se evalúa sobre el entry de app con `VITE_ENTRY_KEY=resources/js/app.tsx`.

## 5) Estado de cierre de fase

**Fase 2 cerrada y certificada**.

La plataforma queda operativa para avanzar con Fase 3, manteniendo guardrails de i18n, payload, performance y aislamiento central/tenant.
