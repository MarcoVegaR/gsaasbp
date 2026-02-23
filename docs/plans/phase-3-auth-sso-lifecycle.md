---
description: Plan de ejecución detallado para la Fase 3 (Auth, SSO Transaccional & Lifecycle)
---

# Fase 3 — Autenticación, SSO Transaccional & Ciclo de Vida (Plan de Ejecución)

**Objetivo principal:** Implementar el sistema de identidad global (IdP Central), el inicio de sesión unificado y el mecanismo de Single Sign-On (SSO) transaccional hacia los tenants. Garantizar seguridad *zero-trust* mediante cookies `__Host-`, JWS *assertions* asíncronas con consumo atómico, prevención absoluta de BOLA/BOPLA y *scraping* en Claims, y validaciones extremas de parsing y *clickjacking*.

## 1. Gestión de Identidad Centralizada (IdP Global)
- [x] **Única Fuente de Verdad:** El modelo `User` y sus credenciales residen **exclusivamente** en la BD Central.
- [x] **User Claims Service (Anti-BOLA, BOPLA y Anti-Scraping):**
  - **Autorización por Objeto (Cierre Anti-BOLA):** El `tenant_id` **NO DEBE** ser un parámetro controlable. Debe derivarse del credencial *service-to-service* (S2S). Cada consulta valida activamente `(caller_tenant_id, target_user_id) -> active membership`.
  - **Data Minimization (Anti-BOPLA y DTO Estricto):** Contrato de Claims mínimo. **Prohibido usar `->toArray()`**. Serialización exclusiva mediante un DTO/Transformer con *allowlist* dura. El caché solo almacena este DTO permitido. Cualquier campo nuevo requiere *bump* de versión y test de *snapshot*.
  - **Anti-Scraping Estricto ("Shape Constraints"):** Endpoints operan solo por `user_id` exacto o búsqueda severamente restringida. No existe endpoint "listar todos los claims". Rate limit por minuto y **cuotas largas (diaria/semanal)** por tenant y por caller S2S. Alarmas por patrones anómalos: alta tasa sostenida de *cache hits* (scraping "lento") y picos de *cache misses*.
  - **Caché Estricta y Aislada:** Respuestas **solo en memoria**. Llave: `aud:sub` (`tenant_id:user_id`). **Prohibido** compartir pools. Si Redis obliga a persistencia, prohibido almacenar PII.
- [x] **Shadow Table (`tenant_users`) y Revalidación de Estado:**
  - Crear `tenant_users` en los tenants (cero PII, `UPSERT`).
  - **Revalidación Activa:** El SSO revalida estado (`is_active`, baneos) y membresías en Central **en el momento exacto del salto**.

## 2. Seguridad de Sesiones y Cookies (Zero-Trust)
- [x] **Invariantes `__Host-` de la Cookie de Sesión:**
  - Prefijo `__Host-`, `Secure=true`, `Path=/`, `HttpOnly=true`, `SameSite=Lax`, sin atributo `Domain`.
  - **Single Writer y Cookie Chaos:** La App es el único componente autorizado a emitir `Set-Cookie`. Garantizar que el proxy (Nginx/LB/CDN) NUNCA reescribe ni inyecta cookies competidoras en distintos *paths*. Validación **vía E2E a través del proxy real** inspeccionando cookies efectivas en el navegador (no solo cabeceras HTTP).

## 3. SSO Transaccional: JWS Assertion y Modos Operativos
- [x] **Prevención de Clickjacking y CSP en Auto-Submit:**
  - En páginas de auto-submit (Central) y endpoints de consumo (Tenant):
  - **CSP de "mínima superficie" con Hash/Nonce:** `Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action https://<tenant-host>; script-src 'sha256-<hash>'` (o `nonce`). El hash debe generarse y **validarse automáticamente en el pipeline CI** (evita regresiones a `unsafe-inline`).
  - **Precedencia Clickjacking:** Aplicar también `X-Frame-Options: DENY`.
- [x] **Prevención de SSO Initiation CSRF (Login CSRF):**
  - Salto SSO protegido por `POST`.
  - **Validación Robusta y Defense-in-Depth:** El token CSRF siempre manda (sin heurísticas). Si `Origin` o `Referer` existe, debe coincidir. Si no, aceptar *solo* si `Sec-Fetch-Site` indica `same-origin`/`same-site` junto al CSRF. Adicionalmente, si están presentes, validar `Sec-Fetch-Mode: navigate` y `Sec-Fetch-Dest: document` como contexto.
- [x] **Modos de Transmisión del SSO (Back-Channel vs Front-Channel):**
  - **Back-channel (SSO_MODE=backchannel):** `code` opaco que viaja mediante *POST auto-submit* al Tenant (NUNCA por URL).
    - **No Request-Body Logging:** Prohibido el logging del *body* en Central, Tenant, Nginx, LB o APM.
    - **Bind y State Obligatorio:** `tenant_id:code_hash -> {tenant_id, user_id, nonce, redirect_path, state}`.
    - **Redeem Binding:** S2S redeem impone `tenant_id(caller) == tenant_id(code)`. Falla genérico si no coincide.
  - **Front-channel (Fallback):** JWT por *POST auto-submit*. Inyectar `Referrer-Policy: no-referrer` y `Cache-Control: no-store`.
- [x] **Validación Estricta y Key Management (JWKS/kid Abuse):**
  - **Algorithm Pinning:** Fijar algoritmo (ej. `RS256`).
  - **Cierre de `kid` (No I/O):** El `kid` DEBE validar contra una *allowlist* en memoria o JWKS cacheado local. **PROHIBIDO usar `kid` para indexar el *filesystem*, *DB* o *fetch remoto/URL***. Fallo genérico para `kid` desconocido con *negative caching*.
  - **Bloqueo de Features Peligrosas JWT:** Rechazar terminantemente cabeceras dinámicas (`jku`, `x5u`, `jwk`) y rechazar/limitar estrictamente `crit`. Validar `iss` (exacto), `aud` (exacto) y `typ` esperado.
  - **Clock Skew:** Tolerancia máxima (ej. ±60s) para `nbf`, `iat`, `exp`. Rotación tolera la llave anterior solo en esa ventana.
  - **Orden de Validación:** Validar cabeceras prohibidas, algoritmo, firma, `iss`, `aud`, `exp`, `nbf` e `iat` ANTES de leer payload o Redis.
- [x] **Consumo Atómico en Redis (Multi-nodo Operable):**
  - Ejecutar el consumo `GETDEL(tenant_id:jti)` en un **cliente Redis de escritura separado**, configurado estructuralmente para apuntar **exclusivamente al primary node**. Forzar `Session::regenerate()`.

## 4. Normalización Extrema y Allow-list de Callbacks
- [x] **Resolución Segura del Destino (Parser Mismatch E2E):**
  - Aceptar solo *paths relativos* comenzando con **único `/`**.
  - **Prohibido:** `//`, `\`, dobles encodings (`%252f`).
  - **Consistencia de Parser Unificado:** Validador, *redirect builder* y *runtime/routing* DEBEN usar exactamente la misma implementación y el mismo helper/librería (WHATWG URL, misma *base URL*) de punta a punta.
- [x] **Canonización de Dominios (TR46 / UTS #46):**
  - Canonizar dominios a ASCII. Guardar canonizado y hacer el **matching exacto** sobre esa forma.

## 5. Ciclo de Vida (Lifecycle) y Auditoría Segura
- [x] **HSTS Preload Controlado (Gating):**
  - Forzar HSTS con `includeSubDomains; preload` y `max-age` largo **solo en dominios de plataforma 100% controlados** (declaración explícita de intención).
  - Para Custom Domains de Tenants, NO usar `includeSubDomains; preload`.
- [x] **Auditoría Estructurada:**
  - Rate-limiting a `/sso/consume`.
  - **Structured Logging (JSON):** Truncado de `User-Agent` y sanitización.

## Criterios de Aceptación (DoD++++++++++ Obligatorios)
- [x] **Test Claims BOPLA (Data Minimization):** Aserción de que el payload se genera vía DTO (nunca `toArray`) y cumple estrictamente el contrato versionado.
- [x] **Test Anti-Scraping (Claims Quotas):** Validación de alarmas y cuotas (diarias/semanales) operativas ante patrones sostenidos de *cache hits* y *misses*.
- [x] **Test Cookie Chaos Guard (Proxy E2E):** Prueba E2E a través del proxy real inspeccionando *cookies efectivas en el UA* para garantizar que no hay competidoras y que el *Single Writer* prevalece sin degradación de `__Host-`.
- [x] **Test CSP Hash CI Pipeline (Clickjacking):** Verificación forzosa en pipeline CI de que el `sha256-...` (o `nonce`) corresponde al *script* de auto-submit desplegado. Falla build ante `unsafe-inline`.
- [x] **No request-body logs:** Pipeline check/config scanning certificando ausencia de logging de bodies en endpoints SSO (App + Reverse Proxy + APM).
- [x] **Test JWKS / kid Abuse:** Payloads maliciosos en `kid` (`../../..`) y cabeceras dinámicas (`jku`, `x5u`, `jwk`, `crit` no autorizado) devuelven falla genérica sin I/O.
- [x] **Test JWT Algorithm Pinning & Clock Skew:** `alg=none`, HS/RS confusion fallan antes de Redis. Claims de tiempo aplican `±60s` de skew.
- [x] **Test Redis Primary Enforcement:** Configuración comprobable del cliente Redis de escritura apuntando estructuralmente al *primary node* con namespace `tenant_id:jti`.
- [x] **Test Redeem Binding (Anti-Mix-Up S2S):** Tenant A no puede redimir código de Tenant B aunque tenga *code+state* (403 genérico).
- [x] **Test Modo Backchannel sin URL:** `code` y JWT viajan por POST *body* con headers *anti-cache/referer*.
- [x] **Test Hardening de Paths (Parser Unificado):** El endpoint usa un único helper WHATWG y rechaza diferencialmente `//dashboard`, `\dashboard`, `%2f%2f`. Fuzzing diferencial.
- [x] **Test TR46 Storage Consistente:** Dominios IDN guardados y evaluados canonizados ASCII.
