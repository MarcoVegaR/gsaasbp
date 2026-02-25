# GSaaSBP — SaaS B2B multi-tenant (Laravel 12 + Inertia + React 19)

Este proyecto es un **blueprint SaaS B2B multi-tenant** con foco en seguridad operativa, aislamiento por tenant y DX para generar modulos de negocio.

> Si eres nuevo en el proyecto, este README te explica de forma pedagogica **que puedes hacer**, **como entrar por rol**, y **como probar flujos reales** (incluyendo registrar, crear, editar y eliminar).

---

## 1) ¿Que tipo de SaaS es este?

Es una plataforma con 3 superficies separadas:

1. **Central (`web`)**: cuenta de usuario y operaciones generales.
2. **Tenant (`web` en dominio tenant)**: operacion del workspace (RBAC, auditoria, billing, modulos).
3. **Admin (`platform`)**: control plane global B2B (`/admin/*`) con guard dedicado.

La separacion de dominios y guards evita *guard confusion* y fugas cross-tenant.

---

## 2) ¿Que se puede hacer con este SaaS?

## 2.1 Usuario central (dominio central)

- Registrarte e iniciar sesion.
- Ver dashboard central.
- Gestionar perfil, password, apariencia y 2FA.
- Aceptar invitaciones a workspaces tenant.
- Iniciar flujos SSO.

## 2.2 Usuario tenant (dominio tenant)

- Entrar al dashboard del workspace.
- Gestionar **Workspace settings** (invites, RBAC, auditoria, billing).
- Consumir modulos dinamicos generados por Phase 8.
- Operar CRUD de modulos tenant-scoped (segun permissions/policies).

## 2.3 Admin global (dominio central `/admin/*`)

- Operar lifecycle de tenants.
- Emitir y consumir step-up capabilities.
- Forensics (consulta + export one-time token).
- Telemetria con privacy budget.
- Billing reconcile.
- Impersonation break-glass auditada y revocable.

---

## 3) Mapa rapido de acceso por contexto

| Contexto | URL base | Guard |
|---|---|---|
| Central | `http://localhost:8010` | `web` |
| Tenant | `http://tenant.localhost:8010` | `web` |
| Admin | `http://localhost:8010/admin/login` | `platform` |

> En local, manten `CENTRAL_DOMAINS=localhost,127.0.0.1` para evitar conflictos de resolucion tenant.

---

## 4) Credenciales demo (seed)

Con `php artisan migrate:fresh --seed`, quedan disponibles:

- `superadmin@example.test` / `password` (admin global)
- `approver@example.test` / `password`
- `executor@example.test` / `password`
- `owner@tenant.localhost` / `password` (owner tenant)
- `member@tenant.localhost` / `password`
- `support@acme.localhost` / `password`

### Nota importante sobre Phase 8

El seeder de demo otorga al **owner tenant** permisos CRUD para el modulo generado de ejemplo:

- `tenant.sample-entity.view`
- `tenant.sample-entity.create`
- `tenant.sample-entity.update`
- `tenant.sample-entity.delete`

Esto permite probar manualmente crear/editar/eliminar desde Chromium MCP en:

- `/tenant/modules/sample-entities`

---

## 5) Flujos guiados para probar el sistema (paso a paso)

## 5.1 Flujo A — Registro en central (registrar)

1. Ir a `http://localhost:8010/register`.
2. Completar nombre, correo y password.
3. Confirmar que redirige a `/dashboard`.

## 5.2 Flujo B — Operacion tenant + CRUD modulo (crear/editar/eliminar)

1. Ir a `http://tenant.localhost:8010/login`.
2. Ingresar con `owner@tenant.localhost` / `password`.
3. Abrir `Sample Entity` desde el sidebar.
4. Crear un registro (`Create Sample Entity`).
5. Abrir el detalle y editar (`Edit`).
6. Eliminar (`Delete`) y validar retorno a listado vacio o actualizado.

## 5.3 Flujo C — Panel admin global

1. Ir a `http://localhost:8010/admin/login`.
2. Ingresar con `superadmin@example.test` / `password`.
3. Abrir `/admin/panel`.
4. Validar secciones: tenant lifecycle, telemetry, forensics, billing, impersonation.

---

## 6) ¿Por que a veces aparece `403 This action is unauthorized`?

Ese `403` puede ser **esperado** y significa que la plataforma esta en modo *fail-closed*:

- Falta de permission/ability para la accion.
- Policy denegando acceso.
- Guard/contexto incorrecto.
- Tenant no activo o sin entitlement requerido.

En este proyecto, un modulo generado puede iniciar cerrado hasta que se otorguen permissions/policies correctas.

---

## 7) Instalacion local

## 7.1 Requisitos

- PHP `^8.2`
- Composer
- Node `>=20`
- **npm** (solo npm; no yarn/pnpm)
- SQLite o motor compatible configurado en `.env`

## 7.2 Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

## 7.3 Levantar en local

```bash
php artisan serve --host=127.0.0.1 --port=8010
```

Abre:

- `http://localhost:8010`
- `http://tenant.localhost:8010`
- `http://localhost:8010/admin/login`

---

## 8) Comandos utiles de calidad

```bash
php artisan test
php artisan test tests/Feature/Phase8/Phase8ContractsTest.php --stop-on-failure
npm run types
npm run build
```

---

## 9) Documentacion complementaria

- Manual transversal: `docs/manuals/manual-usuario-sistema-completo.md`
- Manual de module generator (Phase 8): `docs/manuals/phase-8-manual-usuario.md`
- Master plan: `docs/plans/master-plan.md`

---

## 10) Stack tecnico

- Backend: Laravel 12, Fortify, Spatie Permission (teams), Stancl Tenancy
- Frontend: Inertia v2, React 19, TypeScript strict, shadcn/ui
- Testing: Pest + Playwright
- Seguridad: step-up capabilities, denylist superadmin, contracts fail-closed, forensics one-time token
