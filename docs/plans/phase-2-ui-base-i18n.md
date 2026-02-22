---
description: Plan de ejecución detallado para la Fase 2 (Sistema de Diseño, UI Base & i18n)
---

# Fase 2 — Sistema de Diseño, UI Base & i18n (Plan de Ejecución)

**Objetivo principal:** Establecer la arquitectura frontend escalable basada en React 19, Inertia.js v2 y Tailwind CSS v4, garantizando una base de componentes robusta (shadcn/ui), un enrutamiento eficiente y pruebas de integración E2E con Playwright.

## 1. Configuración del Entorno Frontend
- [ ] Verificar e imponer React 19 como estándar absoluto. Cualquier starter kit que instale React 18 debe ser sobreescrito en el primer commit y validado vía CI (`npm ls react` / `pnpm why react`).
- [ ] Requisito de tooling: Imponer Node 20+ en el entorno local y de CI.
- [ ] Actualizar y configurar Vite (`vite.config.ts`) para soportar React e Inertia de forma óptima.
- [ ] Configurar Tailwind CSS v4. **Decisión de Producto:** El baseline de navegadores soportados es estrictamente Safari 16.4+, Chrome 111+ y Firefox 128+. No se dará soporte a legacy browsers.
- [ ] Asegurar que `tsconfig.json` tenga habilitado el modo `strict` para garantizar type safety en todo el frontend.

## 2. Sistema de Componentes y Diseño (shadcn/ui)
- [ ] Inicializar y configurar `shadcn/ui` en el proyecto mediante MCP.
- [ ] **Política Estricta de shadcn/ui:** shadcn genera código, no es una dependencia normal. 
  - Fijar una carpeta estándar (`resources/js/components/ui`).
  - Implementar una regla/lint: No se editan los primitives de shadcn sin pasar por un wrapper interno.
  - Los upgrades o regeneración de componentes requieren un PR dedicado exclusivo.
- [ ] Integrar componentes base esenciales (Button, Input, Card, Modal, Dropdown) asegurando accesibilidad (a11y).
- [ ] Crear layouts base separados: `TenantLayout` y `CentralLayout`. **Regla de Aislamiento:** Estos layouts NO comparten estado global; todo estado compartido debe venir de props globales o stores con namespace por contexto.

## 3. Arquitectura de Rutas y Navegación (Inertia.js)
- [ ] Configurar el resolve de páginas de Inertia en `resources/js/app.tsx`.
  - **Doctrina de Splitting:** Bundle único por defecto. Habilitar *code splitting* solo de forma *opt-in* cuando el bundle supere un presupuesto de tamaño X o el volumen de páginas lo exija.
- [ ] Implementar el sistema de layouts persistentes en Inertia.
- [ ] Configurar `HandleInertiaRequests`.
  - **Presupuesto de Shared Props:** Definir tamaño máximo permitido.
  - Obligar a que datos grandes (traducciones, diccionarios) sean pasados con *lazy evaluation* (closures) o servidos por un endpoint cacheado y tenant-aware para evitar fugas de memoria y bloat en el payload.
- [ ] Implementar indicadores de progreso de navegación.

## 4. Internacionalización (i18n)
- [ ] **Source of Truth:** Laravel es la única fuente de la verdad para el locale. El frontend consume estrictamente del mismo set.
- [ ] Integrar `react-i18next`.
- [ ] Definir cadena de precedencia estricta para el idioma: `?lang=` > `cookie` > `perfil usuario` > `default app`.
- [ ] Sincronizar traducciones al frontend asegurando no saturar las props globales de Inertia (usar closures u obtener asíncronamente vía endpoint si el JSON es muy grande).

## 5. Pruebas End-to-End (E2E) y Navegación
- [ ] **Estándar E2E Único:** Configurar **Playwright** como única herramienta E2E (JS-first, sólido para flujos SPA).
- [ ] Definir el setup de Playwright para CI, incluyendo servidor de pruebas, seeders, y resolución de hosts (`tenant` vs `central`).
- [ ] Escribir tests E2E para flujos básicos de navegación asíncrona.
- [ ] **Test E2E de Persistencia de Layout:** Validar estado observable real (ej. abrir sidebar, navegar, y verificar que sigue abierto; el DoD no debe dar un falso positivo si la vista se remonta).
- [ ] **Test E2E de i18n:** Cambiar idioma, navegar por 3 páginas distintas, refrescar el navegador (F5), y verificar que la persistencia del locale se mantiene.

## Criterios de Aceptación (DoD)
- CI verifica explícitamente el uso de React 19 (`npm ls react`).
- Política de "Bundle único por defecto" aplicada en Vite/Inertia.
- Los componentes de `shadcn/ui` están instalados en el directorio aislado sin ediciones directas informales.
- El middleware de Inertia (`HandleInertiaRequests`) no excede un payload base predefinido (testeado/validado de que no hay leak de data gigante en cada request).
- La resolución de idiomas sigue la cadena de precedencia y las traducciones son coherentes entre Backend y Frontend.
- Los tests en Playwright (incluyendo persistencia de layout e i18n cross-navigation) pasan en CI.
