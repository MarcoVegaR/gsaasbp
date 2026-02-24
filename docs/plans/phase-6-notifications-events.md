---
description: Plan de ejecución detallado para la Fase 6 (Notificaciones & Eventos en Tiempo Real)
---

# Fase 6 — Notificaciones y Eventos en Tiempo Real (Plan de Ejecución)

**Objetivo principal:** Construir el subsistema de eventos en tiempo real (Laravel Echo/Reverb) y notificaciones tenant-scoped con **aislamiento cross-tenant verificable**, revocación efectiva de acceso y propagación asíncrona consistente. El diseño debe resistir presión real (ataques, reconnection storms, fallas de red/cola y errores operativos), manteniendo coherencia estricta con Fase 1 (tenancy fail-closed), Fase 3 (hardening de sesión/logging), Fase 4 (Stale Guard y anti-IDOR) y Fase 5 (TenantStatus circuit breaker).

## Secuencia de Implementación Recomendada
1. Contrato de canales (`ChannelNameBuilder`/`BroadcastChannelRegistry`) + registro único de canales.
2. Hardening de handshake Reverb + `/broadcasting/auth` (middleware order, guard determinista, Origin allowlist, CSRF/throttle, respuesta uniforme, no body logs).
3. Revocación efectiva por `TenantStatus`/RBAC/membresía con invalidación determinista (`authz_epoch`) y reautorización periódica de corta vida (TTL).
4. Broadcast async confiable (after-commit/outbox, idempotencia y orden parcial definido por agregado).
5. Notifications DB tenant-scoped + endpoints anti-IDOR + UI Inertia/Echo con payload mínimo y política de retención.
6. Resiliencia de escala (reconnection storms, circuit breaker de auth, backoff+jitter).
7. Observabilidad/alertas + suite de contratos E2E/Feature/carga/caos/arquitectura.

## 1. Infraestructura de Broadcasting Estricta (Aislamiento de Canales)
- [ ] **Ruta y Middleware de Autorización (`/broadcasting/auth`):**
  - Tenancy y contexto de equipo deben inicializarse antes de bindings/autorización para evitar *late resolution leaks*.
  - Guard tenant explícito y determinista por canal (declarado en `Broadcast::channel(..., ['guards' => [...]])`); prohibido fallback implícito al guard por defecto.
  - **Control primario CSWSH:** la allowlist de `Origin` se valida en el handshake de Reverb (`config/reverb.php` → `allowed_origins`).
  - `/broadcasting/auth` mantiene validación redundante de `Origin` como *defense-in-depth* (no control primario).
  - Rechazo uniforme anti-oracle: mismo status/body para denegaciones y latencia acotada (evitar diferenciación por timing).
  - Mantener autorización vía Gate/Policy (`can`, `Gate::allows`, `Broadcast::channel`), sin uso directo de API Spatie en código app.
  - No depender de WAF para inspección de frames WS: muchas implementaciones solo inspeccionan handshake.
- [ ] **Invariante de Canal + Anti-Spoofing:**
  - Todo canal tenant-scoped usa formato canónico (`private-tenant.{tenant_id}.{resource}` / `private-tenant.{tenant_id}.user.{user_id}` / para canales sensibles `private-tenant.{tenant_id}.user.{user_id}.epoch.{authz_epoch}`).
  - En `Broadcast::channel()`: `tenant_id(channel) === tenant()->id` y pertenencia del usuario al tenant activo, siempre fail-closed.
  - En callbacks/clases de canales: prohibido `Model::find()` sin scope tenant; obligatorio repositorio/consulta tenant-scoped (`findOrFail(tenant_id, resource_id)` o equivalente).
  - Prohibido confiar en channel model binding implícito para scoping tenant.
- [ ] **ChannelNameBuilder / Registry (Anti-Magic Strings):**
  - Backend y frontend generan/parsing de canales desde una factoría/registro único tipado.
  - Prohibida concatenación manual de strings de canal fuera del registro.
- [ ] **Controles Anti-DoS en Auth de Canales:**
  - Rate-limit multidimensional por `user_id`, `session_id`, IP y fingerprint de canal.
  - Límites lógicos: máximo conexiones por usuario/tenant, máximo suscripciones por socket, y máximo frecuencia subscribe/unsubscribe.
- [ ] **Hardening de Mensajes WS (Input Safety):**
  - Definir tamaño máximo de frame/mensaje y límites por ventana para evitar DoS por payload grande.
  - Configurar explícitamente `max_request_size` (handshake/request inicial) y `max_message_size` (frames/mensajes WS) en entornos productivos.
  - Validación estricta de schema/allowlist para payloads WS entrantes cuando existan eventos client->server.

## 2. Prevención de Fugas en Conexiones Persistentes (Long-lived WS)
- [ ] **Revocación Activa de Conexiones:**
  - Eventos críticos (`TenantStatusChanged`, `TenantMembershipRevoked`, `RoleRevoked`) fuerzan desconexión o invalidación de suscripciones activas.
  - Coherencia con Fase 5: si `TenantStatus ∈ {SUSPENDED, HARD_DELETED}`, bloquear nuevos handshakes y cortar conexiones activas.
  - **Invalidación determinista recomendada:** usar `authz_epoch` en nombre de canal para superficies sensibles; al revocar acceso, incrementar epoch y emitir solo al canal nuevo (el canal anterior queda estéril).
  - Si la desconexión forzada no es primitiva confiable en runtime, la garantía de seguridad será **re-auth periódica obligatoria** (TTL corto): sin renovación válida, la suscripción expira y no se reautoriza.
  - Refuerzo opcional: `max_connection_age` para forzar reconexiones periódicas y acotar ventanas de sesión larga.
- [ ] **Hardening de Handshake (`/broadcasting/auth`):**
  - *Throttle* estricto por usuario/IP/canal para prevenir DoS y enumeración de canales.
  - Circuit breaker específico para picos de reconexión (degradación controlada antes de saturar app/Redis).
  - Mantener disciplina de seguridad de Fase 3: no logging de request body en auth de broadcasting.
  - CSRF y validaciones de origen en endpoint de autenticación (defense-in-depth).
- [ ] **Presence Channels con Minimización de Datos:**
  - Presence habilitado solo cuando sea indispensable de producto.
  - Metadata limitada a identificadores técnicos mínimos (sin PII libre) y pseudónimo rotatorio cuando el caso lo permita.

## 3. Propagación Asíncrona Confiable (Queued Events)
- [ ] **Entrega Consistente (After-Commit / Outbox):**
  - Eventos y notificaciones sensibles deben emitirse tras commit para evitar “ghost broadcasts” por transacciones revertidas.
  - Para flujos críticos, usar patrón Outbox con deduplicación por `event_id`.
  - Definir SLO de entrega: p95/p99 `commit -> delivered` bajo carga objetivo.
- [ ] **Idempotencia y Orden Parcial:**
  - Envelope de evento: `event_id`, `tenant_id`, `aggregate_id`, `sequence`, `occurred_at`, `schema_version`.
  - Definir explícitamente el agregado de secuenciación (ej. `notification_stream:{tenant_id}:{user_id}`) y estrategia transaccional para `sequence`.
  - Consumidores/UI implementan regla `apply-if-newer` y persisten `last_seen_sequence` por stream.
- [ ] **Hidratación y Tear-down de Worker (Regla de Oro #11):**
  - Jobs `ShouldBroadcast` tenant-scoped rehidratan tenant/contexto RBAC al inicio.
  - En `finally`, restaurar estado neutral (`tenant() === null`, cache/registrar de permisos reseteado).
  - Prohibir eventos tenant-scoped async sin `TenantAware` (o contrato equivalente) vía linter/test de arquitectura.
- [ ] **Operación de Outbox (GC y Recuperación):**
  - GC/pruning de outbox con particionado/TTL y métricas (`outbox_lag`, `outbox_backlog`, `retry_count`, `dlq_size`).
  - Runbook de recuperación ante caída de Redis/queue sin pérdida ni duplicación visible para usuario final.

## 4. Notificaciones de Aplicación (Database, API y UI)
- [ ] **Aislamiento en `notifications`:**
  - Tabla incluye `tenant_id` NOT NULL + índices compuestos para consultas por tenant/usuario/lectura.
  - Scope `BelongsToTenant` obligatorio y fail-closed fuera de dominios centrales.
- [ ] **Anti-IDOR y Autorización Server-Side:**
  - Endpoints de "marcar leída/eliminar" deben filtrar por `tenant_id` + `notifiable_id` del usuario autenticado.
  - Validaciones en Policy/Gate, no solo en UI.
- [ ] **Payload Minimization & Fetch Seguro:**
  - Payload WS emite identificadores (`notification_id`, `event_type`, `version`), no PII.
  - Detalle se obtiene por HTTP protegido por *Stale Guard* (Fase 4) y circuit breaker de TenantStatus (Fase 5).
  - En shared props de Inertia, exponer solo contadores resumidos para no romper presupuesto de payload.
- [ ] **Retención y Ciclo de Vida de Notificaciones:**
  - Política explícita de retención/pruning (por ejemplo por antigüedad/estado) para controlar tamaño, backups y cumplimiento.
  - Tareas de compactación con monitoreo de crecimiento de índices y tiempos de consulta.

## 5. Observabilidad, Auditoría y Operación
- [ ] **Métricas y Logging Seguro:**
  - Métricas de handshake autorizado/denegado, desconexiones por revocación y latencia de delivery.
  - Labels de baja cardinalidad (sin `tenant_id` ni identificadores de usuario).
  - Logs/auditoría estructurados sin secretos ni payloads completos, pero con fingerprint tenant-safe (`tenant_fingerprint` HMAC), `trace_id` y `reason_code` interno.
- [ ] **Runbook de Degradación:**
  - Si WS cae, fallback controlado a polling con límites y sin romper aislamiento tenant.
  - Incluir procedimiento para aislar incidente por tenant sin usar métricas high-cardinality.
  - Definir valores explícitos de infraestructura para `idle timeout` (LB/proxy) y `ping interval` (app/socket) con ubicación exacta de configuración.
  - Definir matriz de coherencia de límites entre app e infraestructura (`max_request_size`, `max_message_size`, proxy/LB timeouts, heartbeat interval).
  - Incluir procedimiento de `php artisan reverb:restart` (rolling/graceful) y mitigación de reconexión (jitter).
- [ ] **Resiliencia a Reconnection Storm:**
  - Cliente con backoff exponencial + jitter.
  - Parametrización de LB/proxy (`idle timeout`, keepalive/ping) para evitar desconexiones masivas evitables.
- [ ] **Modo Degradado por Redis (Scaling Backbone):**
  - Si Redis pub/sub está degradado/no disponible, activar circuit breaker de realtime para evitar auto-DoS en app.
  - Degradar a polling controlado y limitar nuevas autenticaciones WS hasta recuperar salud.

## Criterios de Aceptación (DoD++++++++++++++ Obligatorios)
- [ ] **Test E2E Anti-Cross-Tenant Listening:** Usuario de Tenant A intentando canal de Tenant B recibe `403` uniforme.
- [ ] **Test Origin Allowlist en Handshake Reverb + WSS-only:** El handshake WS rechaza `Origin` fuera de allowlist antes de subscribe/auth y producción exige `wss`/TLS.
- [ ] **Test Defense-in-Depth Origin en `/broadcasting/auth`:** Aun con handshake válido, `/broadcasting/auth` rechaza orígenes no autorizados por política de app.
- [ ] **Test Anti-Oracle en `/broadcasting/auth`:** Denegaciones por canal inexistente, tenant mismatch o rol inválido retornan mismo envelope y latencia comparable; `reason_code` solo en logs internos.
- [ ] **Test Middleware/Guard Order y Guard Determinista:** Tenancy/contexto team se inicializa antes de authorize/bindings y cada canal usa guard explícito correcto.
- [ ] **Test Anti-IDOR en Channel Resource Binding:** `resource_id` de otro tenant en canal autorizado retorna deny uniforme y no filtra existencia.
- [ ] **Test Rate Limit Multidimensional + Límites Lógicos:** Se valida límite por usuario/sesión/IP/canal, máximo conexiones/suscripciones y límite subscribe/unsubscribe.
- [ ] **Test Block por TenantStatus (Fase 5):** `SUSPENDED/HARD_DELETED` bloquea handshake y revoca acceso activo.
- [ ] **Test Invalidación Determinista por `authz_epoch`:** tras revocación (rol/membresía), el epoch incrementa y broadcasts al canal nuevo no llegan a clientes suscritos al canal epoch anterior.
- [ ] **Test Revocación Hard por Re-Auth TTL:** Usuario revocado no puede reautorizar ni re-suscribirse tras expirar TTL, aun con socket vivo.
- [ ] **Test Presence PII Scan:** Payload presence cumple allowlist estricta y no contiene PII ni identificadores estables no aprobados.
- [ ] **Test Límites de Tamaño/Schema WS:** Frames/payloads fuera de límite o schema esperado son rechazados sin afectar estabilidad.
- [ ] **Test Integrity ChannelNameBuilder:** No existen canales privados creados por string literal fuera del registro central.
- [ ] **Test After-Commit / No Ghost Broadcast:** Un rollback de transacción no emite evento WS visible.
- [ ] **Test Idempotencia/Dedupe de Eventos:** Reenvío del mismo `event_id` no duplica notificación observable.
- [ ] **Test Sequence Contract + Apply-if-Newer:** UI/consumidor ignora eventos viejos/desordenados según `last_seen_sequence` por stream.
- [ ] **Test SLO de Entrega Outbox:** Bajo carga objetivo, p95/p99 `commit -> delivered` dentro del umbral definido.
- [ ] **Test Outbox GC/Pruning:** Proceso de retención reduce backlog sin romper idempotencia ni trazabilidad.
- [ ] **Test Caos Redis/Queue:** Caída y recuperación no produce ghost broadcasts ni duplicación visible al usuario.
- [ ] **Test Tear-down de Worker:** Tras job tenant-scoped, estado vuelve a neutral (`tenant() === null` + cache permisos limpia).
- [ ] **Test Anti-IDOR Notificaciones:** Usuario no puede leer/mutar notificaciones de otro usuario, incluso dentro del mismo tenant.
- [ ] **Test Retención de Notifications:** Política de pruning se aplica sin degradar índices/consultas críticas.
- [ ] **Test Payload Minimization WS:** Broadcast excluye PII/texto libre y fuerza fetch HTTP para detalle.
- [ ] **Test Reconnection Storm:** Simulación de reconexión masiva valida backoff+jitter cliente y circuit breaker de auth sin auto-DoS.
- [ ] **Test Idle Timeout + Ping/Keepalive:** Conexión ociosa sobre umbral operativo se mantiene por heartbeat válido (sin cortes espurios de LB/proxy).
- [ ] **Test Redis Degraded Mode:** Redis lento/no disponible dispara breaker de realtime y fallback a polling sin saturar `/broadcasting/auth`.
- [ ] **Config Lint Dual-Size WS (Prod):** CI falla si `max_request_size` y `max_message_size` no están definidos explícitamente para producción.
- [ ] **Config Contract Proxy/LB/Heartbeat:** CI/runbook check falla si no existen valores explícitos documentados para `idle timeout` (proxy/LB) y `ping interval` coherente.
- [ ] **Test No Request-Body Logs en Broadcasting Auth:** Scans/feature tests verifican ausencia de body logging.
- [ ] **Test Métricas Low-Cardinality + Correlación:** Métricas sin high-cardinality y trazabilidad por `trace_id` + `tenant_fingerprint` en logs/auditoría.
