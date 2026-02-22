# Multi-Tenant Guardrails & Architecture

## 1. Scope y Seguridad
- **BelongsToTenant:** Todo Modelo de datos de negocio DEBE aplicar el scope global de Tenant (BelongsToTenant) por defecto.
- **Bypass de Scope:** PROHIBIDO usar `withoutGlobalScopes()` o queries de `DB::table()` en crudo en controladores o comandos de negocio regulares. Solo usar `SystemContext::execute()` encapsulado en `try/finally` exigiendo permiso de superadmin.

## 2. Mutación de RBAC (Teams)
- **Aislamiento Estricto:** Está estrictamente prohibido usar la API nativa de Spatie `syncRoles()`, `syncPermissions()` y `roles()->detach()`.
- **Acción Obligatoria:** Toda mutación de roles/permisos debe pasar por la acción dedicada de asignación (`AssignRolesToMember`), filtrando internamente pivotes por `team_id = tenant_id` explícitamente en el Query Builder.
- **Cache Lifecycle:** Al cambiar contexto de Tenant, establecer el prefijo en el store dedicado de caché (`permission`) y ejecutar `app(PermissionRegistrar::class)->initializeCache()`. Restaurar contexto neutral en `finally`.

## 3. Estado en Memoria
- **Prevención de Fugas:** Prohibido el uso de `Cache::memo()` en flujos tenant.
- **Octane / Workers:** Prohibido el uso de singletons o propiedades estáticas para almacenar el `tenant_id` actual sin un reset garantizado al finalizar el request/job.

## 4. Auditoría
- **Consultas Forenses (activity_log):** Toda consulta debe realizarse obligatoriamente a través del repositorio forense. Debe incluir un rango de fechas explícito en la columna `created_at` (Partition Key) para garantizar el pruning de Postgres.
