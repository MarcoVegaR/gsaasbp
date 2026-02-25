---
description: Backlog de pendientes para definir nueva fase de productizacion del boilerplate SaaS
---

# Fase 9 (propuesta) — Pendientes del boilerplate SaaS

## 0) Estado actual (base existente)

Actualmente el proyecto ya tiene:

- Panel Admin (`/admin/panel`) con acciones de lifecycle, hard-delete, telemetry, forensics, billing e impersonation.
- Panel Tenant (`/tenant/settings`) con invitaciones, RBAC, auditoria y billing.
- Soporte de i18n por middleware (`?lang=es|en`) y cookie `locale`.
- Motor de modulos tenant-scoped (ejemplo: `sample-entities`).

## 1) Bloqueantes para considerar el SaaS "listo para clientes reales"

1. **Alta de tenant desde Admin (UI + API) — BLOQUEANTE PRINCIPAL**
   - No existe endpoint de creacion de tenant en `routes/admin.php`.
   - No existe formulario en `/admin/panel` para crear tenant.
   - Debe incluir:
     - `tenant_id` (o generacion UUID server-side).
     - dominio/subdominio inicial.
     - estado inicial (`active`/`suspended`).
     - metadata base (`name`, `plan`, etc).

2. **Asignacion de owner inicial durante onboarding — BLOQUEANTE**
   - Falta flujo admin para:
     - crear/enlazar usuario owner,
     - crear membresia en `tenant_users`,
     - asignar rol `owner` en el team del tenant,
     - impedir tenant sin owner.

3. **Bootstrap automatico al crear tenant — BLOQUEANTE**
   - Crear catalogo minimo de roles/permisos tenant.
   - Crear entitlements iniciales segun plan.
   - Registrar auditoria de provisioning.

## 2) Pendientes funcionales de alta prioridad

1. **Gestion de usuarios del tenant (ciclo completo)**
   - Hoy hay invite + RBAC, pero falta UX/API para:
     - desactivar/reactivar miembro,
     - revocar membresia,
     - ban/unban,
     - historial de cambios por miembro.

2. **Gestion de dominios por tenant (admin)**
   - Agregar/quitar/cambiar dominio del tenant desde admin.
   - Validaciones de unicidad, formato y colisiones con central domains.

3. **Vista detalle de tenant en Admin**
   - Hoy hay listado agregado.
   - Falta detalle operativo por tenant con:
     - owner actual,
     - miembros,
     - plan/entitlements,
     - auditoria y estado de integraciones.

4. **Flujo comercial minimo (alta cliente end-to-end)**
   - Wizard: crear tenant -> owner -> plan -> dominio -> acceso inicial.

## 3) Pendientes de Administración y Settings Generales (Standard Boilerplate Features)

1. **Gestión de roles y permisos desde UI Tenant (Avanzado)**
   - Actualmente hay RBAC, pero falta la capacidad de crear/editar roles customizados por el tenant (si el plan lo permite).
   
2. **Configuración global del Tenant (Tenant Settings UI)**
   - Editar nombre, logo/branding, zona horaria y preferencias generales.

3. **Selector visual de idioma en UI (admin + tenant + central)**
   - Hoy se cambia por query param (`?lang=es|en`).
   - Falta control visible (dropdown/switch) en el layout base para el usuario final.
   - Persistencia y UX: Sincronizar la cookie host-only existente con el perfil del usuario.

## 4) Pendientes de robustez operativa

1. **Rate limits y validaciones de provisioning admin**
   - Evitar alta duplicada de tenant por reintentos.
   - Idempotencia en flujos de creacion.

2. **Observabilidad del onboarding**
   - Eventos de auditoria para cada paso critico:
     - tenant.created,
     - owner.assigned,
     - domain.bound,
     - onboarding.completed.

3. **Playbooks de soporte**
   - Procedimiento para recuperacion de owner,
   - procedimiento de cambio de dominio,
   - procedimiento de suspension/reactivacion.

## 5) Pendientes de QA/Certificacion para la nueva fase

1. Tests feature para onboarding admin (crear tenant + owner).
2. Tests de permisos y guard rails (admin/platform vs tenant/web).
3. Tests E2E del flujo comercial base.
4. Tests i18n del selector visual + persistencia por host.

## 6) Propuesta de alcance para la nueva fase

**Nombre sugerido:** Fase 9 — Generic Boilerplate Completion (Admin & Tenant Lifecycle).

**Objetivo:**
Cerrar las brechas operativas del boilerplate base para que sea funcionalmente equivalente a un panel productivo (estilo Nova/Filament), permitiendo la gestión completa del ciclo de vida de tenants, dominios, usuarios e idioma desde la UI, sin depender de seeders ni base de datos directa.

**Definition of Done (resumen):**

- Admin puede crear tenant, asignar dominio y definir owner inicial desde la UI (sin seeders).
- Admin puede ver el detalle operativo de cada tenant.
- Cada tenant puede gestionar su equipo completo (activar, desactivar, roles).
- Idioma seleccionable por UI (dropdown) en admin/tenant/central con persistencia correcta.
- Settings básicos del tenant editables por el owner.
- Suite minima de pruebas automatizadas en verde para los nuevos flujos.

## 7) Preguntas abiertas para la discusion

1. El alta de tenant sera exclusivamente manual (operador admin) o dejaremos preparada la base para un autoservicio (self-signup B2B) futuro?
2. Al crear un tenant, ¿el owner inicial se crea siempre como un usuario nuevo o puede seleccionarse un usuario central existente buscando por email?
3. ¿Qué planes comerciales iniciales definimos en los seeders (Starter/Pro/Enterprise) y qué entitlements base incluye cada uno para automatizar el bootstrap del tenant?
