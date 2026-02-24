---
description: Manual de usuario de la Fase 5 (Superadmin, Telemetría Global & Platform Lifecycle)
---

# Manual de Usuario — Fase 5 (Superadmin, Telemetría Global & Platform Lifecycle)

Este manual explica cómo operar la capa de administración de plataforma de Fase 5 en entorno local.

## 1) Prerrequisitos

- PHP 8.4+
- Composer
- Node 20+
- npm
- Base de datos migrada (`php artisan migrate`)
- Browser Chromium de Playwright instalado para certificación E2E (`npx playwright install chromium`)

## 2) Variables relevantes en `.env`

Configurar/validar:

- `CENTRAL_DOMAINS=localhost,127.0.0.1`
- `SUPERADMIN_EMAILS=superadmin@tu-dominio.test`
- `PHASE5_PLATFORM_SESSION_COOKIE=__Host-platform_session`
- `PHASE5_PLATFORM_SESSION_SAME_SITE=lax`
- `PHASE5_STEP_UP_TTL_SECONDS=600`
- `PHASE5_STEP_UP_ALLOWED_SCOPES=platform.tenants.hard-delete`
- `PHASE5_STEP_UP_HARD_DELETE_STRICT_IP=false`
- `PHASE5_TENANT_STATUS_CACHE_STORE=array|redis`
- `PHASE5_TENANT_STATUS_CACHE_TTL_SECONDS=15`
- `PHASE5_IMPERSONATION_ALLOWED_ISSUERS=http://localhost`
- `PHASE5_IMPERSONATION_MUTATION_ALLOWLIST=` (vacío por defecto)
- `PHASE5_ANALYTICS_RATE_LIMIT_PER_MINUTE=30`
- `PHASE5_ANALYTICS_K_ANONYMITY=10`
- `PHASE5_ANALYTICS_BUCKET_SECONDS=3600`
- `PHASE5_ANALYTICS_CONTRIBUTION_CAP_PER_TENANT=10`
- `PHASE5_ANALYTICS_ROUNDING_QUANTUM=5`

## 3) Primer arranque

1. Instalar dependencias:
   - `composer install`
   - `npm ci`
2. Inicializar app:
   - `php artisan key:generate`
   - `php artisan migrate:fresh --seed`
3. Levantar entorno:
   - `composer run dev`

## 4) Operación funcional de Fase 5

## 4.1 Portal platform admin

Ruta principal:

- `GET /admin/dashboard`

Contrato esperado:

- El guard activo debe ser `platform`.
- La sesión de plataforma usa cookie `__Host-` con `Secure`, `HttpOnly`, `Path=/`, `SameSite` y sin `Domain`.

## 4.2 Estado del tenant (circuit breaker)

- Mutar estado:
  - `POST /admin/tenants/status`

Body mínimo:

```json
{
  "tenant_id": "<tenant-id>",
  "status": "suspended",
  "reason": "investigation"
}
```

Efecto esperado:

- Superficies críticas tenant (SSO consume/start, mutaciones sensibles y jobs operativos) responden `423 TENANT_STATUS_BLOCKED` o abortan side effects.

## 4.3 Step-up capability para acciones destructivas

- Emitir capability:
  - `POST /admin/step-up/capabilities`
- Usar capability en hard delete:
  - `DELETE /admin/tenants/{tenantId}` con `capability_id` (body o header `X-Platform-Capability-Id`).

Contrato esperado:

- Scope explícito (`platform.tenants.hard-delete`).
- Consumo single-use atómico: un segundo consumo del mismo capability debe fallar.

## 4.4 Impersonation seguro (RFC 8693)

Contrato esperado cuando se usa `act` en assertion SSO:

- `act.iss` debe estar en allowlist.
- `act` anidado está prohibido.
- Campos forenses (`actor_platform_user_id`, `subject_user_id`, `impersonation_ticket_id`) se derivan de claims confiables y no del payload.
- Mutaciones en contexto impersonado quedan bloqueadas por defecto salvo allowlist.

## 4.5 Telemetría global

Endpoints admin:

- Preview collector saneado:
  - `POST /admin/telemetry/collector-preview`
- Analytics agregadas:
  - `GET /admin/telemetry/analytics`

Contrato esperado:

- Allowlist + redacción para `resource`, `spans`, `metrics`, `logs`.
- Analytics con k-anonymity, time buckets, contribution caps, supresión estable, rounding/quantization, rate limit y cache.

## 5) Certificación recomendada

```bash
php artisan test
php artisan test tests/Feature/Phase5/Phase5ContractsTest.php --stop-on-failure
npm run types
node scripts/ci/00_guardrails.mjs
node scripts/ci/10_check_react_tree.mjs
node scripts/ci/20_check_shadcn_components_json.mjs
npm run build
VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs
node scripts/ci/40_check_sso_csp_contract.mjs
node scripts/ci/50_check_sso_no_body_logs.mjs
npx playwright install chromium
CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0
```

## 6) Errores comunes

### A) `423 TENANT_STATUS_BLOCKED`

Causa: tenant en estado suspendido/hard_deleted y circuito breaker activo.

### B) `423 STEP_UP_REQUIRED`

Causa: capability ausente, expirada o ya consumida para el scope requerido.

### C) `403` en operaciones impersonadas

Causa: request de mutación no permitido bajo impersonation o claim `act` inválido.

### D) Analytics vacías/suprimidas

Causa: umbral de k-anonymity no alcanzado o bucket suprimido por controles anti-differencing.

## 7) Resultado esperado de la fase

La Fase 5 deja operativo el backoffice de plataforma con guard dedicado, circuit breaker de tenant, step-up de alto riesgo, impersonation forense y telemetría agregada segura.
