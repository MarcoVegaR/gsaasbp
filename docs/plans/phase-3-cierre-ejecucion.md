---
description: Cierre técnico de la Fase 3 (Auth, SSO Transaccional & Lifecycle)
---

# Fase 3 — Cierre de Ejecución (Auth, SSO Transaccional & Lifecycle)

Este documento consolida el cierre técnico de Fase 3, incluyendo implementación y certificación de seguridad SSO.

## 1) Objetivo de la fase (cumplido)

Implementar IdP central, SSO transaccional con modo backchannel/frontchannel, validación JWT zero-trust, endurecimiento de callbacks y auditoría operativa con contratos de seguridad verificables.

## 2) Implementación realizada

### 2.1 Identidad central y claims anti-BOLA/BOPLA

- `User` se mantiene como fuente de verdad en central DB.
- Servicio IdP de claims por `user_id` + credencial S2S (sin listados globales).
- DTO estricto versionado (`UserClaimsData`) sin `toArray()`.
- Cuotas anti-scraping por minuto/día/semana + alarmas de hit/miss.

Archivos clave:

- `app/Http/Controllers/Sso/Central/ClaimsController.php`
- `app/Support/Sso/SsoClaimsService.php`
- `app/Support/Sso/SsoClaimsQuotaGuard.php`
- `app/Support/Sso/UserClaimsData.php`
- `app/Http/Middleware/ResolveS2sCaller.php`

### 2.2 Shadow table `tenant_users` + revalidación activa

- Migración y modelo `tenant_users` (cero PII funcional de perfil).
- Revalidación de membresía activa y no baneo en inicio/consumo SSO.
- Actualización de `last_sso_at` en consume exitoso.

Archivos clave:

- `database/migrations/2026_02_24_000000_create_tenant_users_table.php`
- `app/Models/TenantUser.php`
- `database/factories/TenantUserFactory.php`
- `app/Support/Sso/SsoMembershipService.php`

### 2.3 SSO transaccional (backchannel + frontchannel)

- Inicio SSO vía `POST /sso/start` con validaciones anti-login-CSRF.
- Backchannel: `code` opaco por body POST auto-submit.
- Frontchannel: assertion JWT firmada y consumible una sola vez.
- Binding anti-mix-up en `POST /sso/redeem` por caller S2S + tenant.

Archivos clave:

- `app/Http/Controllers/Sso/Central/StartSsoController.php`
- `app/Http/Controllers/Sso/Central/RedeemBackchannelCodeController.php`
- `app/Http/Controllers/Sso/Tenant/ConsumeSsoController.php`
- `app/Support/Sso/SsoCodeStore.php`
- `app/Support/Sso/SsoOneTimeTokenStore.php`
- `routes/central.php`
- `routes/tenant.php`

### 2.4 Hardening JWT/JWKS y consumo atómico

- Algorithm pinning (`RS256`), validación estricta de `kid` allowlist.
- Bloqueo de cabeceras peligrosas (`jku`, `x5u`, `jwk`) y `crit` no autorizado.
- Validación `iss`, `aud`, `typ`, `iat`/`nbf`/`exp` con skew ±60s.
- Consumo one-time con `GETDEL`/Lua sobre conexión Redis de escritura con rol `primary` obligatorio.

Archivos clave:

- `app/Support/Sso/SsoJwtAssertionService.php`
- `app/Support/Sso/SsoOneTimeTokenStore.php`
- `config/sso.php`
- `config/database.php`

### 2.5 Normalización extrema, TR46 y clickjacking/CSP

- Canonización de dominios (TR46/UTS46) en helper + modelo `Domain`.
- Hardening de redirect paths (`//`, `\`, doble encoding).
- Auto-submit con CSP hash (`sha256-...`), `X-Frame-Options: DENY`, anti-cache y no-referrer.
- Middleware HSTS con preload/includeSubDomains solo para dominios de plataforma confiables.

Archivos clave:

- `app/Support/Sso/DomainCanonicalizer.php`
- `app/Models/Domain.php`
- `app/Support/Sso/RedirectPathGuard.php`
- `app/Support/Sso/SsoAutoSubmitPage.php`
- `app/Http/Middleware/ApplyPlatformHsts.php`
- `config/tenancy.php`

### 2.6 Auditoría y pipeline checks

- Auditoría estructurada de `/sso/consume` con truncado/sanitización de UA.
- Checks CI agregados para contrato CSP hash y ausencia de request-body logs SSO.

Archivos clave:

- `app/Support/Sso/SsoAuditLogger.php`
- `scripts/ci/40_check_sso_csp_contract.mjs`
- `scripts/ci/50_check_sso_no_body_logs.mjs`

## 3) Certificación ejecutada

### 3.1 Tests backend (Pest)

```bash
php artisan test
```

Resultado: **PASS** (80 tests, 298 assertions).

Incluye contratos de Fase 3 en `tests/Feature/Sso/*`:

- Claims DTO estricto + anti-scraping.
- Redeem binding anti-mix-up.
- Backchannel POST-only consume.
- JWKS/kid abuse, algorithm pinning, clock skew.
- Redis primary enforcement.
- Parser hardening y TR46 canonicalization.
- CSP hash contract y validación de inicio SSO.

### 3.2 Certificación Fase 2 (no-regresión)

```bash
php artisan test tests/Feature/InertiaPayloadBudgetTest.php tests/Feature/I18nLocaleCookieContractTest.php
CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0
```

Resultado: **PASS**.

### 3.3 Guardrails CI + SSO

```bash
node scripts/ci/00_guardrails.mjs
node scripts/ci/10_check_react_tree.mjs
node scripts/ci/20_check_shadcn_components_json.mjs
npm run build
VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs
node scripts/ci/40_check_sso_csp_contract.mjs
node scripts/ci/50_check_sso_no_body_logs.mjs
```

Resultado: **PASS**.

## 4) Estado de cierre de fase

**Fase 3 cerrada y certificada en verde**.

La plataforma queda lista para iniciar Fase 4 con base de identidad, SSO y hardening operativo validados.
