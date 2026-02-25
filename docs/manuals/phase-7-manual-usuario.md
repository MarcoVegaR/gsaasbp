---
description: Manual operativo de rutas y menus (Panel Central y Workspace Tenant)
---

# Manual Operativo de Rutas y Menus

Este manual explica **como funciona el sistema por rutas**, usando como base las pruebas de contratos y autenticacion ya ejecutadas.

Incluye:

1. Que rutas aparecen en el menu.
2. Para que sirve cada ruta.
3. Que **no** hace cada ruta.
4. Que rutas existen pero no se exponen en el menu (solo APIs/flujo interno).

## 1) Evidencia de pruebas usada para este manual

- `tests/Feature/Phase5/Phase5ContractsTest.php`
- `tests/Feature/Phase7/Phase7ContractsTest.php`
- `tests/Feature/Auth/AuthenticationTest.php`

Estas pruebas validan guardas, seguridad de rutas, contratos de panel admin, tokens one-time, hard delete, impersonation y flujo de login tenant.

## 2) Vista pedagogica por fases (resumen)

- **Fase 1:** Base multi-tenant por dominio (separacion central vs tenant).
- **Fase 2:** Base UI + i18n compartido.
- **Fase 3:** Endurecimiento SSO/JWT.
- **Fase 4:** Invites, RBAC, auditoria y billing tenant.
- **Fase 5:** Control plane global (`platform.*`), step-up y circuit breaker.
- **Fase 6:** Notificaciones/broadcasting tenant.
- **Fase 7:** Panel central B2B (`/admin/*`) con forensics, telemetria, hard delete 4-ojos e impersonation break-glass.

## 3) Rutas que SI aparecen en menu

## 3.1 Sidebar en contexto Tenant (`/tenant/*`)

| Opcion visible | Ruta | Objetivo | NO hace |
|---|---|---|---|
| Tenant dashboard | `/tenant/dashboard` | Landing principal del workspace tenant. | No ejecuta mutaciones sensibles por si sola. |
| Workspace settings | `/tenant/settings` | Configuracion del espacio tenant. | No reemplaza controles de RBAC/auditoria/billing de Fase 4. |
| Modulos dinamicos (ej. Sample Entity) | `/tenant/modules/sample-entities` | Entrar a modulos generados por catalogo de Phase 8. | No concede permisos automaticamente; requiere abilities/policies del modulo. |

Notas:

- Los modulos del sidebar tenant se construyen desde `tenantModules` compartido por Inertia y el catalogo (`config/phase8_modules.php`).
- Ruta de ejemplo actual en menu: `Sample Entity` -> `/tenant/modules/sample-entities`.

## 3.2 Sidebar en contexto Admin (`/admin/*`)

| Opcion visible | Ruta | Objetivo | NO hace |
|---|---|---|---|
| Central admin panel | `/admin/panel` | UI principal del backoffice global para operar lifecycle, telemetria, forensics, billing e impersonation. | No autentica por si sola; requiere guard `platform` y middleware de seguridad activos. |

## 3.3 Menu de usuario (dropdown)

| Opcion visible | Ruta | Objetivo | NO hace |
|---|---|---|---|
| Settings (solo guard `web`) | Ruta de perfil (`/settings/profile`) | Editar perfil del usuario tenant/central no-platform. | No aparece para guard `platform`. |
| Log out (guard `web`) | `POST /logout` | Cerrar sesion de usuario normal. | No usa GET; no debe llamarse por enlace simple. |
| Log out (guard `platform`) | `POST /admin/logout` | Cerrar sesion del admin global. | No usa `POST /logout` de guard web. |

## 4) Rutas importantes que NO estan en menu (pero son parte del sistema)

Estas rutas se usan por botones/formularios internos del panel o por flujos de seguridad.

## 4.1 Acceso y sesion admin

| Ruta | Objetivo | NO hace |
|---|---|---|
| `GET /admin/login` | Renderiza formulario de acceso admin (`platform`). | No otorga sesion sin credenciales validas. |
| `POST /admin/login` | Inicia sesion admin con throttling. | No salta validaciones de guard. |
| `GET /admin/dashboard` | Endpoint tecnico de verificacion de guard/cookie. | No reemplaza el panel funcional (`/admin/panel`). |

## 4.2 Operacion interna del panel admin

| Seccion UI | Ruta | Objetivo | NO hace |
|---|---|---|---|
| Step-up | `POST /admin/step-up/capabilities` | Emitir capability temporal por scope sensible. | No ejecuta mutacion final. |
| Tenant directory | `GET /admin/tenants` | Listar tenants para operacion. | No cambia estado del tenant. |
| Tenant status | `POST /admin/tenants/status` | Cambiar estado (`active/suspended/hard_deleted`). | No elimina fisicamente tenant. |
| Hard delete approval | `POST /admin/tenants/{tenantId}/hard-delete-approvals` | Crear aprobacion 4-ojos. | No hace hard delete directo. |
| Hard delete execute | `DELETE /admin/tenants/{tenantId}` | Ejecutar hard delete con approval + step-up. | No permite replay de approval/capability. |
| Telemetry analytics | `GET /admin/telemetry/analytics` | Consultar agregados con privacy budget. | No entrega datos sin supresion anti-differencing. |
| Forensics list | `GET /admin/forensics/audit` | Explorar logs forenses con ventana temporal. | No acepta consultas sin `from/to`. |
| Forensics export request | `POST /admin/forensics/exports` | Encolar export forense. | No descarga archivo directamente. |
| Forensics token | `POST /admin/forensics/exports/{exportId}/token` | Emitir token one-time de descarga. | No permite uso indefinido del token. |
| Forensics download | `POST /admin/forensics/exports/download` | Descargar payload via token en body. | No acepta token por query string. |
| Billing events | `GET /admin/billing/events` | Consultar eventos de billing. | No reconcilia estado por si solo. |
| Billing reconcile | `POST /admin/billing/reconcile` | Encolar reconciliacion de billing. | No salta step-up/capability. |
| Impersonation issue | `POST /admin/impersonation/issue` | Emitir sesion break-glass temporal para tenant. | No mantiene sesion permanente. |
| Impersonation terminate | `POST /admin/impersonation/terminate` | Revocar una sesion por `jti`. | No depende del cliente; revoca server-side. |

## 4.3 Rutas tenant de compatibilidad o seguridad (no menu)

| Ruta | Objetivo | NO hace |
|---|---|---|
| `GET /dashboard` (en dominio tenant) | Compatibilidad post-login Fortify: redirige a `/tenant/dashboard`. | No renderiza dashboard por si misma. |
| `POST /tenant/impersonation/terminate` | Break-glass desde banner rojo en UI tenant. | No emite impersonation nueva. |

## 5) Que valida la suite sobre estas rutas

- Usuario tenant intentando `/admin/panel` => redirect/denegacion (evita guard confusion).
- Mutaciones admin con origen invalido => `403 INVALID_ORIGIN`.
- Query secrets en admin (`?PHPSESSID=...`) => `400 ADMIN_QUERY_REJECTED`.
- Hard delete approval = one-time y ligado al tenant correcto.
- Forensics exige ventana temporal acotada y sargable.
- Token de descarga forense = one-time (`410` en replay).
- Telemetria aplica privacy budget (`429 PRIVACY_BUDGET_EXHAUSTED`).
- Impersonation puede revocarse por `jti` y tenant recibe bloqueo si expiro/revoco.
- Login tenant en `/login` redirige por cadena valida: `/dashboard` -> `/tenant/dashboard`.

## 6) Flujo manual recomendado (paso a paso)

1. Iniciar sesion admin en `GET /admin/login` con usuario `platform`.
2. Abrir `GET /admin/panel` desde menu lateral.
3. Emitir step-up y probar:
   - cambio de estado tenant,
   - aprobacion hard-delete,
   - ejecucion hard-delete,
   - export forense con token one-time,
   - reconciliacion billing,
   - issue/terminate impersonation.
4. Iniciar sesion tenant en `http://tenant.localhost/login`.
5. Verificar redireccion a `/tenant/dashboard` (via compat `/dashboard`).
6. Validar sidebar tenant (`dashboard`, `settings`, modulos dinamicos).

## 7) Errores esperados y significado rapido

- `ADMIN_QUERY_REJECTED`: intento de pasar secretos por query en `/admin/*`.
- `INVALID_ORIGIN`: mutacion admin fuera de origen permitido.
- `INVALID_AUDIT_WINDOW`: consulta forense sin ventana valida.
- `PRIVACY_BUDGET_EXHAUSTED`: exceso de consultas de telemetria de alto costo.
- `IMPERSONATION_EXPIRED`: `jti` revocado/expirado en contexto tenant.

## 8) Resumen ejecutivo

- El menu muestra solo rutas de navegacion de alto nivel.
- Las rutas sensibles (mutaciones, exportes, break-glass) estan fuera de menu y se ejecutan bajo middleware + step-up + contratos fail-closed.
- Las pruebas de Fase 5, Fase 7 y Auth cubren tanto la navegacion visible como las rutas no visibles que sostienen la seguridad operacional.
