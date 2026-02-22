---
description: Plan de ejecución detallado para la Fase 2 (Sistema de Diseño, UI Base & i18n)
---

# Fase 2 — Sistema de Diseño, UI Base & i18n (Plan de Ejecución)

**Objetivo principal:** Establecer la arquitectura frontend escalable basada en React 19, Inertia.js v2 y Tailwind CSS v4, garantizando una base de componentes robusta (shadcn/ui), un enrutamiento eficiente y pruebas de integración E2E con Playwright.

## 1. Configuración del Entorno Frontend
- [ ] **Package Manager Único:** `npm` es el único gestor permitido.
  - CI debe fallar explícitamente si existe `pnpm-lock.yaml` o `yarn.lock`.
  - CI debe ejecutar `npm ci` para garantizar reproducibilidad (sin `--force`).
- [ ] Verificar e imponer React 19 como estándar absoluto. Fijar versiones exactas de `react` y `react-dom` en `package.json`.
  - CI debe ejecutar `npm ls react react-dom --json` y recolectar todas las versiones resueltas en el árbol. **Criterio de duplicado simétrico:** Falla si `set(versionsReact)` o `set(versionsReactDom)` tiene un tamaño mayor a 1 (es decir, detecta versiones distintas dentro del mismo paquete). Múltiples nodos deduplicados a la misma versión 19.x exacta son válidos.
- [ ] Requisito de tooling: Imponer `engines: { "node": ">=20" }` en `package.json`.
  - CI debe ejecutar un script que parsee la versión de Node (ej. validando semver major `process.versions.node.split('.')[0] >= 20`) antes de cualquier comando `npm` para fallar "hard" y evitar bypasses locales con `--force`.

## 2. Sistema de Componentes y Diseño (shadcn/ui)
- [ ] Inicializar y configurar `shadcn/ui`. Versionar `components.json` mediante PR dedicado.
- [ ] **Política Estricta de shadcn/ui:** shadcn genera código, no es una dependencia.
  - Solo se permite el registry default. **Enforcement:** Check en CI que falla si `components.json` incluye `registries` de terceros.
  - El check de CI también validará el resto de claves contra un *schema versionado* (allowlist) para evitar roturas falsas cuando shadcn upstream agregue keys nuevas legítimas.
  - Fijar carpeta estándar (`resources/js/components/ui`).
  - Fallback no-AI: La instalación CLI estándar (`npx shadcn@latest add ...`) es la fuente de verdad.
  - Implementar lint: Prohibido editar primitives sin pasar por un wrapper interno.

## 3. Arquitectura de Rutas y Navegación (Inertia.js)
- [ ] Configurar resolve de páginas en Inertia (`resources/js/app.tsx`).
  - **Doctrina de Splitting:** Bundle único por defecto.
  - **Presupuesto de Bundle:** El JS inicial ≤ 300KB (gzip). **Método:** CI ejecuta build de producción, lee el manifest, encuentra el `isEntry: true` y suma el gzip exacto (vía `zlib`) de ese entry + **todos sus imports transitivos/preloaded (JS)**. El script debe fallar con un error explicativo (ej. "manifest shape changed") si no encuentra el entry o el array de imports, evitando verdes falsos por cambios en Vite. Si el límite real se rompe justificadamente, se ajusta el budget con PR.
- [ ] Implementar sistema de layouts persistentes separados (`TenantLayout` y `CentralLayout` sin estado global compartido).
- [ ] Configurar `HandleInertiaRequests`.
  - **Presupuesto de Payload (Shared Props):** ≤ 15KB en bytes reales. **Método:** CI hace request HTTP forzando `X-Inertia: true` contra 3 rutas canónicas: `/` (Central Login), `/tenant/dashboard`, y `/tenant/settings`. Evalúa los bytes reales vía `Buffer.byteLength(JSON.stringify(page.props), 'utf8')` comprobando que no exceda el límite.
  - El test debe fallar si detecta *keys prohibidas* (ej. `translationsAll`, `routesAll`) garantizando que solo viajan shared keys de un allowlist estricto.
  - *Data pesada:* Usar Inertia v2 **Deferred Props** o endpoints cacheados.

## 4. Internacionalización (i18n)
- [ ] **Source of Truth:** Laravel es la única fuente. El frontend consume exclusivamente archivos `lang/*.json` (JSON translations exportados).
- [ ] Integrar `react-i18next`.
- [ ] Precedencia de idioma: `?lang=` > `cookie` > `perfil usuario` > `default app`.
  - **Enforcement:** Validar el parámetro `?lang=` contra una estricta *allowlist* de locales.
  - **Política de Cookie Locale:** El idioma es aislado por Tenant. La cookie debe ser estrictamente *host-only* (sin atributo `Domain` explícito que la expanda al dominio padre). **Atributos requeridos:** `Path=/; SameSite=Lax; Secure` (Secure solo se inyecta en producción o request HTTPS explícito).
- [ ] **Estrategia de Carga (Core vs Deferred) y Fail-Fast:**
  - El diccionario core (errores, nav, acciones comunes) se inyecta en el payload base (≤15KB). CI debe validar que el `coreDictionary` esté presente en las 3 rutas canónicas.
  - Regla estricta de FOUC y Deadlock: La app React/Inertia no se monta hasta que `coreDictionary` esté en memoria e `i18next` inicializado. Si el backend no envía el core por error, el bootstrap debe hacer un *fail-fast* renderizando una pantalla de error explícita en lugar de una vista en blanco.
  - Los diccionarios por página se inyectan vía Deferred Props (v2), requiriendo obligatoriamente un componente `<Deferred fallback={...}>`.

## 5. Pruebas End-to-End (E2E) y Navegación
- [ ] **Estándar E2E Único:** Configurar Playwright (JS-first).
- [ ] **Multihost & DB Strategy:**
  - Configurar `projects` separados en Playwright para Central y Tenant.
  - **DNS/Hosts en CI:** Inyectar los hosts exclusivamente mediante `sudo tee -a /etc/hosts` en el runner para que `central.test` y `tenant.central.test` resuelvan a `127.0.0.1`. (El comodín `*.localhost` queda solo como conveniencia para desarrollo local, no para CI).
  - Configurar `webServer` nativo de Playwright (con `url`, `port`, `timeout` explícitos y `reuseExistingServer: !process.env.CI`).
  - **Aislamiento DB y Paralelismo:** Ejecutar `artisan migrate:fresh --seed` en CI. Limitar a `workers: 1` por defecto garantizando determinismo.
- [ ] Test E2E de Persistencia de Layout: Validar estado observable real (ej. abrir sidebar).
- [ ] Test E2E de i18n: Navegar y refrescar validando aislamiento del locale respetando la política de host-only cookie.

## Criterios de Aceptación (DoD)
- CI ejecuta un script estricto validando Node 20+ (parseo de semver major) previo a `npm ci` sin `--force` (que falla si hay locks de yarn/pnpm).
- Análisis JSON de `npm ls react react-dom` falla si `set(versionsReact)` o `set(versionsReactDom)` es > 1.
- CI valida que `components.json` usa registry default y llaves conocidas del schema.
- Test de build localiza `isEntry` de Vite y suma imports transitivos ≤ 300KB (gzip), fallando con error explícito si cambia la estructura del manifest.
- Test de runtime forza `X-Inertia: true` en 3 rutas canónicas, validando que el `Buffer.byteLength` de `page.props` es ≤ 15KB y no contiene *keys prohibidas*.
- Traducciones usan JSON. La cookie locale es host-only con Path/SameSite/Secure definidos. La app falla explícitamente en bootstrap si el core dictionary está ausente.
- Tests E2E en Playwright resuelven hosts obligatoriamente vía `/etc/hosts` en CI, usando webServer nativo y 1 worker para garantizar aislamiento de BD.
