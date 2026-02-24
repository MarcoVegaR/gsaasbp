---
description: Plan de ejecución detallado para la Fase 5 (Superadmin, Telemetría Global & Platform Lifecycle)
---

# Fase 5 — Superadmin, Telemetría Global y Gestión de Plataforma (Plan de Ejecución)

**Objetivo principal:** Construir el panel B2B SaaS Backoffice con una arquitectura de contención de privilegios (*Privilege Containment*). Garantizar que la administración global, la telemetría agregada y el acceso *Break-Glass* (Impersonation) operen bajo invariantes estrictas de seguridad: denegación absoluta por *Denylist*, auditoría inyectada desde el token y métricas anonimizadas desde el Colector OTel.

## 1. Contención de Privilegios (Superadmin RBAC & Environment Isolation)
- [ ] **Aislamiento de Entorno (Platform Guard & Cookies):**
  - El portal Superadmin utiliza un **guard dedicado** (`platform`).
  - **Invariante `__Host-` Estricta:** La cookie de sesión DEBE emitirse con `Secure; Path=/;` y **sin atributo `Domain`**. Para evitar falsos negativos en CI/Local, el test E2E y el desarrollo deben ejecutarse sobre HTTPS real (ej. `mkcert`).
  - **Guard Confusion Execution Tripwire:** Un test de integración hace un *request* real a un endpoint `admin.*` que evalúa un *Gate*, y aserta que el *guard* activo es exactamente `platform` antes de autorizar.
- [ ] **Step-Up Auth Capability:**
  - Operaciones sensibles requieren MFA reciente que emite un *capability* temporal (TTL 5-10 min) gestionado en el *session store* del servidor.
  - **Propiedades:** Atado a `session_id` + `device_fingerprint`. Scope explícito. Revocable en logout/MFA.
  - **Consumo Atómico (Hard Delete):** Para acciones ultra-destructivas, el *capability* es de **un solo uso** (*single-use*), evitando el reuso dentro de la ventana de TTL.
  - **IP Binding como Señal:** Un cambio de IP sube la fricción (exige re-auth) pero no hace *hard-fail* automático (evita falsos positivos por NAT).
- [ ] **Denylist Global (Deny-Hard):**
  - En el `Gate::before`, si el *guard* es `platform` y la habilidad está en `superadmin_denylist.php`, **DEBE retornar `false`** (terminal).

## 2. Gestión de Ciclo de Vida del Tenant (Platform Lifecycle)
- [ ] **Circuit Breaker Activo (TenantStatus):**
  - Evaluado en *SSO Jumps*, *requests* críticas (mutaciones/billing) y **Middleware de Jobs**.
  - **Job Abort Metrics (Low Cardinality):** Si `TenantStatus ∈ {SUSPENDED, HARD_DELETED}`, el Job aborta de forma idempotente y emite la métrica `job_aborted_due_to_tenant_status`. **Micro-cierre:** Esta métrica tiene prohibido incluir `tenant_id` o etiquetas de alta cardinalidad para evitar filtración por observabilidad.
- [ ] **Borrados Duros y Tombstones (Hard Deletes):**
  - **Tombstones Inmutables:** El borrado crea un registro `HARD_DELETED` en Central. La capa S2S rechaza permanentemente eventos tardíos.
  - **Checklist:** DB, S3, Credenciales, Índices Secundarios, Llaves de Integración. Requiere *2-Person Rule* segregada.

## 3. Impersonation Seguro (RFC 8693 Break-Glass)
- [ ] **Contrato Explícito de Impersonation (Claim `act`):**
  - Estructura obligatoria en el JWS:
    - `sub` = sujeto (usuario del tenant)
    - `act.sub` = actor (*platform user id*)
    - `act.iss` = *issuer* del actor token (debe validarse contra una **allowlist explícita**, no coincidencia libre, para unicidad cross-IdP)
    - `act.role`, `act.ticket`, `act.reason`
    - `aud` = tenant destino (validación estricta anti-replay cross-tenant)
    - `jti` = anti-replay atómico
- [ ] **Bloqueo Server-Side y Derivación Anti-Spoofing:**
  - Mutaciones rechazadas por defecto a nivel Middleware si `is_impersonating == true`.
  - **Micro-cierre Anti-Spoofing:** Los campos `actor_platform_user_id`, `subject_user_id` e `impersonation_ticket_id` se derivan **única y exclusivamente del token confiable**, NUNCA del *payload* del *request*.

## 4. Telemetría Global y Observabilidad (Anti-Differencing & OTel)
- [ ] **Minimización de Datos en el Colector (OTel Processors):**
  - **Micro-cierre:** La *allowlist* de atributos se aplica **antes de exportar en el OpenTelemetry Collector** (mediante *processors*), asegurando que atributos prohibidos (emails, *path params* crudos) no lleguen al backend de métricas, sin depender de la disciplina de la aplicación.
- [ ] **Umbral de K-Anonimato y Anti-Differencing:**
  - Métricas agregadas exigen un umbral mínimo $k$ (ej. $k=10$).
  - **Time-Bucketing & Contribution Cap:** Agrupación en ventanas temporales fijas (1h/24h) y límite máximo de contribución por Tenant para prevenir ataques de *differencing* sobre agregados.

## Criterios de Aceptación (DoD+++++++++++++++ Obligatorios)
- [ ] **Test Invariantes `__Host-` en HTTPS:** Un test E2E ejecutado sobre HTTPS real inspecciona el `Set-Cookie` del portal de administración y asegura que incluye `Secure`, `Path=/` y carece del atributo `Domain`.
- [ ] **Tripwire de Guard Confusion (Ejecución):** Un test hace un *request* real a un endpoint administrativo y aserta que, justo antes del `authorize()`, el `Auth::guard()` activo es exactamente `platform`.
- [ ] **Test Capability Consumo Atómico:** Un *capability* emitido para un *Hard Delete* es invalidado inmediatamente después de su primer uso exitoso; un segundo intento en el mismo milisegundo o ventana TTL falla.
- [ ] **Test Job Abort Low Cardinality:** Al abortar un *Job* por suspensión, el sistema verifica que la métrica emitida `job_aborted_due_to_tenant_status` no contenga el `tenant_id` ni *labels* de alta cardinalidad.
- [ ] **Test Unicidad `act.iss` Allowlist:** Un salto de *impersonation* es rechazado de inmediato si su `act.iss` no pertenece a la *allowlist* en memoria, bloqueando *issuers* desconocidos.
- [ ] **Test Anti-Spoofing Auditoría:** Un *payload* malicioso intentando inyectar un `actor_platform_user_id` distinto en el body del request es ignorado, forzando los datos extraídos criptográficamente del claim `act`.
- [ ] **Test OTel Collector Allowlist:** Una prueba *mockeada* del pipeline OTel aserta que atributos prohibidos inyectados por la aplicación son redactados por los *processors* del colector antes de la exportación final.
