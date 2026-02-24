---
description: Plan de ejecución detallado para la Fase 5 (Superadmin, Telemetría Global & Platform Lifecycle)
---

# Fase 5 — Superadmin, Telemetría Global y Gestión de Plataforma (Plan de Ejecución)

**Objetivo principal:** Construir el panel B2B SaaS Backoffice con una arquitectura de contención de privilegios (*Privilege Containment*). Garantizar que la administración global, la telemetría agregada y el acceso *Break-Glass* (Impersonation) operen bajo invariantes estrictas de seguridad: denegación absoluta por *Denylist*, auditoría inyectada desde token confiable y métricas anonimizadas desde el Colector OTel.

## 1. Contención de Privilegios (Superadmin RBAC & Environment Isolation)
- [x] **Aislamiento de Entorno (Platform Guard & Cookies):**
  - El portal Superadmin utiliza un **guard dedicado** (`platform`).
  - **Invariante `__Host-` estricta:** La cookie de sesión DEBE emitirse con `Secure; Path=/; HttpOnly; SameSite=Lax|Strict;` y **sin atributo `Domain`**.
  - Para evitar falsos negativos de CI/local, los E2E de sesión se ejecutan sobre HTTPS real (ej. `mkcert`) o usan nombre alterno de cookie solo en entorno local controlado.
  - **Guard Confusion Tripwires:**
    - Test de cobertura: todas las rutas `admin.*` deben incluir middleware que fuerce `Auth::shouldUse('platform')`.
    - Test de ejecución: request real a endpoint `admin.*` que evalúe *Gate* y aserte `platform` activo antes de `authorize()`.
  - **Entry points no HTTP:** Casos de uso de plataforma (jobs/commands/backoffice services) exigen `PlatformContext` explícito; no se permite depender del guard implícito del request.
- [x] **Step-Up Auth Capability:**
  - Operaciones sensibles requieren MFA reciente que emite un *capability* temporal (TTL 5-10 min) en *session store* del servidor.
  - **Propiedades:** *binding* a `session_id + device_fingerprint`, `scope` explícito, revocación en logout/cambio MFA/suspensión.
  - **Consumo atómico (race-proof):** Para acciones ultra-destructivas (Hard Delete), capability *single-use* con `capability_id` y `consumed_at` usando operación atómica (`UPDATE ... WHERE consumed_at IS NULL`, filas afectadas = 1).
  - **IP Binding como señal:** cambio de IP aumenta fricción (re-step-up), no *hard-fail* por defecto; para Hard Delete puede ser regla estricta.
- [x] **Denylist Global (Deny-Hard):**
  - En `Gate::before`, si guard=`platform` y ability en `superadmin_denylist.php`, **retornar `false`** terminal.
  - **Namespacing de abilities obligatorio:** `platform.*` vs `tenant.*`.
  - El denylist aplica sobre `platform.*`; ninguna ability `platform.*` puede resolverse en policies tenant.

## 2. Gestión de Ciclo de Vida del Tenant (Platform Lifecycle)
- [x] **Circuit Breaker Activo (TenantStatus):**
  - Evaluado en *SSO jumps*, requests críticas (mutaciones/exportaciones/billing hooks) y middleware de jobs.
  - Invalidación por **evento pub/sub + TTL bajo** para minimizar staleness.
  - En jobs largos, verificar `TenantStatus` al inicio y antes de cada side effect irreversible.
  - **Métrica operativa:** `job_aborted_due_to_tenant_status` sin `tenant_id` ni labels de alta cardinalidad (allowlist mínima: `status`, `environment`).
- [x] **Borrados Duros y Tombstones (Hard Deletes):**
  - Tombstone inmutable en Central (`HARD_DELETED`).
  - Ingesta S2S rechaza permanentemente eventos tardíos (anti-resurrección).
  - Checklist: DB + S3 + credenciales + índices secundarios + llaves de integración.
  - Aprobación segregada con *2-Person Rule*.

## 3. Impersonation Seguro (RFC 8693 Break-Glass)
- [x] **Contrato Explícito de Impersonation (Claim `act`):**
  - `sub` = sujeto (usuario tenant)
  - `act.sub` = actor (platform user)
  - `act.iss` = issuer del actor token (**allowlist estricta**)
  - `act.role`, `act.ticket`, `act.reason`
  - `aud` estricto al tenant destino (anti replay cross-tenant)
  - `jti` con consumo atómico anti-replay
  - **Regla de profundidad:** `act` anidado prohibido (profundidad máxima 1) para evitar ambigüedad forense.
- [x] **Bloqueo Server-Side y Derivación Anti-Spoofing:**
  - Mutaciones rechazadas por defecto si `is_impersonating == true` (excepto allowlist explícita).
  - `actor_platform_user_id`, `subject_user_id` e `impersonation_ticket_id` se derivan exclusivamente de token/contexto confiable, nunca del payload.

## 4. Telemetría Global y Observabilidad (Anti-Differencing & OTel)
- [x] **Minimización de Datos en Colector (OTel Processors):**
  - Allowlist/redaction/filter/transform aplicada en Collector **antes de exportar**.
  - Cobertura explícita de `spans`, `metrics`, `logs` y `resource attributes` (no solo attributes de span).
- [x] **K-Anonimato y Anti-Differencing:**
  - Umbral mínimo `k` (ej. 10) para mostrar agregados.
  - Time-bucketing fijo + contribution cap por tenant.
  - Supresión estable + redondeo/quantization + rate limiting/caching de endpoints analíticos para reducir inferencia iterativa.

## Criterios de Aceptación (DoD+++++++++++++++ Obligatorios)
- [x] **Test `__Host-` completo en HTTPS:** `Set-Cookie` incluye `Secure`, `Path=/`, `HttpOnly`, `SameSite`, sin `Domain`.
- [x] **Tripwire Guard Confusion (cobertura + ejecución):**
  - Todas las rutas `admin.*` tienen middleware de `Auth::shouldUse('platform')`.
  - Request real a endpoint admin confirma guard activo `platform` antes de authorize.
- [x] **Test PlatformContext no HTTP:** comando/job de plataforma falla si no recibe `PlatformContext` explícito.
- [x] **Test Capability atómico (race):** dos consumos concurrentes del mismo capability: solo uno exitoso (filas afectadas=1).
- [x] **Test Denylist + Namespacing:** ability `platform.*` en denylist siempre denegada y nunca resuelta por policy tenant.
- [x] **Test Circuit Breaker en jobs largos:** suspensión durante ejecución bloquea side effect siguiente antes de persistir cambios.
- [x] **Test métrica low-cardinality:** `job_aborted_due_to_tenant_status` no contiene `tenant_id`, `job_class`, `error_detail` ni labels no allowlisted.
- [x] **Test `act.iss` + no nested `act`:** issuer fuera de allowlist y tokens con `act` anidado son rechazados.
- [x] **Test Anti-Spoofing auditoría:** payload malicioso no puede sobrescribir actor/sujeto/ticket forense.
- [x] **Test OTel Collector (resource/log attrs):** atributos prohibidos no llegan al backend tras processors.
- [x] **Test anti-differencing operativo:** consultas repetidas en ventanas cercanas no permiten inferencia por supresión estable + rounding + caching.
