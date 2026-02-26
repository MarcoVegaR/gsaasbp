---
description: Backlog refinado para la productización del Control Plane y Tenant Lifecycle (Generic Boilerplate)
---

# Fase 9 (Propuesta Refinada) — Control Plane Provisioning & Tenant Lifecycle

Basado en el análisis de riesgos operativos y para evitar que la productización introduzca deuda técnica o rompa invariantes de seguridad (tenancy fail-closed, anti-exfil, idempotencia), la Fase 9 se reestructura estrictamente en **tres entregables (9A, 9B, 9C)** enfocados en el "Generic Boilerplate".

---

## 9A) Control Plane Provisioning (Hard Core)

El objetivo es formalizar el "alta de tenant" no como un CRUD, sino como un **workflow orquestado del control plane** (agnóstico del entrypoint: admin manual o self-service futuro).

1. **State Machine y Orchestrator de Provisioning**
   - Estado transicional: `tenants.status` debe incluir `provisioning`.
   - Workflow atómico: Crear tenant -> Crear/Enlazar Owner -> Bootstrap RBAC/Entitlements -> Marcar como `active`.
   - Si falla a la mitad, el tenant queda en `provisioning` (sin login operativo).

2. **Idempotencia estricta por diseño**
   - Implementar llave de idempotencia por request (header o field) en el orquestador.
   - Reintentos con la misma llave deben retornar el resultado previo sin duplicar recursos.
   - Unicidad fuerte en DB: `tenants.id` (UUID generado server-side) y normalización estricta de dominios (lowercase, trim).

3. **Verificación de Propiedad de Dominios (Domain Control Validation)**
   - Agregar dominios requiere probar control (prevención de subdomain takeover / hijacking).
   - Implementar validación por registro TXT (DNS) o archivo HTTP (`/.well-known/...`).
   - Invariante: Solo se emite el evento `domain.bound` cuando existe `domain.verified_at`.

4. **Bootstrap inmutable de Roles y Permisos (Evitar RBAC Drift)**
   - Catálogo base versionado en código. Sincronización idempotente por tenant al aprovisionar.
   - Denylist estricto de permisos "imposibles" de asignar desde la UI del tenant (ej. mutaciones de billing global o lifecycle).

5. **Observabilidad con Correlation IDs**
   - Todo el flujo de onboarding debe compartir un `provisioning_correlation_id`.
   - Este ID debe viajar en eventos (`tenant.created`, `owner.assigned`), auditoría forense y spans de OpenTelemetry para permitir debug y reintentos exactos.

---

## 9B) Tenant Ops UX (Medium)

Una vez que el motor de provisioning es robusto, se construyen las interfaces administrativas (admin) y operativas (tenant).

1. **Vista Detalle de Tenant en Admin**
   - UI operativa por tenant: Owner actual, estado del provisioning, dominios vinculados (y su estado de verificación), plan/entitlements actuales y auditoría.

2. **Gestión completa del ciclo de vida de usuarios (Tenant UI)**
   - Funcionalidades UX/API para el Owner:
     - Desactivar/Reactivar miembro.
     - Revocar membresía.
     - Ban/Unban de usuarios problemáticos.
     - Historial de cambios forenses por miembro.

3. **Owner Recovery (Break-glass Playbook)**
   - Endpoint seguro y playbook administrativo para recuperar/reasignar el owner de un tenant cuando el original se pierde (ej. empleado desvinculado).

---

## 9C) Product Polish & Frontend (Soft)

Refinamientos de cara al usuario final y flujos comerciales.

1. **Selector Visual de Idioma (i18n UI Seguro)**
   - Dropdown/switch visible en layouts (admin, tenant, central).
   - Control estricto de scope de cookies (`__Host-locale` con `Secure`, `Path=/`, sin `Domain` amplio) para evitar cross-host cookie confusion.
   - Evaluar sincronización con el perfil de usuario (solo si aplica al contexto específico para evitar filtrado de preferencias cross-tenant).

2. **Wizard Comercial End-to-End (UI Administrativa)**
   - Pantallas en `/admin/panel` que consuman el orquestador de la fase 9A:
     - Ingreso de datos básicos -> Selección/Creación de Owner (búsqueda por email auditada) -> Plan base -> Dominio inicial.
     - Confirmación explícita antes de ejecutar (prevención de "wrong tenant assignment").

---

## QA & Certificación (Negative Testing)

La certificación de esta fase se centra en **lo que NO debe pasar**:

1. **Idempotencia:** Reintentar "crear tenant" 5 veces con el mismo `idempotency_key` debe resultar en exactamente 1 tenant.
2. **Domain Validation:** Intentar hacer bind de un dominio sin validación TXT/HTTP debe fallar cerrado (`fail-closed`).
3. **Atomicidad de Owner:** Un fallo simulado durante la asignación del owner debe dejar el tenant en estado `provisioning` (inoperable), nunca activo y huérfano.
4. **Cookie Scope:** Cambio de idioma no debe afectar sesiones o sobreescribir contextos por mal uso del atributo `Domain`.
5. **Suspensión:** Cambiar estado a `suspended` revoca inmediatamente accesos en todos los paths (web, jobs, realtime).

---

## Preguntas/Definiciones Operativas para arrancar

1. **Planes y Entitlements:** ¿Migramos la definición de planes (Starter/Pro/Enterprise) de los seeders a una configuración inmutable versionada para que el orquestador sepa exactamente qué entitlements conceder en el bootstrap?
2. **Verificación de Dominios:** ¿Para el MVP de verificación de dominios (9A) implementamos solo TXT record, o vamos directo por un proveedor (ej. Cloudflare API) si ya se usa bajo el capó?
