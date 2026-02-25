---
description: Manual de usuario de la Fase 7 (Panel Central de Administración)
---

# Manual de Usuario — Fase 7 (Panel Central de Administración)

Este manual documenta el uso de la interfaz de administración global B2B, operaciones destructivas, y extracción forense. Las capacidades avanzadas como *Hard Delete*, *Impersonation* y Telemetría tienen defensas de *anti-abuse* rigurosas (Circuit Breakers / Privacy Budget).

## 1) Prerrequisitos

- PHP 8.4+
- Base de datos migrada (`php artisan migrate:fresh --seed`)
- Cuenta "Superadmin" asignada mediante configuración.
- Opcionalmente un Worker local activado para *Forensic Exports*.

## 2) Variables relevantes en `.env`

Asegúrese de parametrizar las salvaguardas:

- `SUPERADMIN_EMAILS=superadmin@example.test` (Vital para Gate platform)
- `PHASE7_ADMIN_INACTIVITY_TIMEOUT_SECONDS=900`
- `PHASE7_ADMIN_MUTATION_ORIGIN_REQUIRED=true`
- `PHASE7_SYSTEM_CONTEXT_ALLOWED_PURPOSES=admin.tenants.list,admin.tenant.hard-delete.approval,...`
- `PHASE7_PRIVACY_BUDGET_WINDOW_SECONDS=3600`
- `PHASE7_PRIVACY_BUDGET_MAX_COST_PER_WINDOW=20`
- `PHASE7_FORENSICS_MAX_WINDOW_DAYS=30`
- `PHASE7_FORENSICS_MAX_EXPORT_ROWS=5000`
- `PHASE7_FORENSICS_EXPORT_DISK=local`
- `PHASE7_HARD_DELETE_APPROVAL_TTL_SECONDS=900`
- `PHASE7_HARD_DELETE_SIGNATURE_KEY=cambiar-esto-en-produccion`
- `PHASE7_IMPERSONATION_TTL_SECONDS=180`

## 3) Acceso al Panel Central

Diríjase a la ruta `/admin/panel` sobre el dominio central. Necesitará estar autenticado como Superadmin en la guarda global.

### Defensas Ocultas Activas:
1. **Redirección de Inquilinos:** Un usuario de tenant (guard `web`) nunca podrá ver la UI, y obtendrá un redirect a login.
2. **CSP Stricta:** El Backoffice B2B bloquea totalmente los intentos de *iframing* (`frame-ancestors 'none'`).
3. **Bloqueo PHPSESSID:** Para frenar fugas de sesión HTTP, las variables secretas pasadas por la query string (e.g. `?token=abc`) forzarán invalidaciones asertivas del token de sesión.

## 4) Gestión del Ciclo de Vida del Tenant

### 4.1 Modificar Estado
La UI *Tenant lifecycle* le permite congelar operaciones de Inquilinos emitiendo estado `suspended`. El Worker de telemetría y el SSO rechazarán todas las siguientes operaciones hasta restaurarse a `active`.

### 4.2 Hard Delete 4-Ojos
Para mitigar riesgos de demolición inadvertida, el *Hard Delete* se ejecuta en dos pasos transaccionales con separación de personal (*separation of duties*):

1. **Aprobación asíncrona:** El supervisor (`requested_by_platform_user_id`) solicita la firma, y un admin concurrente (`approved_by_platform_user_id`) autoriza el `approval_id`.
2. **Ejecución atómica:** El ejecutor invoca el servicio de demolición inyectando el `approval_id` + una pre-autorización temporal tipo **Step-Up Capability** (disponible en la cabecera de la UI).
3. Todo intento de reciclar el `approval_id` resulta en Error HTTP 409 y se deniega al instante.

## 5) Auditoría Forense y Exportación

### Explorador
Todas las consultas a los registros de actividad `ActivityLog` son forzadas mediante filtros sargables de bases de datos. No se pueden generar búsquedas sin rango horario para proteger la infraestructura y soportar particionado estricto. Las PII de los clientes (`hmac_kid`) y correos figurarán redactados si están ofuscados por hash.

### Exportación Desacoplada (One-Time)
Para bajar archivos sensibles a disco duro (*air-gapped*):

1. **Queue Export:** Solicite la exportación asíncrona de un bloque de logs. Generará un ID.
2. **Issue Token:** Transforme dicho ID en un Token Descartable y efímero.
3. **Download Payload:** Pegue el *One-Time Token*. Un segundo click o envío por URL del mismo resultará en error `410 Gone`.

## 6) Gestión de Privacidad y Telemetría

A diferencia de un panel de métricas regular, la API de OTel Dashboard rastrea el número de variables de dimensionalidad (filtros extra por ID de tenant/evento) en relación al Costo Fijo por Ventana (Por defecto: 20 peticiones "caras" por hora). Si supera las consultas iterativas, un error 429 *PRIVACY_BUDGET_EXHAUSTED* bloqueará al analista e informará mediante Security Alarms la sospecha de inferencia anti-anónima.

## 7) Break-glass Flow (Impersonation)

1. Para asumir una sesión temporal y auditable dentro del Workspace Inquilino, emita una aserción vía UI (con el paso previo de emisión de `Step-Up`).
2. Se le entregará una URL temporal (e.g. `https://tenant.localhost/sso/consume`).
3. El servidor le emitirá un Session temporal visible y trazable, junto a un Banner Rojo de alerta persistente.
4. Para invalidar forzosamente este túnel activo (por compromisos de seguridad de endpoint), extraiga el parámetro `jti` de la UI y presione **Terminate Impersonation** para el borrado global atómico en todas las instancias Worker / Redis del clúster.

## 8) Errores Frecuentes

### A) `INVALID_AUDIT_WINDOW` en Explorador
Causa: Excedió el máximo de búsqueda (`30 días`) o los tiempos se solicitaron sin orden cronológico.

### B) `INVALID_HARD_DELETE_APPROVAL` en Destrucción de Inquilinos
Causa: El ID de aprobador, ejecutor y solicitante entran en conflicto. Tienen que ser dos o más Superadmins distintos interactuando (cuatro ojos / separation of duties).

### C) `ADMIN_QUERY_REJECTED` al bajar Forensic Payload
Causa: Ingresó el token JWT One-Time de descarga en la URL del navegador. Siempre debe insertarlo desde la interfaz JSON por POST.
