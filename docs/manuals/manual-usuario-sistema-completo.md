---
description: Manual de uso del sistema completo (Fases 1 a 8)
---

# Manual de Usuario del Sistema Completo (Fases 1 a 8)

Este manual esta dirigido a las personas que usan el sistema en operacion diaria:

- usuarios de plataforma (dominio central),
- usuarios de tenant (workspace),
- administradores globales (backoffice B2B),
- equipos de soporte/integracion.

El objetivo es explicar **que rutas existen**, **como acceder**, **que se puede hacer** y **que no** en cada area.

## 1) Mapa de acceso por contexto

| Contexto | Dominio/URL base | Tipo de usuario |
|---|---|---|
| Central | `http://localhost:8000` o `http://127.0.0.1:8000` | Usuario web normal (guard `web`) |
| Tenant | `http://tenant.localhost:8000` (ejemplo) | Usuario miembro de tenant (guard `web`) |
| Admin B2B | `http://localhost:8000/admin/login` | Administrador global (guard `platform`) |

Importante:

- Las rutas centrales se montan por dominio central configurado.
- Las rutas tenant corren por dominio tenant y se bloquean en dominios centrales.

## 2) Usuarios demo para pruebas manuales

Si ejecutaste `php artisan migrate:fresh --seed`, puedes usar estas cuentas de ejemplo (password comun: `password`):

- `superadmin@example.test` (admin global)
- `approver@example.test` (aprobador)
- `executor@example.test` (ejecutor)
- `owner@tenant.localhost` (owner tenant)
- `member@tenant.localhost` (member tenant)
- `support@acme.localhost` (support tenant secundario)

## 3) Navegacion visible en menu (lo que ve el usuario)

## 3.1 Menu lateral en dominio central (`/` y `/dashboard`)

| Opcion | Ruta | Que permite |
|---|---|---|
| Dashboard | `GET /dashboard` | Ver dashboard central del usuario autenticado. |

## 3.2 Menu lateral en tenant (`/tenant/*`)

| Opcion | Ruta | Que permite |
|---|---|---|
| Tenant dashboard | `GET /tenant/dashboard` | Ver panel principal del workspace tenant. |
| Workspace settings | `GET /tenant/settings` | Operar invites, RBAC, auditoria y billing del tenant. |
| Modulos dinamicos | `GET /tenant/modules/{modulo}` | Entrar a modulos generados (Phase 8), por ejemplo `sample-entities`. |

## 3.3 Menu lateral en admin (`/admin/*`)

| Opcion | Ruta | Que permite |
|---|---|---|
| Central admin panel | `GET /admin/panel` | Operar lifecycle de tenants, forensics, telemetry, billing e impersonation. |

## 3.4 Menu de usuario (dropdown superior)

| Opcion | Ruta | Que permite |
|---|---|---|
| Settings (solo guard `web`) | `GET /settings/profile` | Gestion de perfil/cuenta del usuario normal. |
| Log out (`web`) | `POST /logout` | Cerrar sesion usuario web. |
| Log out (`platform`) | `POST /admin/logout` | Cerrar sesion admin global. |

---

## 4) Catalogo de rutas por area funcional

## 4.1 Area central (usuario web)

| Metodo + Ruta | Acceso | Para que sirve |
|---|---|---|
| `GET /` | Publico | Landing de la app central. |
| `GET /dashboard` | Auth + verified | Dashboard de usuario central. |
| `GET /settings/profile` | Auth | Ver/editar perfil. |
| `PATCH /settings/profile` | Auth | Actualizar perfil. |
| `DELETE /settings/profile` | Auth + verified | Eliminar cuenta/perfil. |
| `GET /settings/password` | Auth + verified | Pantalla para cambio de password. |
| `PUT /settings/password` | Auth + verified | Actualizar password (con throttle). |
| `GET /settings/appearance` | Auth + verified | Configurar apariencia. |
| `GET /settings/two-factor` | Auth + verified | Gestion de 2FA. |
| `POST /invites/{inviteToken}/accept` | Auth + verified | Aceptar invitacion de workspace tenant (Fase 4). |
| `POST /sso/start` | Auth + verified | Iniciar salto SSO hacia tenant (Fase 3). |

### Lo que NO hace esta area

- No da acceso a operaciones admin globales (`/admin/*`).
- No permite mutaciones RBAC/billing tenant fuera del workspace tenant.

## 4.2 Area tenant (workspace)

| Metodo + Ruta | Acceso | Para que sirve |
|---|---|---|
| `GET /dashboard` (tenant domain) | Auth + verified | Ruta de compatibilidad: redirige a `/tenant/dashboard`. |
| `GET /tenant/dashboard` | Auth + verified | Home funcional del tenant. |
| `GET /tenant/settings` | Auth + verified | Pantalla consolidada de gestion tenant. |
| `POST /tenant/invites` | Auth + verified + entitlement | Emitir invitaciones async del workspace. |
| `GET /tenant/rbac/members` | Auth + verified + entitlement | Listar miembros, roles y permisos delegables. |
| `POST /tenant/rbac/members/{member}/roles` | Auth + verified + step-up + entitlement | Actualizar roles de un miembro. |
| `GET /tenant/audit-logs` | Auth + verified + entitlement | Consultar auditoria forense con ventana temporal. |
| `POST /tenant/audit-logs/export` | Auth + verified + entitlement | Encolar export de auditoria. |
| `GET /tenant/billing` | Auth + verified + entitlement | Ver estado de suscripcion y entitlements. |
| `POST /tenant/billing/reconcile` | Auth + verified + entitlement | Encolar reconciliacion de billing. |
| `GET /tenant/modules/{slug}` | Auth + verified + tenant active | Operar modulo generado en Phase 8. |

### Lo que NO hace esta area

- No permite administrar otros tenants.
- No permite ejecutar hard delete global.
- No permite saltar controles step-up/entitlements.

## 4.3 Area admin global (Backoffice B2B)

| Metodo + Ruta | Acceso | Para que sirve |
|---|---|---|
| `GET /admin/login` | Guest `platform` | Formulario de login admin global. |
| `POST /admin/login` | Guest `platform` + throttle | Iniciar sesion admin global. |
| `POST /admin/logout` | Auth `platform` | Cerrar sesion admin global. |
| `GET /admin/panel` | Auth `platform` + middleware de seguridad | UI principal del control plane. |
| `GET /admin/dashboard` | Auth `platform` | Endpoint tecnico de verificacion de guard/cookie. |

### Operaciones dentro del panel admin (APIs usadas por la UI)

| Seccion panel | Metodo + Ruta | Uso |
|---|---|---|
| Step-up | `POST /admin/step-up/capabilities` | Emitir capability temporal por scope sensible. |
| Directory | `GET /admin/tenants` | Listar tenants. |
| Tenant status | `POST /admin/tenants/status` | Cambiar estado tenant (`active/suspended/hard_deleted`). |
| Hard delete approval | `POST /admin/tenants/{tenantId}/hard-delete-approvals` | Crear aprobacion 4-ojos. |
| Hard delete execute | `DELETE /admin/tenants/{tenantId}` | Ejecutar hard delete con step-up + approval. |
| Telemetry analytics | `GET /admin/telemetry/analytics` | Consultar telemetria agregada y suprimida por privacidad. |
| Collector preview | `POST /admin/telemetry/collector-preview` | Previsualizar sanitizacion del collector. |
| Forensics explorer | `GET /admin/forensics/audit` | Explorar eventos forenses. |
| Forensics export | `POST /admin/forensics/exports` | Encolar export. |
| Forensics token | `POST /admin/forensics/exports/{exportId}/token` | Emitir token one-time. |
| Forensics download | `POST /admin/forensics/exports/download` | Descargar payload con token por body. |
| Billing events | `GET /admin/billing/events` | Consultar eventos de billing global. |
| Billing reconcile | `POST /admin/billing/reconcile` | Encolar reconciliacion global. |
| Impersonation issue | `POST /admin/impersonation/issue` | Emitir sesion break-glass para soporte. |
| Impersonation terminate | `POST /admin/impersonation/terminate` | Revocar sesion por `jti`. |

### Lo que NO hace esta area

- No permite bypass directo de policies/step-up.
- No permite usar tokens sensibles por query string.
- No permite operar como tenant sin trazabilidad (impersonation es auditado y revocable).

## 4.4 Notificaciones y realtime (Fase 6)

Estas rutas suelen ser usadas por la app y no por navegacion manual directa:

| Metodo + Ruta | Uso |
|---|---|
| `GET /tenant/notifications` | Listar notificaciones del usuario tenant autenticado. |
| `PATCH /tenant/notifications/{notificationId}/read` | Marcar como leida. |
| `DELETE /tenant/notifications/{notificationId}` | Eliminar notificacion. |
| `POST /broadcasting/auth` | Autorizar canales websocket tenant-scoped. |

## 4.5 Integraciones SSO/IdP y webhooks

| Metodo + Ruta | Uso |
|---|---|
| `POST /sso/start` | Inicio SSO desde central. |
| `POST /sso/consume` (tenant) | Consume codigo one-time SSO. |
| `POST /sso/redeem` | Redeem backchannel code. |
| `GET /idp/claims/{userId}` | Endpoint S2S de claims IdP. |
| `POST /tenant/events/ingest` | Ingesta de eventos externos tenant. |
| `POST /tenant/billing/webhooks/{provider}` | Recepcion de webhooks de billing. |

> Estas rutas son de integracion/sistema. No son pantallas de navegacion para usuario final.

## 4.6 Modulos generados (Phase 8)

- Cada modulo nuevo queda bajo la familia de rutas: `/tenant/modules/{slug-plural}`.
- Ejemplo actual: `GET /tenant/modules/sample-entities`.
- El modulo aparece automaticamente en el sidebar tenant cuando queda registrado en `config/phase8_modules.php`.
- El alta tecnica del modulo se realiza con `php artisan make:saas-module {Name} --schema=...` (ver `docs/manuals/phase-8-manual-usuario.md`).
- Estado inicial de seguridad: un modulo recien generado puede responder `403` hasta que sus abilities/policies sean habilitadas por el equipo responsable.

---

## 5) Como entrar segun tu rol

## 5.1 Usuario central

1. Abrir `http://localhost:8000/login`.
2. Iniciar sesion.
3. Ir a `/dashboard`.
4. Gestionar cuenta en `/settings/*`.

## 5.2 Usuario tenant

1. Abrir `http://tenant.localhost:8000/login`.
2. Iniciar sesion (ejemplo: `owner@tenant.localhost` / `password`).
3. Redireccion esperada: `/dashboard` -> `/tenant/dashboard`.
4. Abrir `/tenant/settings` para invites, RBAC, auditoria y billing.

## 5.3 Admin global

1. Abrir `http://localhost:8000/admin/login`.
2. Iniciar sesion con cuenta `platform` (ejemplo: `superadmin@example.test`).
3. Ir a `/admin/panel`.
4. Ejecutar operaciones sensibles desde la UI (step-up, lifecycle, forensics, billing, impersonation).

---

## 6) Limites y reglas clave que el usuario debe conocer

- Un usuario tenant no debe usar `/admin/*`.
- El hard delete requiere aprobacion y capability temporal.
- Exportes forenses usan token one-time y no deben ponerse en query string.
- Mutaciones de roles tenant pueden exigir step-up (`423 STEP_UP_REQUIRED`).
- Telemetria puede responder `429 PRIVACY_BUDGET_EXHAUSTED` por protecciones anti-inferencia.

## 7) Errores frecuentes (lectura rapida)

- `ADMIN_QUERY_REJECTED`: query sensible en rutas admin.
- `INVALID_ORIGIN`: mutacion admin desde origen no permitido.
- `TENANT_STATUS_BLOCKED`: tenant suspendido/hard_deleted.
- `STEP_UP_REQUIRED`: falta reautenticacion/capability para accion sensible.
- `INVALID_AUDIT_WINDOW`: rango de auditoria invalido.
- `IMPERSONATION_EXPIRED`: sesion impersonada expirada o revocada.

## 8) Referencias recomendadas

- Manuales por fase (`docs/manuals/phase-1-manual-usuario.md` ... `phase-8-manual-usuario.md`) para detalle profundo por modulo.
- Este manual para operacion transversal del sistema completo.
