---
description: Manual de usuario de la Fase 6 (Notificaciones & Eventos en Tiempo Real)
---

# Manual de Usuario — Fase 6 (Notificaciones & Eventos en Tiempo Real)

Este manual documenta el uso, ciclo de vida e invalidaciones de la capa de Notificaciones asíncronas y Eventos en Tiempo Real configurada para operar con aislamientos cross-tenant y prevención de DoS.

## 1) Prerrequisitos

- PHP 8.4+
- Composer, Node 20+ y NPM
- Base de datos local actualizada (`php artisan migrate`)
- Entorno de Cola (Queue Worker) corriendo (`php artisan queue:work`) para consumir `tenant_notification_outbox`

## 2) Variables relevantes en `.env`

Configurar/validar:

- `BROADCAST_CONNECTION=phase6`
- `PHASE6_ALLOWED_ORIGINS=http://tenant.localhost,http://localhost`
- `PHASE6_AUTH_RATE_LIMIT_PER_MINUTE=90`
- `PHASE6_NOTIFICATIONS_LIST_LIMIT=50`
- `PHASE6_OUTBOX_QUEUE=default`
- `PHASE6_REALTIME_DEGRADED_TTL_SECONDS=60`

## 3) Primer arranque

Asegúrese de correr un worker que intercepte y despache los Jobs del Outbox:
```bash
php artisan queue:work --queue=default
```

Para probar retransmisiones (Reverb / Pusher), levante el servidor de web sockets configurado o instale la dependencia local de Reverb.

## 4) Operación funcional de Fase 6

### 4.1 Envío transaccional y asíncrono

Siempre que quiera notificar al front-end o registrar una alerta de sistema de manera segura y cross-tenant:
1. Resuelva el servicio: `app(\App\Support\Phase6\NotificationOutboxService::class)`
2. Invoque el método transaccional `enqueue($tenantId, $notifiableId, 'event.type', [...])`.
3. Esto bloqueará la secuenciación del outbox para ese tenant/usuario, creará una entrada idempotente en BD y enviará un `ProcessTenantNotificationOutboxJob` hacia la cola.

### 4.2 Consumo Frontend (API Endpoints Anti-IDOR)

Una vez enviado, si el usuario explora su panel de notificaciones:
- `GET /tenant/notifications?limit=50`: Obtiene el historial del usuario autenticado bajo ese tenant.
- `PATCH /tenant/notifications/{id}/read`: Marca una notificación específica como vista.
- `DELETE /tenant/notifications/{id}`: Elimina un mensaje para el usuario.

### 4.3 Recepción Websocket Auth

La aplicación Front-end intentará inicializar un `Echo` conectándose al canal de Epoch.
- `POST /broadcasting/auth`: Internamente, verifica `Origin` / `Referer` y un circuito de límite dinámico por socket/usuario/sesión.
- Si un administrador suspende a un tenant, o quita un rol al usuario, su Epoch cambiará (p.ej de `epoch.1` a `epoch.2`) rompiendo forzosa e instantáneamente todos los websockets activos enganchados a la versión obsoleta del canal.

## 5) Certificación recomendada

Para validar regresiones y el flujo correcto del outbox sin bloqueos asíncronos:

```bash
php artisan test
php artisan test tests/Feature/Phase6/Phase6ContractsTest.php --stop-on-failure
npm run build
CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0
node scripts/ci/00_guardrails.mjs
```

## 6) Errores comunes

### A) `403 Forbidden` en handshake /broadcasting/auth
Causa: El `Origin` no está listado en el array estricto `phase6.allowed_origins` o se ha cambiado la IP del cliente y falló la validación CORS defense-in-depth.

### B) Evento Socket no emitido / No hay logs de broadcast
Causa: El Queue Worker local se encuentra detenido y los items asíncronos del modelo `TenantNotificationOutbox` permanecen apilados (`processed_at` = null).

### C) `TENANT_STATUS_BLOCKED`
Causa: El backend rechazó una conexión socket de un usuario intentando ingresar a un canal de un tenant actualmente suspendido en Fase 5.

## 7) Resultado esperado de la fase

La Fase 6 integra colas transaccionales y WebSockets con barreras fuertes anti-spoofing y validaciones estrictas del `Epoch`, proveyendo comunicación asíncrona robusta y sin "Ghost broadcasts" para los arrendatarios de la plataforma.
