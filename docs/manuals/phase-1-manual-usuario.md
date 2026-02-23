---
description: Manual de usuario de la Fase 1 (Fundación & Multi-Tenancy)
---

# Manual de Usuario — Fase 1 (Fundación & Multi-Tenancy)

Este manual explica cómo operar y verificar la base multi-tenant implementada en Fase 1 en entorno local.

## 1) Prerrequisitos

- PHP 8.4+
- Composer
- Node 20+
- npm
- PostgreSQL local accesible (en este proyecto: puerto `5434`)

## 2) Configuración local esperada

En `.env`:

- `DB_CONNECTION=pgsql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=5434`
- `DB_DATABASE=gsaasbp_central`
- `DB_USERNAME=postgres`
- `DB_PASSWORD=...` (clave local real)
- `CENTRAL_DOMAINS=localhost,127.0.0.1`
- `PERMISSION_CACHE_STORE=array` (dev/test)

## 3) Primer arranque

1. Instalar dependencias:
   - `composer install`
   - `npm ci`
2. Migrar y poblar base:
   - `php artisan migrate:fresh --seed`
3. Limpiar cache de framework:
   - `php artisan optimize:clear`
4. Levantar entorno de desarrollo:
   - `composer run dev`

## 4) Cómo validar que Tenancy está bien

## 4.1 Rutas centrales

- Abrir:
  - `http://localhost:8000`
  - `http://127.0.0.1:8000`
- Ambas deben mostrar la app central (sin excepción de tenant).

## 4.2 Navegación de cuenta (Settings)

- Iniciar sesión.
- Ir a Dashboard y abrir menú de usuario -> Settings.
- Debe navegar correctamente a:
  - `/settings/profile`
  - `/settings/password`
  - `/settings/two-factor`
  - `/settings/appearance`

## 4.3 Verificación automática

- Ejecutar tests:
  - `php artisan test`
- El bloque de `tests/Feature/Tenancy/*` debe pasar.

## 5) Errores comunes y resolución rápida

## Error A: `TenantCouldNotBeIdentifiedOnDomainException` en `127.0.0.1`

**Causa:** `127.0.0.1` no está en `CENTRAL_DOMAINS`.

**Solución:**
- Ajustar `.env` con `CENTRAL_DOMAINS=localhost,127.0.0.1`
- `php artisan optimize:clear`

## Error B: Settings no abre y aparece `AxiosError: Network Error`

**Causa:** URLs absolutas cruzadas de host (`localhost` vs `127.0.0.1`) en artifacts generados.

**Solución:**
1. Regenerar rutas/actions Wayfinder con normalización:
   - `CENTRAL_DOMAINS=localhost php artisan wayfinder:generate --with-form && node scripts/normalize-wayfinder-urls.mjs`
2. Reiniciar `composer run dev`.

## Error C: fallo de conexión PostgreSQL

**Causa:** credenciales/puerto incorrectos en `.env`.

**Solución:**
- Confirmar `DB_PORT=5434` y clave real local (`DB_PASSWORD`).
- Reintentar `php artisan migrate:fresh --seed`.

## 6) Operación diaria recomendada

1. `php artisan optimize:clear`
2. `composer run dev`
3. Trabajar con host consistente (recomendado: `127.0.0.1:8000` o `localhost:8000`, pero no alternar durante una sesión de pruebas manuales).

## 7) Alcance de Fase 1 para usuarios del equipo

Esta fase entrega la fundación técnica. No introduce todavía:

- SSO transaccional completo (Fase 3),
- panel central avanzado (fases posteriores),
- módulos de negocio específicos.

Sí entrega una base segura para construir esas capas con aislamiento tenant comprobado.
