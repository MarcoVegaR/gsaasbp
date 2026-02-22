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
  - CI debe ejecutar `npm ls react react-dom --json` y parsear el árbol. **Criterio de duplicado:** Es válido tener múltiples nodos en el árbol si y solo si la versión resuelta es idéntica (19.x). CI falla si `set(versionsReact)` o `set(versionsReactDom)` tiene un tamaño mayor a 1.
- [ ] Requisito de tooling: Imponer `engines: { "node": ">=20" }` en `package.json`.
  - CI debe ejecutar un script explícito al inicio (ej. validar `process.versions.node >= 20`) antes de cualquier comando `npm` para fallar "hard" y evitar bypasses locales con `--force`.
- [ ] Actualizar y configurar Vite (`vite.config.ts`).
- [ ] Configurar Tailwind CSS v4. **Decisión de Producto:** Baseline de navegadores Safari 16.4+, Chrome 111+ y Firefox 128+.
  - *Nota contractual (Plan B):* Si se exige soporte legacy estricto a futuro, se documenta y se congela en Tailwind v3.4.
- [ ] Asegurar que `tsconfig.json` tenga habilitado el modo `strict`.

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
  - **Presupuesto de Bundle:** El JS inicial ≤ 300KB (gzip). **Método:** CI ejecuta build de producción, lee el manifest, encuentra el *entry point* y suma el gzip exacto (vía `zlib`) de ese entry + **todos sus imports transitivos/preloaded**. Falla con error claro si el manifest no tiene la estructura esperada (para no dar falsos verdes). Si se rompe por deps base justificadas, se ajusta el budget con PR.
- [ ] Implementar sistema de layouts persistentes separados (`TenantLayout` y `CentralLayout` sin estado global compartido).
- [ ] Configurar `HandleInertiaRequests`.
  - **Presupuesto de Payload (Shared Props):** ≤ 15KB decodificado. **Método:** CI hace request HTTP forzando el header `X-Inertia: true` contra 3 rutas canónicas: `/` (Central Login), `/tenant/dashboard`, y `/tenant/settings`. Evalúa `JSON.stringify(page.props)` comprobando que su tamaño no exceda el límite.
  - *Data pesada:* Usar Inertia v2 **Deferred Props** o endpoints cacheados.

## 4. Internacionalización (i18n)
- [ ] **Source of Truth:** Laravel es la única fuente. El frontend consume exclusivamente archivos `lang/*.json` (JSON translations exportados).
- [ ] Integrar `react-i18next`.
- [ ] Precedencia de idioma: `?lang=` > `cookie` > `perfil usuario` > `default app`.
  - **Enforcement:** Validar el parámetro `?lang=` contra una estricta *allowlist* de locales.
  - **Política de Cookie Locale:** El idioma es aislado por Tenant. La cookie debe ser *host-only* (sin atributo `Domain` explícito que la expanda al dominio padre), previniendo filtraciones de preferencia entre central y tenants.
- [ ] **Estrategia de Carga (Core vs Deferred):**
  - El diccionario core (errores, nav, acciones comunes) se inyecta en el payload base (≤15KB).
  - Regla estricta de FOUC: La app React/Inertia no se monta hasta que el `coreDictionary` esté en memoria e `i18next` esté inicializado.
  - Los diccionarios por página se inyectan vía Deferred Props (v2), requiriendo obligatoriamente un componente `<Deferred fallback={...}>`.

## 5. Pruebas End-to-End (E2E) y Navegación
- [ ] **Estándar E2E Único:** Configurar Playwright (JS-first).
- [ ] **Multihost & DB Strategy:**
  - Configurar `projects` separados en Playwright para Central y Tenant.
  - **DNS/Hosts en CI:** Inyectar los hosts usando `sudo tee -a /etc/hosts` en el runner (o usar alias `*.localhost` donde soporte resolución nativa) para que `central.test` y `tenant.central.test` resuelvan a `127.0.0.1`.
  - Configurar `webServer` nativo de Playwright (con `url`, `port`, `timeout` explícitos y `reuseExistingServer: !process.env.CI`).
  - **Aislamiento DB y Paralelismo:** Ejecutar `artisan migrate:fresh --seed` en CI. Limitar a `workers: 1` por defecto garantizando determinismo.
- [ ] Test E2E de Persistencia de Layout: Validar estado observable real (ej. abrir sidebar).
- [ ] Test E2E de i18n: Navegar y refrescar validando aislamiento del locale (host-only cookie).

## Criterios de Aceptación (DoD)
- CI ejecuta script de validación Node 20+ previo a `npm ci` (que falla si hay locks de yarn/pnpm).
- Análisis JSON de `npm ls react react-dom` falla si se detectan *diferentes* versiones en el árbol (`size > 1`).
- CI valida que `components.json` usa registry default y llaves conocidas del schema.
- Test de build localiza entry de Vite y suma imports transitivos ≤ 300KB (gzip).
- Test de runtime forza `X-Inertia: true` en Central Login, Tenant Dashboard y Tenant Settings, validando payload ≤ 15KB en cada una.
- Traducciones usan JSON con estrategia Core/Deferred evitando FOUC (app no monta hasta tener el core). La cookie locale es host-only.
- Tests E2E configuran DNS en CI explícitamente (`/etc/hosts`) y usan 1 worker para garantizar aislamiento de BD.
