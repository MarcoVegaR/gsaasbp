---
description: Plan de ejecución detallado para la Fase 8 (Generador de Módulos / DX)
---

# Fase 8 — Generador de Módulos y DX (Plan de Ejecución)

**Objetivo principal:** Construir la herramienta de *Developer Experience* (DX) oficial del boilerplate que permita generar módulos de dominio (CRUDs, reportes, entidades) asegurando, por diseño (*secure by default*), el cumplimiento de todos los invariantes arquitectónicos de las Fases 1 a 7. El código generado no debe requerir intervención manual para ser multi-tenant seguro, protegido contra exfiltración y alineado al *Frontend Budget*.

## Secuencia de Implementación Recomendada
1. Motor Base del Generador (Comando Artisan, Configuración, Parser de Esquema YAML/JSON).
2. Generación de Capa de Datos (Migraciones Tenant-Aware, Modelos con Traits, Factories).
3. Generación de Capa de Negocio y Autorización (Policies con Nomenclatura Estricta, Controllers, FormRequests).
4. Generación de Capa de Presentación (Rutas aisladas, Páginas Inertia v2, i18n Dictionaries).
5. Linting Estático Post-Generación (Aserción automática de invariantes en el código emitido).
6. Suite de Contratos E2E/Feature (Validación de un módulo generado de prueba).

## 1. Motor Base del Generador (Thin Generation & Atomic Rollback)
- [ ] **Comando Unificado y Atómico (Safe Concurrency):**
  - Crear `php artisan make:saas-module {name}` que orqueste la creación del stack.
  - **Global Lock Estricto:** El generador adquiere un *lock advisory* exclusivo mediante archivo (`fopen(..., 'c+')` con `flock(..., LOCK_EX)`) durante toda la ejecución. El modo `c+` asegura compatibilidad con sistemas NFS simulando locks. Todo proceso mutador de Phase 8 DEBE respetar este lock para prevenir condiciones de carrera (ej. *lost updates* al editar archivos existentes).
  - **Generación en Staging Dir (Mismo Filesystem):** Todos los archivos nuevos se emiten a un directorio temporal (`storage/framework/module-generator/{uuid}`). El generador valida que este directorio esté en el mismo mount/filesystem que el destino final. Solo si el linting pasa, se realiza un *commit atómico* (rename real) al destino final.
  - **Atomic Replace para Archivos Existentes:** Toda mutación a archivos existentes (sidebar, `BusinessModelRegistry`, rutas) se realiza mediante un patrón *write-to-temp + atomic replace* con un *manifest* de cambios, garantizando rollback determinista si el proceso falla a la mitad.
- [ ] **Definición por Esquema (Schema Linter Estricto):**
  - Soportar un archivo `module.yml` evitando prompts interactivos.
  - **Schema Linter (Fail-Fast & Secure Parsing):** Antes de generar o mutar nada, el parser valida: allowlist de identificadores (regex estricto), colisiones con keywords de PHP/TS, y tipos de datos válidos. 
  - **Prohibición de Ejecución de Código:** El parseo de YAML se realiza bloqueando explícitamente flags inseguros (desactivando `PARSE_OBJECT`, custom tags o constantes en `symfony/yaml`). Si el YAML es malicioso o inválido, aborta de inmediato.
- [ ] **Sistema de Stubs Seguros (Thin Generation / Fat Core):**
  - Los stubs base minimizan la lógica generada. Delegan el peso a clases core (ej. heredar de `BaseTenantController`, `BaseTenantRequest`, `BaseTenantPolicy`) para evitar drift. Si el core evoluciona (Fases 1-7), los módulos heredan las mejoras sin requerir "codemods".

## 2. Capa de Datos (Migraciones y Modelos)
- [ ] **Migraciones Tenant-Aware & Constraints (PostgreSQL-First):**
  - El stub de migración **exige** que cualquier índice `->unique()` incluya `tenant_id` como primer elemento. 
  - **Unique Live (Soft Delete Aware):** Si el esquema usa Soft Deletes, el generador asume el motor oficial del boilerplate (PostgreSQL) y emite SQL explícito para un índice único parcial: `DB::statement('CREATE UNIQUE INDEX ... WHERE deleted_at IS NULL')`. Se prohíbe el uso del inexistente `->whereNull()` en índices de Laravel core o soluciones parcheadas para otros motores.
  - **Foreign Keys Declarativas:** El schema debe especificar el scope de cada FK (`tenant` o `global`). Si es `tenant`, exige pertenencia al mismo tenant.
  - **Cascade Explícito:** El default de borrado es `restrict` (para preservar evidencia forense SaaS). Solo emite `cascadeOnDelete()` si el schema lo solicita expresamente.
- [ ] **Modelos Seguros por Defecto:**
  - Inyección automática del trait `BelongsToTenant` (Fase 1).
  - Prohibición estricta (vía stub) de definir scopes globales mutables que evadan el tenant. Toda tabla pivote debe incluir `tenant_id` si relaciona entidades del tenant.
  - Inyección del trait de *Activity Log* (Fase 4/5). El generador requiere que el schema marque campos con `pii: true` o `secret: true` para aplicar *redaction* por defecto y evitar fugas forenses automáticas.
- [ ] **Factories Contextuales:**
  - El factory generado usa estados explícitos (`->forTenant($tenant)`). Incluye un *fallback* que aborta/falla tempranamente si se intenta instanciar sin un contexto tenant activo, evitando fugas en tests unitarios o seeds.

## 3. Capa de Negocio y Autorización
- [ ] **Controllers Aislados & Reportes Complejos:**
  - Prohibición estricta de `DB::table()` en los stubs de controlador. Obligación de usar Eloquent (cubierto por el scope tenant).
  - Para reportes o consultas complejas con Joins, el generador emite llamadas al wrapper `TenantQuery::table()` asegurando que el scope no se evada accidentalmente al hacer *query building* manual.
- [ ] **Policies Estandarizadas (Consistencia estricta):**
  - Las policies generadas asumen la nomenclatura de Fases 4/5 (`tenant.{module}.view`, `tenant.{module}.create`).
  - Heredan de `BaseTenantPolicy` que implementa un `before()` explícito retornando `true/false/null` según las reglas del Superadmin Denylist. Los métodos generados en el stub retornan *exclusivamente booleanos* para evitar comportamientos ambiguos en el Gate.
- [ ] **Autorización Explícita (Route-Level):**
  - Se abandona el uso de `authorizeResource()` (comportamiento opaco/gris en upgrades). El generador emite autorización explícita y auditable a nivel de ruta usando middleware `can:` por acción, o validación directa en FormRequests (donde el controller solo orquesta).
- [ ] **FormRequests Seguros & Anti-BOLA:**
  - Reglas `Rule::unique()` y `exists()` generadas automáticamente como *compuestas* (`->where('tenant_id', current_tenant)`) u omitidas si apuntan a catálogos globales según declare el schema.
  - **Route-Model Binding Protegido (Pre-Auth):** Se implementa y usa una macro `Route::tenantResource()` que:
    1) Registra binding estricto *tenant-aware* (`where('tenant_id', ...)`).
    2) Fuerza un retorno uniforme `missing()/404` para evitar enumeración BOLA si un ID de otro tenant es inyectado.
    3) **Invariant Testeable:** El generador garantiza que el binding se resuelva estrictamente *antes* del middleware `can:`. Un ID cross-tenant debe lanzar `ModelNotFoundException` y retornar 404, nunca un 403 (lo cual probaría que la autorización corrió sobre datos de otro tenant).

## 4. Capa de Presentación (Inertia y React 19)
- [ ] **Páginas React 19 Strict:**
  - Generación de componentes funcionales puros (List, Form, Show).
  - Uso exclusivo de primitivas *shadcn/ui* locales (Fase 2).
- [ ] **Budget por Capa y Code Splitting (Vite Eager-Free):**
  - El módulo generado respeta el *Initial JS Budget*. Se define un budget estricto por capa: el *app entry* (core) no debe inflarse por imports de módulos. Las páginas generadas se registran obligatoriamente con lazy loading (dynamic imports).
  - **Code Splitting Real:** El Linter/Tests asertan que la resolución de páginas en Inertia usa `import.meta.glob` estrictamente sin `eager: true`.
  - **Regla DX Frontend:** Prohibido importar componentes pesados de módulos dentro del layout global o core.
  - **Contrato Deferred sin Flicker:** Los diccionarios i18n y datos lookup declaran un contrato claro: diccionario/data mínima en initial. Las secciones *Deferred Props* se envuelven obligatoriamente en el componente `<Deferred>` de Inertia (con `grouping` definido) para garantizar cargas incrementales controladas.
- [ ] **Rutas Aisladas:**
  - Archivo de rutas generado bajo el grupo `middleware(['web', 'tenant'])` (o el equivalente definido en Fase 1).

## 5. Validación Estática Post-Generación (Linter Dual: AST + ESLint)
- [ ] **Anti-Patrones Prohibidos (Dual-Linter):**
  - El generador incluye un paso final que hace linting al código recién emitido usando:
    1) **PHP AST (PHP-Parser):** Atrapa evasiones reales como `DB::select`, `Model::query()->withoutGlobalScope()`, o cambios de alias en traits. (Se debe fijar y declarar compatibilidad explícita de versión de PHP-Parser con el target PHP).
    2) **ESLint/TS AST:** Atrapa imports directos de módulos pesados hacia el core, exige dynamic imports en páginas, e impide lecturas síncronas de props que deben ser *deferred*.
  - Falla con error (haciendo rollback atómico limpiando el staging dir) si detecta anti-patrones.
- [ ] **Registro Automático Seguro:**
  - Inserción automática del módulo en el menú de navegación lateral (sidebar) respetando ACL (vía atomic replace).
  - Inserción en el `BusinessModelRegistry` (vía atomic replace).

## Criterios de Aceptación (DoD+++++++++++++++++++++ Obligatorios)
- [ ] **Test Generación End-to-End & Concurrencia:** Ejecutar `make:saas-module TestEntity` compila correctamente. Ejecutar dos instancias concurrentes demuestra que el **Global Lock** evita corrupción y ambos se registran intactos.
- [ ] **Test YAML Secure Parsing:** Proveer un esquema malicioso con `!php/object` u otros tags inseguros es rechazado automáticamente por el parser sin ejecutar código.
- [ ] **Test Migraciones Compuestas (Fail-Fast Parser):** El generador lanza excepción y aborta *antes de escribir en el destino final o realizar mutaciones* si se intenta definir un `unique` simple (sin `tenant_id`) en el esquema YAML.
- [ ] **Test Unique-Live (PostgreSQL-First):** El generador emite un índice parcial explícito en PostgreSQL (`WHERE deleted_at IS NULL`) al detectar soft deletes en el schema, y rechaza/falla si se intenta forzar compatibilidad con motores no soportados (ej. MySQL).
- [ ] **Test Atomicidad Archivos Existentes:** Mutar archivos globales (ej. sidebar, registry) usa el patrón *write-to-temp + rename*. Si se simula un fallo a mitad de generación, los archivos globales mantienen su estado original sin corrupción.
- [ ] **Test Aislamiento Modelos (BelongsToTenant):** Un modelo generado aleatoriamente falla al hacer `Model::all()` si el contexto tenant no está inicializado (prueba de inyección correcta del scope).
- [ ] **Test Model Access Global (Fallback Explicito):** Intentar instanciar el factory generado o consultar el modelo en un contexto global falla a menos que se use un modo administrativo declarado explícitamente y validado.
- [ ] **Test Prevención BOLA (Binding -> Auth Order):** El generador emite rutas con binding estricto (macro `tenantResource`) que se evalúa *antes* del middleware `can:`. Un request a un ID válido de otro tenant retorna `404` (no `403`), evitando enumeración BOLA.
- [ ] **Test Frontend Budget Limit & Splitting:** Compilar los assets frontend tras generar 5 módulos grandes no infla el chunk principal. El `app entry` sigue pesando `< 300KB` (gzip), validando que `import.meta.glob` se usa sin `eager` y las rutas usan dynamic imports.
- [ ] **Test Rollback Atómico por Linting (Dual):** Si el linter (PHP AST o ESLint) detecta un anti-patrón, el proceso aborta, el staging dir se limpia, y no queda rastro del módulo en el filesystem ni en registros del core.
- [ ] **Test Compatibilidad AST:** La ejecución del linter aserta que la versión de `nikic/php-parser` está soportada para la versión actual de PHP.
- [ ] **Test Integración Activity Log (PII Redaction):** Crear, actualizar y eliminar una entidad del módulo generado produce entradas forenses válidas, particionables por `created_at`. Campos no marcados explícitamente para log en el schema no se fugan al payload forense.
- [ ] **Test de Limpieza de Caché (Octane):** El módulo generado usa stores de caché request-scoped a través de wrappers del core; un test valida que la mutación de la entidad no deja estado huérfano entre requests del mismo worker Octane.
