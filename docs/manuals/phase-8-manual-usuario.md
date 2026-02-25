---
description: Manual de uso del generador de modulos Phase 8
---

# Fase 8 — Manual de Usuario (Module Generator DX)

Este manual explica como usar `make:saas-module` para crear modulos tenant-safe sin romper invariantes de seguridad del boilerplate.

## 1) Que hace el comando

Comando principal:

```bash
php artisan make:saas-module {Name} --schema=path/al/esquema.yml
```

Genera automaticamente:

- Modelo tenant-aware (`BelongsToTenant`).
- Factory tenant-safe con `forTenant(...)`.
- Migracion tenant-aware con constraints/FKs.
- Policy + FormRequests + Controller CRUD.
- Rutas del modulo en `routes/generated/tenant/*.php`.
- Paginas React Inertia (`index`, `form`, `show`).
- Registro en `config/phase8_modules.php`.
- Entradas i18n en `lang/en.json` y `lang/es.json`.

## 2) Estructura minima de esquema

Ejemplo (`module.yml`):

```yaml
name: ContactNote
slug: contact-note
table: tenant_contact_notes
soft_deletes: true
database_driver: pgsql
fields:
  - name: title
    type: string
    unique: true
    audit: true
  - name: body
    type: text
    nullable: true
```

Campos soportados:

- `name`, `slug`, `table`
- `soft_deletes` (`true/false`)
- `database_driver` (usar `pgsql` para soft-delete + unique)
- `fields[]` con `name`, `type`, `nullable`, `unique`, `audit`, `pii`, `secret`
- `indexes[]` y `foreign_keys[]` opcionales

## 3) Reglas de seguridad que aplica por defecto

- Rechaza tags YAML inseguros (ej: `!php/object`).
- Rechaza `unique` sin `tenant_id` como primer elemento.
- Rechaza soft-deletes con unique cuando `database_driver != pgsql`.
- Rechaza colisiones con keywords reservadas.
- Lint estatico de archivos generados para bloquear anti-patrones (`DB::table`, `withoutGlobalScope`, etc).
- Todo el proceso corre con lock global + staging + rollback atomico.

## 4) Flujo recomendado de uso

1. Crear/ajustar schema YAML o JSON.
2. Ejecutar:

   ```bash
   php artisan make:saas-module ContactNote --schema=module.yml
   ```

3. Revisar archivos generados.
4. Ejecutar certificacion minima de la fase:

   ```bash
   php artisan test tests/Feature/Phase8/Phase8ContractsTest.php --stop-on-failure
   npm run types
   npm run build
   ```

5. Ejecutar certificacion completa del proyecto (si el cambio toca capas transversales):

   ```bash
   php artisan test
   node scripts/ci/00_guardrails.mjs
   node scripts/ci/10_check_react_tree.mjs
   node scripts/ci/20_check_shadcn_components_json.mjs
   VITE_ENTRY_KEY=resources/js/app.tsx node scripts/ci/30_check_vite_initial_js_budget.mjs
   node scripts/ci/40_check_sso_csp_contract.mjs
   node scripts/ci/50_check_sso_no_body_logs.mjs
   CI=1 PLAYWRIGHT_PORT=8010 npx playwright test --workers=1 --retries=0
   ```

## 5) Errores esperados y significado

- `custom YAML tags are not allowed`: schema inseguro.
- `must start with tenant_id`: indice unique sin aislamiento tenant.
- `only supported for PostgreSQL`: combinacion soft-delete + unique fuera de pgsql.
- `Timeout waiting for generator lock`: otra ejecucion del generador mantiene el lock.
- `Refusing to overwrite existing generated file`: el modulo intenta pisar archivos ya existentes.

## 6) Notas operativas

- El sidebar tenant muestra automaticamente los modulos registrados en `config/phase8_modules.php`.
- Las rutas generadas se cargan de forma dinamica desde `routes/generated/tenant/*.php`.
- El binding tenant-aware de `Route::tenantResource(...)` devuelve 404 para IDs de otro tenant (anti-BOLA).

## 7) Resultado esperado

Al finalizar, el modulo queda listo para evolucion funcional sin perder:

- aislamiento multi-tenant,
- autorizacion por policy/gate,
- consistencia forense/auditoria,
- contratos de performance y seguridad del boilerplate.
