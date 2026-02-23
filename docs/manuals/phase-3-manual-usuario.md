---
description: Manual de usuario de la Fase 3 (Auth, SSO Transaccional & Lifecycle)
---

# Manual de Usuario — Fase 3 (Auth, SSO Transaccional & Lifecycle)

Este manual explica cómo operar el flujo SSO de Fase 3 en entorno local.

## 1) Prerrequisitos

- PHP 8.4+
- Composer
- Node 20+
- npm
- Base de datos migrada (`php artisan migrate`)

## 2) Variables relevantes en `.env`

Configurar/validar:

- `CENTRAL_DOMAINS=localhost,127.0.0.1`
- `SSO_MODE=backchannel` (recomendado por defecto)
- `SSO_S2S_HEADER=X-S2S-Key`
- `SSO_S2S_CLIENTS={...}` (mapa token -> `{tenant_id, caller}`)
- `SSO_JWT_*` (kid, llaves, algoritmo, skew)
- `SSO_TOKEN_STORE=array|redis`
- `SSO_REDIS_WRITE_CONNECTION=sso_write`
- `REDIS_SSO_WRITE_ROLE=primary`

## 3) Primer arranque

1. Instalar dependencias:
   - `composer install`
   - `npm ci`
2. Inicializar aplicación:
   - `php artisan key:generate`
   - `php artisan migrate:fresh --seed`
3. Levantar entorno:
   - `composer run dev`

## 4) Flujo operativo SSO (backchannel)

1. Usuario autenticado en central inicia salto:
   - `POST /sso/start`
   - payload mínimo: `tenant_domain`, `redirect_path`
2. Central responde HTML auto-submit con `code` + `state` por **POST body**.
3. Tenant consume por:
   - `POST /sso/consume`
4. Tenant revalida membresía activa (`tenant_users`) y crea sesión local.
5. Usuario es redirigido al path permitido y normalizado.

## 5) Integración IdP Claims S2S

Endpoint:

- `GET /idp/claims/{userId}`

Requisitos:

- Header S2S (`X-S2S-Key` por defecto).
- El `tenant_id` se deriva del caller S2S, no del request.
- Respuesta estricta versionada:
  - `version`
  - `tenant_id`
  - `user_id`
  - `mfa_enabled`
  - `email_verified`

## 6) Seguridad aplicada (resumen operativo)

- CSRF + validación de señales `Origin/Referer/Sec-Fetch-*` en inicio SSO.
- `code` nunca viaja por URL en modo backchannel.
- JWT con algorithm pinning, allowlist de `kid`, bloqueo `jku/x5u/jwk`.
- One-time consume con store dedicado y enforcement de Redis primary.
- CSP hash + `X-Frame-Options: DENY` en auto-submit.
- Paths de callback endurecidos y dominios canonicalizados TR46.
- Auditoría estructurada en `/sso/consume` sin logging de request body.

## 7) Certificación recomendada

```bash
php artisan test
php artisan test tests/Feature/InertiaPayloadBudgetTest.php tests/Feature/I18nLocaleCookieContractTest.php
CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0
node scripts/ci/00_guardrails.mjs
node scripts/ci/10_check_react_tree.mjs
node scripts/ci/20_check_shadcn_components_json.mjs
npm run build
VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs
node scripts/ci/40_check_sso_csp_contract.mjs
node scripts/ci/50_check_sso_no_body_logs.mjs
```

## 8) Errores comunes

### A) `403` al iniciar `/sso/start`

Causa frecuente: faltan headers de contexto (`Origin` o `Sec-Fetch-*`) o dominio tenant inválido.

### B) `403` al consumir `/sso/consume`

Causa frecuente: `code/state` expirado, no coincide tenant o membresía inactiva/baneada.

### C) Falla de consume con store Redis

Causa frecuente: `sso_write` no está marcado como `primary`.

## 9) Resultado esperado de la fase

La Fase 3 deja operativa la identidad central + SSO transaccional endurecido y certificable para continuidad de roadmap.
