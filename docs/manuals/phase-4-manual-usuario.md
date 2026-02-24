---
description: Manual de usuario de la Fase 4 (Settings, Perfil, RBAC UI, Auditoría & Billing)
---

# Manual de Usuario — Fase 4 (Settings, Perfil, RBAC UI, Auditoría & Billing)

Este manual explica cómo operar la capa de workspace tenant de Fase 4 en entorno local.

## 1) Prerrequisitos

- PHP 8.4+
- Composer
- Node 20+
- npm
- Base de datos migrada (`php artisan migrate`)
- Browsers de Playwright instalados (para certificación E2E)

## 2) Variables relevantes en `.env`

Configurar/validar:

- `CENTRAL_DOMAINS=localhost,127.0.0.1`
- `PHASE4_PROFILE_PROJECTION_REQUIRED=true`
- `PHASE4_PROFILE_PROJECTION_MAX_AGE_SECONDS=900`
- `PHASE4_RBAC_STEP_UP_TTL_SECONDS=900`
- `PHASE4_INVITES_MAX_PER_WINDOW=5`
- `PHASE4_INVITES_WINDOW_SECONDS=3600`
- `PHASE4_AUDIT_DEFAULT_WINDOW_HOURS=24`
- `BILLING_DEFAULT_PROVIDER=local`
- `BILLING_WEBHOOK_SECRET=...`

## 3) Primer arranque

1. Instalar dependencias:
   - `composer install`
   - `npm ci`
2. Inicializar app:
   - `php artisan key:generate`
   - `php artisan migrate:fresh --seed`
3. Levantar entorno:
   - `composer run dev`

## 4) Operación funcional de Fase 4

## 4.1 Workspace Settings (tenant)

Ruta principal:

- `GET /tenant/settings`

La pantalla permite:

- emitir invitaciones asíncronas (`202`),
- administrar roles RBAC por miembro,
- consultar y exportar auditoría forense,
- revisar estado de billing y disparar reconciliación.

## 4.2 Invitaciones

- Crear invite:
  - `POST /tenant/invites`
- Aceptar invite (dominio central autenticado):
  - `POST /invites/{inviteToken}/accept`

Comportamiento esperado:

- La emisión responde `202 Accepted` incluso bajo soft throttling.
- La aceptación valida identidad del usuario central y consume token de forma atómica.

## 4.3 RBAC y step-up

- Listar miembros/roles:
  - `GET /tenant/rbac/members`
- Mutar roles:
  - `POST /tenant/rbac/members/{member}/roles`

Requisitos:

- step-up reciente (`auth.password_confirmed_at` vigente),
- control anti-escalación,
- bloqueo optimista por `acl_version`,
- protección de último owner.

## 4.4 Auditoría forense

- Consultar logs:
  - `GET /tenant/audit-logs?from=...&to=...&event=...`
- Exportar logs:
  - `POST /tenant/audit-logs/export`

Reglas:

- filtros temporales sargables obligatorios,
- redacción con HMAC y `hmac_kid`,
- exportación asíncrona (`202`).

## 4.5 Billing y reconciliación

- Ver estado de billing:
  - `GET /tenant/billing`
- Reconciliar drift:
  - `POST /tenant/billing/reconcile`
- Webhook proveedor:
  - `POST /tenant/billing/webhooks/{provider}`

Reglas:

- verificación estricta de firma,
- idempotencia por `event_id`,
- detección de divergencia (`outcome_hash`),
- fail-closed por entitlement.

## 5) Certificación recomendada

```bash
php artisan test
php artisan test tests/Feature/Phase4/Phase4ContractsTest.php --stop-on-failure
npm run types
node scripts/ci/00_guardrails.mjs
node scripts/ci/10_check_react_tree.mjs
node scripts/ci/20_check_shadcn_components_json.mjs
npm run build
VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs
node scripts/ci/40_check_sso_csp_contract.mjs
node scripts/ci/50_check_sso_no_body_logs.mjs
CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0
```

## 6) Errores comunes

### A) `403 BILLING_REQUIRED`

Causa: el tenant no tiene entitlement para la feature.

### B) `423 STEP_UP_REQUIRED` en mutación RBAC

Causa: expiró la ventana de step-up.

### C) `409 PROFILE_PROJECTION_STALE`

Causa: proyección de perfil vencida para el usuario tenant.

### D) `409 INVITE_TOKEN_INVALID`

Causa: token expirado/consumido o no corresponde al usuario autenticado.

## 7) Alcance de Fase 4

Esta fase deja operativa la gestión de workspace tenant (settings, invites, RBAC, auditoría y billing).

No incluye todavía:

- canales realtime de notificaciones/events (Fase 6),
- panel central administrativo completo (Fase 7),
- generador de módulos (Fase 8).
