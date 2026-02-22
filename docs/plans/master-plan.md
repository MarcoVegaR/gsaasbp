# SaaS Generic Boilerplate — Master Plan v11 (Final Absoluto)

Este documento es el **Plan General de Construcción** para el Boilerplate SaaS multi-tenant. Contiene la visión del proyecto, la arquitectura, el stack tecnológico, las **13 Reglas de Oro innegociables** (blindadas tras auditoría extrema de riesgos de producción) y el roadmap ordenado de 8 fases.

---

## 1. Visión del Proyecto

Estamos construyendo una **fundación de infraestructura SaaS** diseñada para abstraer la complejidad del multi-tenancy, autenticación, control de acceso y auditoría, permitiendo que el equipo de producto se enfoque exclusivamente en construir módulos de negocio.

### Promesa Técnica
- **Seguridad Zero-Trust:** Ningún tenant confía en otro. Permisos explícitos evaluados nativamente.
- **Escalabilidad sin fricción:** Shared DB por defecto, con soporte híbrido a Dedicated DB.
- **Autorización Inquebrantable:** Súper-administración "Zero-Spatie" e inmutable.
- **Gobernanza "Latest":** Uso de versiones "latest stable" gobernadas estrictamente por CI.

---

## 2. Stack Tecnológico y Librerías Requeridas

*Stack oficial y único. Se prohíbe la introducción de frameworks paralelos (ej. Filament, Livewire).*

### Capa Backend
- **Framework:** Laravel 12 (PHP 8.4)
- **Base de Datos:** PostgreSQL 16+ (Soporte nativo para partitioning `RANGE`)
- **Infra Operativa:** Redis (Sesiones, Colas, Locks atómicos)
- **Multi-tenancy:** `stancl/tenancy` v3.9+ (Híbrido)
- **RBAC:** `spatie/laravel-permission` (Modo *Teams* con `team_id` = `tenant_id`)
- **Auditoría:** `spatie/laravel-activitylog` (Extendido con `tenant_id` y particionado)
- **Observabilidad:** Laravel Nightwatch + Context API nativa (Laravel 12+)

### Capa Frontend (Tenant & Central)
- **Core:** React 19 + Inertia.js v2 + TypeScript (Strict)
- **Estilos:** Tailwind CSS v4 *(Browser baseline: Safari 16.4+, Chrome 111+, Firefox 128+)*
- **UI Components:** `shadcn/ui`

### Capa Calidad / DX
- **Testing:** Pest PHP
- **Análisis estático:** PHPStan (Level 6) + Larastan
- **Linting & Formateo:** Pint (PHP) + ESLint/Prettier

---

## 3. Topología y Ciclo de Petición

### El Modelo Súper Admin & RBAC
- El **Súper Administrador** NO depende de Spatie. Es una identidad estática en la BD Central.
- El RBAC de tenants (Spatie) asume siempre que **`team_id` = `tenant_id`**.

### Flujo HTTP (Tenant)
```text
Request
  → Identify Tenant (Domain/Subdomain)
  → Authenticate
  → Ensure Membership (Valida shadow table idempotente `tenant_users`)
  → TenantPermissionMiddleware (Setea prefijo Spatie → initializeCache() → resetea relaciones)
  → Controller → Service → Model (Protegido por BelongsToTenant)
```

---

## 4. Las 13 Reglas de Oro Arquitectónicas

### Bloque A: Gobernanza y Dependencias
1. **Gobernanza Estricta y Matriz de Compatibilidad:** `composer.lock` y `package-lock.json` versionados obligatoriamente. Deploys exigen builds reproducibles (`npm ci`, `composer install`). Todo PR de upgrade debe pasar tests de e2e y aislamiento completo.
2. **Cero Pre-Releases en Main:** Prohibido usar `next`, `canary`, `beta`, o `rc` en dependencias base.

### Bloque B: Seguridad y Privilegios
3. **Súper Admin Inyectado (Zero-Spatie con Denylist Estricto):**
   - Existe físicamente en `central_admins(user_id)` (DB central), sin endpoints CRUD. Se intercepta mediante `Gate::before()`.
   - **Excepción (Denylist):** Para *Hard Business Constraints*, el Súper Admin **no se auto-aprueba (retorna `null`)**, delegando a la Policy. El denylist solo puede contener abilities de un catálogo central (enum/lista) versionado en código. El catálogo se valida en CI: `denylist ⊆ catálogo` y `catálogo ⊆ policies/gates registrados` (previene excepciones fantasma por typos).
   - **Anti-patrón Prohibido:** Prohibido usar `Gate::after` como mecanismo de *deny* (Laravel no permite revertir un `true/false` previo desde *after*).
4. **API de Autorización 100% Gate-based:** Prohibido usar la API directa de Spatie (`hasRole`, `hasPermissionTo`) en código de aplicación.
   - **CI Lint:** Prohibido el uso de `Gate::allowIf` y `Gate::denyIf` por evadir hooks. El uso de `$this->authorize()` está permitido **solo** si apunta a Policies registradas.
5. **SSO OTT Atómico y Verificado:** Salto a tenant basado en consumo atómico Redis (`GETDEL` o Lua) con TTL < 30s. Validación de Allow-list exige parseo y normalización fuerte de la URL destino.
   - *Test Contract:* Payloads "nasty" de normalización (ej. `//evil.com`, `example.com.`, punycode) deben fallar y ser rechazados estruendosamente.
6. **Frontera Central de Identidad (IdP Global):** La DB central maneja `users`. Las DBs dedicadas usan una *shadow table* (`tenant_users`) actualizada por `UPSERT` (cero PII, idempotente).

### Bloque C: Multi-Tenancy y Estado
7. **Aislamiento Físico de Estado (Uniques y Caché):** En modo Single-DB, todo índice único tenant DEBE ser compuesto: `$table->unique(['tenant_id', 'slug'])`. En Dedicated DB se respeta por uniformidad.
   - **In-Memory Leak Prevention:** Prohibido el uso de `Cache::memo()` en flujos tenant y prohibido el estado mutable tenant-scoped en singletons/estáticos. Cualquier caché en memoria debe ser estrictamente request-scoped y neutralizado.
8. **Bypass Estricto de Scope (Prohibido DB::table):** Prohibido usar `withoutGlobalScopes()` o queries SQL crudos para data tenant. Bypass para sistema requiere `SystemContext::execute()` (con `try/finally`) exigiendo validación de superadmin.
9. **Mutación RBAC Aislada y Lifecycle de Caché (Teams):**
   - **Store Dedicado:** El store *permission* se configura vía `config/permission.php` (`cache.store=permission`). El prefix por tenant se aplica **solo** a ese store (no al `cache.prefix` global).
   - **Lifecycle Reset Obligatorio:** Todo pipeline (Request/Job/Octane) debe setear prefix/team_id al inicio y garantizar la restauración a un **estado neutral oficial** (ej. `team_id=null`, prefijo vacío/central) en un bloque `finally`.
   - **Mutación Restringida:** CI lint prohibirá el uso de `syncRoles()`, `syncPermissions()`, y cualquier mutación a la BD (ej. `roles()->detach()`, `DB::table('model_has_roles')`) salvo dentro de la acción oficial `AssignRolesToMember`.

> **Contrato de Test Pest (Aislamiento Extremo de Roles):**
> ```php
> test('mutar roles en Tenant A no afecta al Tenant B (evita bug de syncRoles)', function () {
>     $initialPivotsB = DB::table('model_has_roles')
>         ->where('team_id', $tenantB->id)
>         ->where('model_id', $user->id)
>         ->where('model_type', get_class($user))
>         ->count();
>
>     app(AssignRolesToMember::class)->execute($tenantA->id, $user->id, []);
>
>     setPermissionsTeamId($tenantA->id);
>     unset($user->roles, $user->permissions);
>     app(PermissionRegistrar::class)->initializeCache();
>     expect($user->can('edit-posts'))->toBeFalse();
>
>     setPermissionsTeamId($tenantB->id);
>     unset($user->roles, $user->permissions);
>     app(PermissionRegistrar::class)->initializeCache();
>     expect($user->can('publish-posts'))->toBeTrue();
>
>     // Anti-regression assert quirúrgico
>     $finalPivotsB = DB::table('model_has_roles')
>         ->where('team_id', $tenantB->id)
>         ->where('model_id', $user->id)
>         ->where('model_type', get_class($user))
>         ->count();
>     expect($finalPivotsB)->toBe($initialPivotsB);
> });
> ```

10. **Auditoría Particionada y Runbook Serializado:** `activity_log` particionada nativamente por `RANGE(created_at)`.
    - **Esquema Estricto:** La tabla `activity_log` **no tendrá default partition** (las particiones se crean por adelantado) para permitir operaciones concurrentes.
    - **Runbook de Retención (Scheduler Mutex):** Procesar máximo **1 partición pending detach** por tabla, protegido por un lock del scheduler (mutex) para no encolar bloqueos. El scheduler ejecuta el detach en **autocommit** (fuera de transacciones).
    - **Flujo Operativo:** `DETACH ... CONCURRENTLY` → esperar → `FINALIZE` → `DROP` asíncrono. Si el *detach* concurrente falla, reintentar *FINALIZE* registrando incidente; no lanzar un segundo detach hasta cerrar el anterior.

### Bloque D: Eventos, Storage y DX
11. **Contexto RBAC en Colas:** Todo Job rehidrata el tenant, setea prefix en el store dedicado, ejecuta `initializeCache()`, y asegura la reversión al estado neutral al finalizar el worker.
12. **Aislamiento de Storage Nativo:** Archivos subidos mutan al disco dinámico con prefijo `tenant/{tenant_id}/`.
13. **Broadcasting Autorizado Estricto:** `/broadcasting/auth` fuerza la inicialización de Tenancy, valida guard y comprueba el tenant actual contra el prefijo del canal generado por el *ChannelNameBuilder*.

---

## 5. Roadmap de Ejecución (8 Fases)

| Fase | Foco Principal | Gate de Salida Temprano (Aprobación estricta) |
|---|---|---|
| **1** | **Fundación, Súper Admin & CI** | `stancl/tenancy` + `central_admins` + Linters anti-`allowIf`/`detach` + Tests Gate::before. |
| **2** | **Sistema de Diseño, UI Base & i18n** | Componentes Inertia y tests e2e de navegación asíncrona pasando. |
| **3** | **Auth, SSO Transaccional & Lifecycle** | OTT atómico validado con payloads negativos (normalización extrema de hostnames). |
| **4** | **RBAC Tenant-Scoped** | Acción `AssignRolesToMember` probada contra aserciones de conteo de pivotes (A/B Test). |
| **5** | **Infraestructura Operativa (Auditoría)** | PG Partitions y Mutex en Scheduler. API Forense única implementada. |
| **6** | **Notificaciones & Eventos (Echo)** | Auth de Echo bloqueado para cross-tenant listeners validado por e2e. |
| **7** | **Panel Central de Administración** | `SystemContext::execute()` protegido contra tenant-admins. |
| **8** | **Generador de Módulos (DX)** | Stubs garantizan *Unique Compuestos*, caché aislada y bloquean explícitamente `DB::table()`. |

---

## 6. Contrato de Queries Forenses (activity_log)

*Para garantizar performance en particionado PostgreSQL:*
- Se prohíbe consultar `activity_log` libremente por la aplicación. Todo acceso debe pasar por un repositorio único (ej. `ForensicLogRepository`).
- La API de este repositorio **rechazará lanzando una excepción** cualquier query que no provea un rango de `created_at` (partition key).
- **Test Robusto de Integración (CI):** El test usa rangos que cubren menos particiones que el total y ejecuta `EXPLAIN (FORMAT JSON)`. Validará de forma tolerante el *Partition Pruning* comprobando que `Subplans Removed > 0` cuando aplique, o que el número de subplans/relations escaneados en el nodo `Append` sea menor al total. Si el pruning ocurre en fase de ejecución, se permite `EXPLAIN (ANALYZE, FORMAT JSON)` como fallback.

---

## 7. Definition of Done Global

- Todo código nuevo está cubierto por un "Contrato de Test" de aislamiento (A no contamina B).
- `make ci` pasa (Pest, PHPStan level 6, Pint, TSC).
- Linters detectan **0** violaciones de uso de mutaciones RBAC clandestinas, API directa de Spatie, `Gate::allowIf`, singletons corruptos, o strings mágicos de canales.
- Cero merged PRs con dependencias pre-release.
