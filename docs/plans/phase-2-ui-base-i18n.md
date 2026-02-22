---
description: Plan de ejecución detallado para la Fase 2 (Sistema de Diseño, UI Base & i18n)
---

# Fase 2 — Sistema de Diseño, UI Base & i18n (Plan de Ejecución)

**Objetivo principal:** Establecer la arquitectura frontend escalable basada en React 19, Inertia.js v2 y Tailwind CSS v4, garantizando una base de componentes robusta (shadcn/ui), un enrutamiento eficiente y pruebas de integración E2E con Playwright.

## 1. Configuración del Entorno Frontend
- [ ] **Package Manager Único:** `npm` es el único gestor permitido.
  - CI debe fallar explícitamente si existe `pnpm-lock.yaml` o `yarn.lock`.
  - CI debe ejecutar `npm ci` para garantizar reproducibilidad.
- [ ] Verificar e imponer React 19 como estándar absoluto. Fijar versiones exactas de `react` y `react-dom` en `package.json`.
  - CI debe ejecutar `npm ls react react-dom` (validar que ambas sean 19.x) y fallar si hay múltiples versiones en el árbol (duplicados).
- [ ] Requisito de tooling: Imponer `engines: { "node": ">=20" }` en `package.json`.
  - CI debe ejecutar `npm config set engine-strict true` (o validar `node -v`) antes de instalar, para forzar el enforcement.
- [ ] Actualizar y configurar Vite (`vite.config.ts`).
- [ ] Configurar Tailwind CSS v4. **Decisión de Producto:** Baseline de navegadores Safari 16.4+, Chrome 111+ y Firefox 128+.
  - *Nota contractual (Plan B):* Si se exige soporte legacy estricto a futuro, se documenta y se congela en Tailwind v3.4.
- [ ] Asegurar que `tsconfig.json` tenga habilitado el modo `strict`.

## 2. Sistema de Componentes y Diseño (shadcn/ui)
- [ ] Inicializar y configurar `shadcn/ui`. Versionar `components.json` mediante PR dedicado.
- [ ] **Política Estricta de shadcn/ui:** shadcn genera código, no es una dependencia.
  - Solo se permite el registry default. **Enforcement:** Check en CI que falla si `components.json` incluye registries de terceros o claves no aprobadas.
  - Fijar carpeta estándar (`resources/js/components/ui`).
  - Fallback no-AI: La instalación CLI estándar (`npx shadcn@latest add ...`) es la fuente de verdad.
  - Implementar lint: Prohibido editar primitives sin pasar por un wrapper interno.

## 3. Arquitectura de Rutas y Navegación (Inertia.js)
- [ ] Configurar resolve de páginas en Inertia (`resources/js/app.tsx`).
  - **Doctrina de Splitting:** Bundle único por defecto.
  - **Presupuesto de Bundle:** El JS inicial ≤ 300KB (gzip). **Método:** CI ejecuta build de producción, lee el entry JS del manifest y calcula su gzip exacto con `zlib` de Node. Falla si lo supera. Si el límite se rompe por deps base justificados, ajustar budget con PR.
- [ ] Implementar sistema de layouts persistentes separados (`TenantLayout` y `CentralLayout` sin estado global compartido).
- [ ] Configurar `HandleInertiaRequests`.
  - **Presupuesto de Payload (Shared Props):** ≤ 15KB decodificado. **Método:** Un test debe pegarle a rutas reales (ej. `/tenant/dashboard`) y medir el tamaño del *page object* final de Inertia en la respuesta HTTP, no directamente desde la función `share()`.
  - *Data pesada:* Usar Inertia v2 **Deferred Props** o endpoints cacheados.

## 4. Internacionalización (i18n)
- [ ] **Source of Truth:** Laravel es la única fuente. El frontend consume exclusivamente archivos `lang/*.json` (JSON translations exportados).
- [ ] Integrar `react-i18next`.
- [ ] Precedencia de idioma: `?lang=` > `cookie` > `perfil usuario` > `default app`.
  - **Enforcement:** Validar el parámetro `?lang=` contra una estricta *allowlist* de locales para evitar inyecciones e invalidaciones del frontend.
- [ ] Sincronizar diccionarios masivos vía Deferred Props (v2) para no asfixiar el payload base.

## 5. Pruebas End-to-End (E2E) y Navegación
- [ ] **Estándar E2E Único:** Configurar Playwright (JS-first).
- [ ] **Multihost & DB Strategy:**
  - Configurar `projects` separados en Playwright para Central y Tenant.
  - Configurar `webServer` nativo de Playwright (con `url`, `port`, `timeout` explícitos y `reuseExistingServer: !process.env.CI`), evitando flakiness por herramientas de wait externas.
  - **Aislamiento DB y Paralelismo:** Ejecutar `artisan migrate:fresh --seed` en CI. Limitar a `workers: 1` por defecto. Si luego se habilita paralelismo, es obligatorio provisionar DB por worker.
- [ ] Test E2E de Persistencia de Layout: Validar estado observable real (ej. abrir sidebar).
- [ ] Test E2E de i18n: Navegar y refrescar validando persistencia del locale.

## Criterios de Aceptación (DoD)
- CI ejecuta `npm ci` con `engine-strict=true` y falla si hay `yarn.lock`/`pnpm-lock.yaml`.
- CI falla si `npm ls react react-dom` detecta discrepancias de versión o duplicados.
- CI valida que `components.json` solo apunta al registry default.
- Test de build verifica bundle JS entry ≤ 300KB (gzip vía `zlib`).
- Test de runtime en request real verifica que payload base de Inertia es ≤ 15KB.
- Traducciones cargan estrictamente desde JSON y el locale de query string tiene un allowlist.
- Tests E2E en Playwright utilizan webServer nativo y 1 worker (o DB por worker) garantizando determinismo.
