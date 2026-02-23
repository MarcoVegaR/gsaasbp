---
description: Plan de ejecución detallado para la Fase 4 (Settings, Perfil, RBAC UI, Auditoría & Billing)
---

# Fase 4 — Configuración, Perfil, RBAC, Auditoría y Facturación (Plan de Ejecución)

**Objetivo principal:** Construir las interfaces B2B conectando la infraestructura base, identidad y diseño. Garantizar resiliencia operativa mediante proyecciones de perfil con control de *staleness* estricto (Backend), mutaciones RBAC *set-based* con *step-up auth*, auditoría forense con *redaction* gestionado por KMS (HMAC), e integrando un conector de facturación agnóstico robusto frente a eventos desordenados y reintentos.

## Secuencia de Implementación Recomendada
1. Key/Secrets Management (Vault/KMS) para firmas S2S y *redaction* HMAC.
2. Infraestructura de Eventos S2S (Envelope Estándar) + Deduplicación (DB) + DLQ.
3. Proyección de Perfil + *Staleness Guard* (Backend) + Revocación de Membresía.
4. Invitaciones Asíncronas (Soft Throttling 202) + Aceptación Segura + Consumo Atómico.
5. RBAC *Set-Based* + *Step-Up Auth* + Permisos No-Delegables + Último Owner + `acl_version`.
6. Auditoría Sargable (Pruning con Prepared Statements) + HMAC *Redaction* + Exportación Async.
7. Billing Connector (Interfaces) + Idempotencia DB + *Out-of-Order* + Reconciliación.
8. EntitlementService Centralizado (Fail-Closed) + Tests.

---

## 1. Perfil y Preferencias (Data Sovereignty & Profile Projection)
- [ ] **Eventos S2S y At-Least-Once Real:**
  - **Envelope Estándar:** `event_name`, `event_id`, `occurred_at`, `tenant_id`, `subject_id`, `schema_version`, `signature`, `retry_count`.
  - **Idempotencia S2S:** Persistencia de *dedupe* con PK `event_id`. Handlers idempotentes por construcción. DLQ con política de *replay* (manual y batch) y alertas.
- [ ] **Proyección de Perfil y Staleness Guard (Server-Side):**
  - La PII real reside **solo** en Central. Proyección local incluye: `central_user_id`, `display_name`, `avatar_url`, `mfa_status`, `profile_version`, `last_synced_at`, y `stale_after` (TTL).
  - **Staleness Guard (Backend):** Middleware/Policy server-side: si `now > stale_after`, rechaza llamadas a endpoints sensibles (invites/RBAC/billing) con HTTP 403/409, permitiendo solo *read-only* básico.
  - **Revocación:** Evento `TenantMembershipRevoked` borra proyección e invalida sesiones.
  - **Schema Guardrail:** Tests/linters impiden columnas PII (email, phone) en migraciones del Tenant.
- [ ] **Mutación PII y Redirección Segura:**
  - Redirección a Central con enlace firmado y *allowlist* estricta de `redirect_uri`.

## 2. Gestión del Workspace y RBAC UI (Takeover Prevention)
- [ ] **Invitaciones Asíncronas (Soft Throttling & Aceptación Segura):**
  - POST `/invites` devuelve **siempre `202 Accepted`**. Si se excede la cuota (*Soft Throttling*), el *Job* no envía el correo, solo audita, manteniendo opacidad.
  - **Aceptación Segura:** El usuario debe autenticarse en Central con un email que coincida con el `sub` del token JWS antes de activar la membresía.
  - **Consumo Atómico:** Tabla `invite_tokens` (PK `jti`, `consumed_at`) + Constraint de unicidad `(tenant_id, central_user_id)`.
- [ ] **Gestión de Roles (Set-Based & Step-Up Auth):**
  - Validación "solo otorgas permisos ⊆ permisos que posees". Permisos `assignable_permissions` no-delegables explícitos.
  - **Step-Up Auth:** Mutaciones RBAC exigen reautenticación o MFA reciente. Posible regla de 2 personas para "crown jewels". Auditoría obligatoria de `acl_updated_by`.
  - **Último Owner:** Constraint de DB (`SELECT ... FOR UPDATE`) para impedir dejar al tenant sin *Owner*. Bloqueo optimista con `acl_version`.

## 3. Consultas Forenses y Auditoría (Sargable Logs & HMAC Key Management)
- [ ] **Visor de Auditoría y Partition Pruning (Prepared Statements):**
  - Filtros *sargables* `created_at >= :from AND created_at < :to`. Prohibido usar `DATE(created_at)`. Garantizar que los *prepared statements* generados por el framework/driver **no degradan** el *Partition Pruning*.
- [ ] **Data Minimization y Redaction (HMAC Key Management):**
  - Guardar `hmac_kid` junto al valor HMAC en *properties* para permitir rotación de claves.
  - Secretos gestionados en Vault/KMS con proceso de "re-key" para histórico.
  - **Anti-Log Forging:** Sanitización estricta (tamaño máximo, eliminación de *control chars*) antes de persistir.
  - Denylist absoluta para *logs* (prohibido registrar *tokens*, contraseñas, *raw payloads*). Inyección de `request_id` *end-to-end*.
- [ ] **Exportación Segura (Anti-DoS):**
  - Queries de exportación retornan `202 Accepted` + Job asíncrono.

## 4. Conector de Facturación Agnóstico y Entitlement
- [ ] **Conector Intercambiable (Vendor Agnostic):**
  - Interfaces (`SubscriptionProvider`, `PaymentGateway`) debido a restricciones regionales. Cliente es el `Tenant`.
- [ ] **Idempotencia Robusta (Verification & Divergence):**
  - Primera línea: Verificación estricta de firma del *webhook* por proveedor.
  - Transacción `INSERT billing_events_processed(event_id) (PK)`. Si hay colisión, ignorar limpiamente (return OK).
  - Manejo *Out-of-Order* con `subscription_revision` o `provider_object_version` + *tie-breaker*.
  - **Reconciliación (Pull):** *Job* periódico para corregir *drift*, con *backoff* para no saturar al proveedor.
  - Alerta si el mismo `event_id` produce un `outcome_hash` distinto (divergencia).
- [ ] **EntitlementService Centralizado (Fail-Closed):**
  - Fallo cerrado por defecto para *features premium*.
  - Aplicable a: controladores, **Jobs**, comandos CLI y webhooks internos.
  - HTTP: `403 Forbidden` (`{ code: "BILLING_REQUIRED" }`).

## Criterios de Aceptación (DoD+++++++++++++ Obligatorios)
- [ ] **Test Server-Side Stale Guard:** *Request* directo al API sensible con proyección vencida devuelve consistente 403/409, no solo validación UI.
- [ ] **Test DLQ Replay (S2S Idempotencia):** Reinyectar un evento ya procesado no duplica efectos, solo audita "duplicate ignored".
- [ ] **Test Soft Throttling Indistinguible:** Abuso de API de invitación responde `202 Accepted`, pero silencia envíos.
- [ ] **Test RBAC Step-Up:** Administrador sin MFA reciente intenta mutación de roles y es bloqueado; tras *step-up*, es permitido.
- [ ] **Test HMAC Redaction Key Rotation:** Usando un nuevo `kid`, los eventos recientes se enmascaran con la nueva clave, mientras los antiguos siguen permitiendo *matching* sin exponer texto plano.
- [ ] **Test Anti-Sargable con Prepared Statements:** Endpoint forense con *prepared statements* es evaluado (ej. `EXPLAIN (ANALYZE, BUFFERS)`) para confirmar que escanea las particiones correctas y no hace *seq scan* global.
- [ ] **Test Billing Webhook Signature & Divergence:** Webhook sin firma válida es rechazado agresivamente. Mismo `event_id` resultando en un `outcome_hash` distinto dispara *incident hook*.
- [ ] **Test Billing Drift Reconciliation:** Simular pérdida de *webhook*; el *Job* de pull restablece el estado de suscripción y *entitlements*.
- [ ] **Test Strict Logging Rules (Anti-Leakage):** *Inputs* con emails/tarjetas/tokens se evalúan; aserción de que la BD de `activity_log` **solo** contiene hashes HMAC y máscaras, nunca texto en crudo.
- [ ] **Test EntitlementService en Job CLI:** Un *Job* asíncrono o comando CLI detiene su ejecución en modo *Fail-Closed* si el *Tenant* no tiene *entitlement* válido.
