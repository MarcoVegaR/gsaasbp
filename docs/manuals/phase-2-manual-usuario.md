---
description: Manual de usuario de la Fase 2 (UI Base & i18n)
---

# Manual de Usuario — Fase 2 (UI Base & i18n)

Este manual describe cómo validar localmente la UI base y la integración i18n de Fase 2 para central y tenant.

## 1) Prerrequisitos

- PHP 8.4+
- Composer
- Node 20+
- npm
- Playwright (Chromium instalado)

Instalación de browser Playwright (una sola vez):

```bash
npx playwright install chromium
```

## 2) Configuración local recomendada

En `.env`:

- `CENTRAL_DOMAINS=localhost,127.0.0.1`
- `APP_LOCALE=en`
- `APP_LOCALE_DEFAULT=en`
- `APP_SUPPORTED_LOCALES=en,es`
- `APP_LOCALE_COOKIE=locale`

## 3) Primer arranque

1. Instalar dependencias:
   - `composer install`
   - `npm ci`
2. Migrar y seed:
   - `php artisan migrate:fresh --seed`
3. Levantar entorno local:
   - `composer run dev`

## 4) Validación funcional manual (rápida)

### 4.1 Central + cambio de idioma

1. Abrir `http://localhost:8000/?lang=es`.
2. Verificar que la app responde correctamente (sin pantalla en blanco).
3. Recargar y confirmar que la preferencia se conserva por cookie host-only.

### 4.2 Tenant + aislamiento de locale

1. Abrir `http://tenant.localhost:8000/tenant/dashboard` (autenticado).
2. Cambiar idioma vía `?lang=en` o `?lang=es`.
3. Verificar que la cookie `locale` del tenant no pisa la del host central.

### 4.3 Persistencia de layout

1. En dashboard (central o tenant), colapsar sidebar.
2. Navegar a otra pantalla de settings.
3. Recargar.
4. Confirmar que el estado visual del sidebar se mantiene.

## 5) Certificación automática Fase 2

### 5.1 Contratos backend i18n/payload

```bash
php artisan test tests/Feature/InertiaPayloadBudgetTest.php tests/Feature/I18nLocaleCookieContractTest.php
```

### 5.2 Guardrails frontend

```bash
node scripts/ci/00_guardrails.mjs
node scripts/ci/10_check_react_tree.mjs
node scripts/ci/20_check_shadcn_components_json.mjs
VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs
```

### 5.3 E2E central/tenant

```bash
CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0
```

## 6) Errores comunes y resolución

### Error A: `Invalid locale` con `?lang=es`

**Causa:** `APP_SUPPORTED_LOCALES` no incluye `es`.

**Solución:**

- Ajustar `.env`:
  - `APP_SUPPORTED_LOCALES=en,es`
- Limpiar cache:
  - `php artisan optimize:clear`

### Error B: `TenantCouldNotBeIdentifiedOnDomainException` en `tenant.localhost`

**Causa:** no existe dominio tenant seed o se ejecutó sin entorno de pruebas aislado.

**Solución:**

- Ejecutar seed/migración limpia:
  - `php artisan migrate:fresh --seed`
- Para E2E usar comando de certificación con `CI=1`.

### Error C: falla Playwright por browser faltante

**Causa:** Chromium de Playwright no instalado.

**Solución:**

```bash
npx playwright install chromium
```

## 7) Alcance de Fase 2

Esta fase entrega:

- base UI persistente central/tenant,
- i18n bootstrap robusto con fail-fast,
- diccionario por página con Deferred Props agrupadas,
- contratos automáticos de presupuesto/aislamiento.

No incluye todavía SSO transaccional ni lifecycle de Fase 3.
