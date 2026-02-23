---
description: Plan de ejecución detallado para la Fase 3 (Auth, SSO Transaccional & Lifecycle)
---

# Fase 3 — Autenticación, SSO Transaccional & Ciclo de Vida (Plan de Ejecución)

**Objetivo principal:** Implementar el sistema de identidad global (IdP Central), el inicio de sesión unificado y el mecanismo de Single Sign-On (SSO) transaccional hacia los tenants. Garantizar seguridad *zero-trust* mediante cookies `__Host-`, JWS *assertions* asíncronas con consumo atómico, prevención absoluta de BOLA en Claims, y validaciones extremas de parsing de callbacks.

## 1. Gestión de Identidad Centralizada (IdP Global)
- [ ] **Única Fuente de Verdad:** El modelo `User` y sus credenciales (passwords, 2FA) residen **exclusivamente** en la BD Central.
- [ ] **User Claims Service (Anti-BOLA Extremo y Anti-Cache Poisoning):**
  - Servicio central de solo lectura para exponer PII (email, nombre) a los tenants sin duplicar columnas.
  - **Cierre Quirúrgico Anti-BOLA:** El `tenant_id` **NO DEBE** ser un parámetro controlable (input) en el request. Debe derivarse exclusivamente del canal de autenticación *service-to-service* (ej. claim del token S2S o certificado mTLS).
  - **Caché Estricta y Aislada:** Las respuestas se almacenan **solo en memoria** (Redis configurado explícitamente sin RDB/AOF). La llave del caché debe ser estructurada rígidamente como `aud:sub` (`tenant_id:user_id`). **Prohibido** compartir pools de caché entre tenants sin prefijos fuertes. Un pico de "cache miss" debe tratarse como un evento auditable (señal de scraping).
- [ ] **Shadow Table (`tenant_users`) y Revalidación de Estado:**
  - Crear la tabla `tenant_users` en los tenants. Solo debe contener `user_id` y `timestamps` (cero PII).
  - El mecanismo de `UPSERT` en el primer acceso hidrata la relación.
  - **Revalidación Activa:** El SSO debe revalidar el estado del usuario (`is_active`, baneos) y membresías en la BD Central **en el momento exacto del salto**, no confiar ciegamente en la tabla *shadow*.

## 2. Seguridad de Sesiones y Cookies (Zero-Trust)
- [ ] **Invariantes `__Host-` de la Cookie de Sesión:**
  - La cookie de sesión DEBE usar el prefijo `__Host-` (ej. `__Host-session`). Esto garantiza a nivel de navegador que se cumplan las invariantes: `Secure=true`, `Path=/`, y sin atributo `Domain`. Además, se debe forzar explícitamente `HttpOnly=true` y `SameSite=Lax`.
  - **Riesgo Operativo (Dev):** En entornos locales (dev), se debe forzar HTTPS (ej. Valet/Mkcert) para que `Secure` (y por ende `__Host-`) funcione.
- [ ] **Prevención de SSO Initiation CSRF (Login CSRF):**
  - La emisión del salto SSO es una acción sensible y DEBE requerir una mutación protegida (`POST`).
  - **Validación Robusta (Micro-Cierre):** Exigir siempre token CSRF válido. Si el `Origin` o `Referer` existe, debe coincidir. Si no existe (por políticas de privacidad del cliente), aceptar el request *solo* si las cabeceras `Sec-Fetch-Site` indican `same-origin` o `same-site` (cuando estén presentes) junto al CSRF válido. **Prohibido relajar reglas si faltan cabeceras.**

## 3. SSO Transaccional: JWS Assertion y Modos Operativos
- [ ] **Modos de Transmisión del SSO (Back-Channel vs Front-Channel):**
  - El nombre técnico del modelo es **"JWS Assertion + jti anti-replay"**. (No es Proof-of-Possession puro sin DPoP/mTLS en cliente).
  - **Back-channel (SSO_MODE=backchannel):** El IdP emite un `code` opaco de un solo uso. **El `code` NUNCA viaja por URL (Query String)**; se entrega mediante un *POST auto-submit* al Tenant. El Tenant redime este `code` directamente contra Central (vía *server-to-service auth*, mTLS o JWT client assertion) para obtener el JWT final.
    - **Bind y State Obligatorio:** El `code` se guarda en Central (`code_hash -> {tenant_id, user_id, issued_at, nonce, redirect_path, state}`) con TTL ultracorto. El `state` previene ataques mix-up.
  - **Front-channel (Fallback):** Enviar el JWT mediante *POST auto-submit*. Inyectar `Referrer-Policy: no-referrer` y `Cache-Control: no-store`. **Prohibido** pasar tokens o codes en la Query String.
- [ ] **Validación Estricta y Ordenada del JWT:**
  - **Algorithm Pinning (No negociable):** Fijar explícitamente el algoritmo (ej. `RS256`). Rechazar estruendosamente `alg=none` o confusión `HS/RS` *antes* de validar la firma.
  - **Orden de Validación (Micro-Cierre):** El Tenant DEBE validar localmente el algoritmo, firma, `iss`, `aud`, `exp`, `nbf` e `iat` ANTES de leer el payload confiable o tocar Redis.
- [ ] **Consumo Atómico (Anti-Race Conditions y Oráculos):**
  - Solo tras la validación local, el Tenant ejecuta `GETDEL(jti)` en Redis para anti-replay.
  - Si Redis falla, el proceso hace **fail closed** (Circuit Breaker con UX de reintento en Central).
  - **Anti-Oráculo:** Devolver respuestas de fallo genéricas (ej. 403 "Invalid SSO Assertion"). No revelar si falló por firma, algoritmo, caducidad o re-uso. Forzar `Session::regenerate()` tras consumo exitoso.

## 4. Normalización Extrema y Allow-list de Callbacks
- [ ] **Resolución Segura del Destino (Parsing Differentials):**
  - **Cierre Quirúrgico del Path:** Aceptar solo *paths relativos* obligando a que comiencen exactamente con un **único `/`**.
  - **Prohibido explícitamente:** `//`, `\`, dobles encodings (`%252f`), o caracteres de control. Aplicar normalización alineada con WHATWG URL antes de usar el path. El host se extrae exclusivamente del registro del tenant.
- [ ] **Canonización de Dominios (IDN / Punycode):**
  - Canonizar dominios personalizados a ASCII (UTS #46). El storage debe ser consistente (guardar canonizado) y el **matching exacto** debe ocurrir *sobre la forma canonizada* en la base de datos (prohibidas reglas `contains` o `endsWith`).

## 5. Ciclo de Vida (Lifecycle) y Auditoría Segura
- [ ] **HSTS Preload Controlado (Gating):**
  - Forzar el header HSTS con `includeSubDomains; preload` y `max-age` largo **solo en dominios 100% controlados (Central / Plataforma)**.
  - Para Custom Domains de Tenants, NO usar `includeSubDomains; preload` para evitar "brickear" subdominios del cliente ajenos a la plataforma.
- [ ] **Auditoría Estructurada y Protección DoS:**
  - Rate-limiting estricto al endpoint `/sso/consume`.
  - **Structured Logging (JSON):** Los logs deben ser estructurados. Truncado duro de `User-Agent` y sanitización (allowlist de charset) para evitar *Log Injection*. La retención de logs con PII debe ser definida como control de seguridad.
- [ ] **Logout Direccional:** El cierre de sesión en un Tenant destruye solo esa sesión local.

## Criterios de Aceptación (DoD++++)
- [ ] **Test de JWT Algorithm Pinning:** Validar que un token con `alg=none`, algoritmo incorrecto o confusión de claves falla localmente *antes* de Redis, devolviendo una respuesta genérica.
- [ ] **Test de Modo Backchannel sin URL:** Aserción de que, bajo `SSO_MODE=backchannel`, tanto el `code` inicial como el JWT final viajan por POST *body* (jamás en *Query String*) y que los headers anti-cache/referer están presentes.
- [ ] **Test Redeem Anti-Mix-Up:** Un intento de redimir un `code` en Central S2S con un `state` incorrecto o desde un tenant no autorizado resulta en un 403 genérico.
- [ ] **Test Anti-BOLA (Claims Service):** El servicio ignora el `tenant_id` del request y usa el extraído del credencial S2S. Un pico de "cache miss" dispara un evento auditable.
- [ ] **Test de HSTS Gating:** Verificar que los *custom domains* de tenants no incluyen directivas `preload` o `includeSubDomains` agresivas que puedan romper infraestructura del cliente.
- [ ] **Test de Invariantes de Cookie:** Validación de cookie `__Host-` con `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/`, y ausencia de `Domain`.
- [ ] **Test de Hardening de Paths:** El endpoint rechaza `//dashboard`, `\dashboard`, `%2f%2f` y otros diferenciales de parsing.
