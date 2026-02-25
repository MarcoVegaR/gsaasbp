---
description: Plan de ejecución detallado para la Fase 7 (Panel Central de Administración)
---

# Fase 7 — Panel Central de Administración (Plan de Ejecución)

**Objetivo principal:** Construir la interfaz de gestión global (Backoffice B2B) integrando la contención de privilegios de Fase 5, el control de acceso concurrente seguro y la auditoría forense rigurosa. Este panel permite al *Superadmin* operar transversalmente bajo invariantes de aislamiento estricto (prevención de *guard confusion*), separación de deberes para acciones destructivas, bypass acotado estrictamente por propósito vía `SystemContext::execute()`, y prevención absoluta de exfiltración o inferencia iterativa de datos tenant.

## Secuencia de Implementación Recomendada
1. Infraestructura Base del Panel Admin (Routing, `PlatformGuard` Hard Assert, Anti-Clickjacking/CSRF, Layout Inertia).
2. Gestión de Tenants y Ciclo de Vida (CRUD, Suspensión fail-closed transversal, Hard Delete con *4-ojos* y *Step-Up*).
3. Visor de Telemetría y Analítica (OTel Dashboards, Métricas k-anonymity, Query Budget).
4. Auditoría Forense Global (Explorador particionado sargable, Redaction UI, Exportación cifrada *One-Time*).
5. Gestión de Suscripciones/Billing Centralizado (Reconciliación manual, Forzar Sincronización).
6. Impersonation UI (Break-Glass Flow, Emisión JWT acotado a `aud`/`jti`, Terminación activa, Auditoría).
7. Suite de Contratos E2E/Feature (Aislamiento, Circuit Breakers, Anti-escalación).

## 1. Infraestructura Base del Backoffice (Aislamiento y Routing)
- [ ] **Enrutamiento Estricto y Middleware:**
  - Todas las rutas `/admin/*` deben usar el guard `platform`.
  - **Hard Assert Temprano (Middleware Order):** Se inyecta `EnsurePlatformGuard` *antes* del middleware de `SubstituteBindings` explícitamente en `bootstrap/app.php` usando `$middleware->priority([...])` para anular leaks de timing/errores por *guard confusion*.
  - Middleware obligatorio: `Auth::shouldUse('platform')` + `UsePlatformSessionSettings` (`__Host-` cookie estricta de Fase 5).
  - Prevención de fugas de sesión: Solo se acepta sesión vía cookie. Configurar PHP con `session.use_trans_sid=0` y `session.use_strict_mode=1` para evitar adopción de IDs externos. Configurar Edge (Nginx/Proxy) para hacer strip/bloqueo de identificadores de sesión en la URL para `/admin/*` y enrutar *scrubbing* en access logs.
  - El layout base de Inertia (`AdminLayout`) valida en montaje la presencia de `coreDictionary` y estado de sesión.
- [ ] **Protección Anti-Tenant-Admin:**
  - Un administrador de tenant (por más permisos que tenga en Spatie) **nunca** puede acceder.
  - Validación: `Gate::authorize('platform.admin.access')` en cada controlador admin. El namespace `platform.*` asegura denegación automática por el *Denylist* de Fase 5 si no es Superadmin real.
- [ ] **Defensas Anti-CSRF y Anti-Clickjacking:**
  - CSP estricto (`frame-ancestors 'none'`) más *graceful degradation* con `X-Frame-Options: DENY` como fallback para navegadores legacy (prohibido iframetear el backoffice).
  - Mutaciones estandarizadas: **Axioma de seguridad: SameSite ≠ CSRF**. CSRF token sigue siendo estrictamente obligatorio para todo *state-changing*. Validación de `Origin` como capa adicional, y prohibición explícita de endpoints mutadores vía GET.
- [ ] **Manejo de Estados de UI:**
  - Timeout por inactividad estricto en frontend (ej. 15 min) que fuerza redirección a login central, destruyendo caché local.

## 2. Gestión de Tenants y Ciclo de Vida (Tenant CRUD)
- [ ] **Visor de Tenants (Read-Only inicial):**
  - Listado paginado de tenants con estado actual (`active`, `suspended`, `hard_deleted`), plan de facturación y cuotas.
- [ ] **Mutaciones Sensibles (Circuit Breaker Integration):**
  - **Suspensión (Fail-Closed Transversal):** Cambiar estado a `SUSPENDED`. Despacha evento para invalidar cachés, sesiones y WebSockets. **Crítico:** Garantizar validación de status en HTTP, Jobs, Outbox y Billing para evitar *ghost access* en ventanas de latencia.
  - **Hard Delete (Tombstoning + 4-Ojos Atómico):** Exige separación de deberes donde la aprobación es un capability firmado (`approval_id`) de uso único ligado a (tenant, action, executor, reason, window). Se consume atómicamente en una sola transacción junto al Step-Up del iniciador.
- [ ] **Bypass Controlado (`SystemContext::execute()`):**
  - Cualquier acceso a datos tenant desde el panel DEBE envolverse en `SystemContext::execute()`. Prohibido el uso de `withoutGlobalScopes()`.
  - **Scope/Blast Radius explícito:** Exigir `targetTenantId` y `purpose`. Cada purpose define su *blast radius*: scope de tablas/recursos, límite de paginación/rate, y *deny by default* para cruces no previstos. Falla duro si el purpose es desconocido.
  - **Auditoría Zero-PII:** `Log::info('system_context.enter/exit')` registra el `actor_id`, `target_tenant` y `purpose`, asegurando redacción server-side para no fugar PII, emails ni queries en los logs.

## 3. Visor de Telemetría (OTel Analytics UI)
- [ ] **Dashboard de Métricas Agregadas:**
  - Consumo de endpoints de métricas de Fase 5 (`AdminTelemetryAnalyticsController`).
  - Presentación de datos aplicando *K-Anonimato* y *Anti-Differencing* (supresión estable, rounding, inyección opcional de ruido estadístico si aplica).
  - **Query/Privacy Budget Semántico:** Presupuesto formalizado por `(platform_user_id, ventana, métrica)` con costo por granularidad para evitar ataques iterativos (differencing). Al agotarse, activa bloqueo total (HTTP 429) y despacha evento auditable `privacy_budget_exhausted`. Dimensiones filtrables explícitamente limitadas.
  - Alertas visuales sobre picos de `job_aborted_due_to_tenant_status` (sin cardinalidad alta).
- [ ] **Monitoreo de Infraestructura:**
  - Visualización de latencia outbox, reconexiones WS (Fase 6) y consumo de cuotas IdP (Fase 3).

## 4. Auditoría Forense Global (Explorador Particionado)
- [ ] **Búsqueda Sargable Obligatoria:**
  - La UI del explorador de logs **exige** seleccionar un rango temporal (`created_at`).
  - Prohibido aplicar casts o funciones en la query (`DATE(created_at)`, `timezone()`) que destruyan la sargabilidad de PostgreSQL.
- [ ] **Visualización de Redaction (HMAC):**
  - Mostrar claramente cuándo un campo fue redactado (presencia de `hmac_kid`).
  - Opción de búsqueda por hash solo permitida ingresando el texto plano y hasheándolo en frontend/backend contra la misma llave activa.
- [ ] **Exportación Asíncrona Seguro:**
  - Prevenir exfiltración "legítima": Exportar requiere **Step-Up**, `reason_code` y **scope mínimo**.
  - El artefacto exportado debe estar cifrado en reposo.
  - Entrega mediante un token de descarga de uso único (*One-Time Token*) con expiración corta a un endpoint autenticado, haciendo streaming del artefacto (prohibido tokens expuestos en URLs).

## 5. Gestión de Suscripciones y Facturación
- [ ] **Visor de Eventos Billing:**
  - Lista de eventos webhook procesados (`billing_events_processed`).
  - Resaltar divergencias (`outcome_hash` inconsistente).
- [ ] **Acciones de Reconciliación:**
  - Botón para despachar manualmente el `ReconcileTenantBillingJob` para un tenant con *drift*.

## 6. Break-Glass / Impersonation UI (RFC 8693)
- [ ] **Flujo de Asunción de Identidad:**
  - Requiere justificación explícita (`reason_code` o ticket de soporte) y **Step-Up Auth** si el entorno lo exige.
  - El backend emite un JWT stateful con claim `act` estructurado, `aud` ultra-específico y `exp` muy corto. El `jti` se ata al fingerprint de sesión platform; se revoca si cambia o hay uso concurrente de origen distinto.
  - Prohibido UI para anidar *impersonation* (depth máximo = 1).
- [ ] **Imposición del Resource Server (Visibilidad/Logging Tenant):**
  - Todo request portador de un claim `act` impone obligatoriamente el modo *Break-Glass* en el tenant RS.
  - Banner rojo persistente en UI indicando "Sesión Break-Glass Activa".
  - **Terminación activa:** Botón "Terminate impersonation now" en el banner que invalida el `jti` server-side (no solo limpiar UI).
  - Auditoría forense inyectada: El log registra actor, target, reason_code, duración y todas las mutaciones realizadas bajo impersonation.

## Criterios de Aceptación (DoD+++++++++++++++++++ Obligatorios)
- [ ] **Test Guard Confusion (Middleware Order):** Test inspecciona `Route::getMiddlewareGroups()` y `$middleware->getPriority()` para garantizar que `EnsurePlatformGuard` precede a `SubstituteBindings`. Falla si alguien reordena la lista.
- [ ] **Test Zero-Tokens in URL & Edge Logs:** Inyectar un `session_id`, `?PHPSESSID=known`, o `token` de exportación en querystring de rutas admin aborta autenticación, regenera ID (`use_strict_mode=1`), y asegura (vía aserción de logs simulados de reverse proxy) que dichos patrones se scrubbean antes de loguearse.
- [ ] **Test CSRF/Clickjacking Suite:** Mutación admin vía POST falla con `419/403` sin CSRF token o con Origin cruzado. Mutaciones bloqueadas en GET. Se aserta presencia de CSP `frame-ancestors 'none'` y `X-Frame-Options: DENY`.
- [ ] **Test Anti-Tenant-Admin:** Un usuario con rol "Admin" dentro de un tenant intenta acceder a `/admin/*` y recibe `403` uniforme, sin importar sus abilities en Spatie.
- [ ] **Test Bypass Controlado (SystemContext):** Las acciones CRUD del panel que tocan data tenant están validadas mediante análisis estático para no usar `withoutGlobalScopes()`, sino `SystemContext::execute()`.
- [ ] **Test SystemContext Blast-Radius:** Un purpose de lectura (`SystemContext::execute()`) falla o se trunca si intenta exceder su límite de iteración o acceder a tablas fuera de su scope definido.
- [ ] **Test Step-Up Consumo Atómico (UI):** Invocar mutación sensible desde la UI consume correctamente el `capability_id` y falla si se reintenta con el mismo token.
- [ ] **Test Hard Delete 4-Ojos:** Reintentar un `approval_id` validado o cruzar el target tenant de una aprobación resulta en hard fail.
- [ ] **Test Sargable Queries UI:** El submit del formulario de auditoría falla validación si el rango `created_at` excede el máximo permitido (ej. 30 días) o está ausente.
- [ ] **Test Partition Pruning Regression:** Test sobre explorador aserta explícitamente que el plan solo referencia particiones dentro del rango temporal o que particiones fuera de rango se marcan como *never executed/removed* en PostgreSQL.
- [ ] **Test Export One-Time Token:** Usar token de exportación 2 veces devuelve `410/404`, y token pasado en query URL es ignorado/rechazado.
- [ ] **Test Telemetry Differencing Attempt (Privacy Budget):** Secuencia automatizada de requests aplicando filtros estrechos agota el *privacy budget*, devuelve nulos/429 consistente, y despacha evento auditable `privacy_budget_exhausted`.
- [ ] **Test Impersonation Misuse & Concurrency:** Token copiado a otro navegador/contexto dispara revocación de `jti` + `401/403`. Token usado para Tenant Y falla por mismatch de `aud`. Uso tras presionar botón de "Terminar" falla.
- [ ] **Test Impersonation Enforcement (Resource Server):** Request válido con claim `act` fuerza inyección en payload Inertia para el banner rojo y enriquece `actor_platform_user_id` en los logs de auditoría sin omisión posible por controladores.
- [ ] **Test Security Alerting (OWASP A09):** Intentos repetidos de Guard Confusion, fallos de firma 4-ojos/Step-Up, o agotamiento de Query Budget deben generar registros logueados en canal `security_alarms`.
- [ ] **Test UI Anti-Differencing:** Intentar acotar filtros en el panel de telemetría para aislar datos de 1 solo tenant oculta los resultados (aplica *k-anonimato*).
- [ ] **Test Layout Mount Guard:** El componente Inertia del panel admin aborta renderizado si detecta que la sesión corresponde al guard `web` (tenant) en vez de `platform`.
- [ ] **Test Observabilidad Operativa:** El panel registra métricas de sus propias acciones administrativas (ej. suspender tenant) sin fugar PII hacia OTel.
