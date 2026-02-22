# AI Agents Guardrails & Instructions

## 1. Reglas Globales (Innegociables)
- **Modificaciones de Dependencias:** Todo cambio en `composer.json` o `package.json` DEBE ir acompañado de la actualización de `composer.lock` y `package-lock.json`. No se permiten "pre-releases" (`beta`, `rc`, `next`) en la rama `main`.
- **Autorización Gate-based:** El sistema usa la API nativa de Gate (`can`, `@can`, `Gate::allows`). Está ESTRICTAMENTE PROHIBIDO usar la API directa de Spatie (ej. `hasRole`, `hasPermissionTo`) en código de aplicación (solo en tests).
- **Prohibición de Inline Authorization:** Está prohibido usar `Gate::allowIf` o `Gate::denyIf`. Si se requiere autorización, usar Policies registradas.
- **Súper Administrador:** El Superadmin se gestiona vía `Gate::before`. Está prohibido usar `Gate::after` para intentar negar accesos.

## 2. Convenciones de Código
- **Tipado Estricto:** PHP 8.4+ con `declare(strict_types=1)`. TypeScript en estricto en el Frontend.
- **Formateo:** Seguir estándar de Laravel (Pint) y Prettier para Frontend.

## 3. Arquitectura SaaS (Tenancy)
Para reglas específicas del entorno Multi-Tenant, refiérase siempre a `app/AGENTS.md` y al `Master Plan v11`.
