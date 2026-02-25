<?php

declare(strict_types=1);

namespace App\Support\Phase8;

use InvalidArgumentException;
use JsonException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ModuleSchemaParser
{
    private const IDENTIFIER_REGEX = '/^[A-Za-z][A-Za-z0-9_]*$/';

    private const SLUG_REGEX = '/^[a-z][a-z0-9\-]*$/';

    /**
     * @var list<string>
     */
    private const RESERVED_KEYWORDS = [
        'abstract',
        'any',
        'array',
        'as',
        'async',
        'await',
        'bool',
        'boolean',
        'break',
        'case',
        'catch',
        'class',
        'const',
        'continue',
        'declare',
        'default',
        'do',
        'echo',
        'else',
        'elseif',
        'enum',
        'eval',
        'export',
        'extends',
        'false',
        'final',
        'finally',
        'float',
        'for',
        'foreach',
        'function',
        'global',
        'goto',
        'if',
        'implements',
        'import',
        'in',
        'instanceof',
        'int',
        'interface',
        'let',
        'match',
        'namespace',
        'new',
        'null',
        'object',
        'package',
        'private',
        'protected',
        'public',
        'readonly',
        'return',
        'self',
        'static',
        'string',
        'switch',
        'this',
        'throw',
        'trait',
        'true',
        'try',
        'type',
        'typeof',
        'undefined',
        'use',
        'var',
        'void',
        'while',
        'yield',
    ];

    public function parse(string $moduleName, ?string $schemaPath = null): ModuleDefinition
    {
        $payload = $this->decodeSchema($this->resolveSchemaFile($schemaPath));

        return $this->normalize($moduleName, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function normalize(string $moduleName, array $payload): ModuleDefinition
    {
        $name = $this->normalizeModuleName($payload['name'] ?? $moduleName);
        $slug = $this->normalizeSlug($payload['slug'] ?? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name)));

        $table = trim((string) ($payload['table'] ?? 'tenant_'.strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $this->pluralize($name)))));
        $this->assertIdentifier($table, 'table');

        $softDeletes = (bool) ($payload['soft_deletes'] ?? false);
        $databaseDriver = strtolower(trim((string) ($payload['database_driver'] ?? 'pgsql')));

        $fields = $this->normalizeFields($payload['fields'] ?? null);
        $indexes = $this->normalizeIndexes($payload['indexes'] ?? null, $fields);
        $foreignKeys = $this->normalizeForeignKeys($payload['foreign_keys'] ?? null);

        if ($softDeletes && $this->hasUniqueIndexes($indexes) && $databaseDriver !== 'pgsql') {
            throw new InvalidArgumentException('Soft-deletes with unique indexes are only supported for PostgreSQL (database_driver=pgsql).');
        }

        return new ModuleDefinition(
            name: $name,
            slug: $slug,
            table: $table,
            softDeletes: $softDeletes,
            databaseDriver: $databaseDriver,
            fields: $fields,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
        );
    }

    /**
     * @return list<ModuleFieldDefinition>
     */
    private function normalizeFields(mixed $rawFields): array
    {
        if (! is_array($rawFields) || $rawFields === []) {
            throw new InvalidArgumentException('The schema must define at least one field in [fields].');
        }

        $allowedFieldTypes = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) config('phase8.parser.allowed_field_types', []),
        ), static fn (string $value): bool => $value !== ''));

        $fields = [];

        foreach ($rawFields as $index => $rawField) {
            if (! is_array($rawField)) {
                throw new InvalidArgumentException(sprintf('Field definition at index [%d] must be an object.', $index));
            }

            $name = trim((string) ($rawField['name'] ?? ''));
            $this->assertIdentifier($name, sprintf('fields[%d].name', $index));
            $this->assertNotReservedKeyword($name, sprintf('fields[%d].name', $index));

            $type = strtolower(trim((string) ($rawField['type'] ?? '')));

            if ($type === '' || ! in_array($type, $allowedFieldTypes, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Field [%s] uses unsupported type [%s]. Allowed: %s',
                    $name,
                    $type,
                    implode(', ', $allowedFieldTypes),
                ));
            }

            $fields[] = new ModuleFieldDefinition(
                name: $name,
                type: $type,
                nullable: (bool) ($rawField['nullable'] ?? false),
                unique: (bool) ($rawField['unique'] ?? false),
                audit: (bool) ($rawField['audit'] ?? false),
                pii: (bool) ($rawField['pii'] ?? false),
                secret: (bool) ($rawField['secret'] ?? false),
            );
        }

        $fieldNames = array_map(static fn (ModuleFieldDefinition $field): string => $field->name, $fields);

        if (count($fieldNames) !== count(array_unique($fieldNames))) {
            throw new InvalidArgumentException('Field names must be unique within the schema.');
        }

        return $fields;
    }

    /**
     * @param  list<ModuleFieldDefinition>  $fields
     * @return list<ModuleIndexDefinition>
     */
    private function normalizeIndexes(mixed $rawIndexes, array $fields): array
    {
        $indexes = [];

        if ($rawIndexes !== null) {
            if (! is_array($rawIndexes)) {
                throw new InvalidArgumentException('The [indexes] entry must be an array when provided.');
            }

            foreach ($rawIndexes as $index => $rawIndex) {
                if (! is_array($rawIndex)) {
                    throw new InvalidArgumentException(sprintf('Index definition at [%d] must be an object.', $index));
                }

                $type = strtolower(trim((string) ($rawIndex['type'] ?? '')));

                if (! in_array($type, ['index', 'unique'], true)) {
                    throw new InvalidArgumentException(sprintf('Index at [%d] must use type [index] or [unique].', $index));
                }

                $columns = array_values(array_filter(array_map(
                    static fn (mixed $column): string => trim((string) $column),
                    (array) ($rawIndex['columns'] ?? []),
                ), static fn (string $column): bool => $column !== ''));

                if ($columns === []) {
                    throw new InvalidArgumentException(sprintf('Index [%d] must define at least one column.', $index));
                }

                foreach ($columns as $column) {
                    $this->assertIdentifier($column, sprintf('indexes[%d].columns[]', $index));
                }

                if ($type === 'unique' && $columns[0] !== 'tenant_id') {
                    throw new InvalidArgumentException(sprintf(
                        'Unique index at [%d] must start with tenant_id to preserve tenant isolation.',
                        $index,
                    ));
                }

                $name = isset($rawIndex['name']) ? trim((string) $rawIndex['name']) : null;

                if ($name !== null && $name !== '') {
                    $this->assertIdentifier($name, sprintf('indexes[%d].name', $index));
                } else {
                    $name = null;
                }

                $indexes[] = new ModuleIndexDefinition(
                    type: $type,
                    columns: $columns,
                    name: $name,
                );
            }
        }

        foreach ($fields as $field) {
            if (! $field->unique) {
                continue;
            }

            $candidateColumns = ['tenant_id', $field->name];
            $alreadyDefined = false;

            foreach ($indexes as $index) {
                if (! $index->isUnique()) {
                    continue;
                }

                if ($index->columns === $candidateColumns) {
                    $alreadyDefined = true;

                    break;
                }
            }

            if ($alreadyDefined) {
                continue;
            }

            $indexes[] = new ModuleIndexDefinition(
                type: 'unique',
                columns: $candidateColumns,
                name: null,
            );
        }

        return $indexes;
    }

    /**
     * @return list<ModuleForeignKeyDefinition>
     */
    private function normalizeForeignKeys(mixed $rawForeignKeys): array
    {
        if ($rawForeignKeys === null) {
            return [];
        }

        if (! is_array($rawForeignKeys)) {
            throw new InvalidArgumentException('The [foreign_keys] entry must be an array when provided.');
        }

        $foreignKeys = [];

        foreach ($rawForeignKeys as $index => $rawForeignKey) {
            if (! is_array($rawForeignKey)) {
                throw new InvalidArgumentException(sprintf('foreign_keys[%d] must be an object.', $index));
            }

            $column = trim((string) ($rawForeignKey['column'] ?? ''));
            $referencesTable = trim((string) ($rawForeignKey['references_table'] ?? ''));
            $referencesColumn = trim((string) ($rawForeignKey['references_column'] ?? 'id'));
            $scope = strtolower(trim((string) ($rawForeignKey['scope'] ?? 'global')));
            $onDelete = strtolower(trim((string) ($rawForeignKey['on_delete'] ?? 'restrict')));

            $this->assertIdentifier($column, sprintf('foreign_keys[%d].column', $index));
            $this->assertIdentifier($referencesTable, sprintf('foreign_keys[%d].references_table', $index));
            $this->assertIdentifier($referencesColumn, sprintf('foreign_keys[%d].references_column', $index));

            if (! in_array($scope, ['tenant', 'global'], true)) {
                throw new InvalidArgumentException(sprintf('foreign_keys[%d].scope must be [tenant] or [global].', $index));
            }

            if (! in_array($onDelete, ['restrict', 'cascade'], true)) {
                throw new InvalidArgumentException(sprintf('foreign_keys[%d].on_delete must be [restrict] or [cascade].', $index));
            }

            $foreignKeys[] = new ModuleForeignKeyDefinition(
                column: $column,
                referencesTable: $referencesTable,
                referencesColumn: $referencesColumn,
                scope: $scope,
                onDelete: $onDelete,
            );
        }

        return $foreignKeys;
    }

    /**
     * @param  list<ModuleIndexDefinition>  $indexes
     */
    private function hasUniqueIndexes(array $indexes): bool
    {
        foreach ($indexes as $index) {
            if ($index->isUnique()) {
                return true;
            }
        }

        return false;
    }

    private function resolveSchemaFile(?string $schemaPath): string
    {
        $candidate = trim((string) ($schemaPath ?? config('phase8.generator.default_schema_file', 'module.yml')));

        if ($candidate === '') {
            throw new InvalidArgumentException('Schema path cannot be empty.');
        }

        if (! str_starts_with($candidate, DIRECTORY_SEPARATOR)) {
            $candidate = getcwd().DIRECTORY_SEPARATOR.$candidate;
        }

        if (! is_file($candidate)) {
            throw new InvalidArgumentException(sprintf('Schema file [%s] does not exist.', $candidate));
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSchema(string $schemaFile): array
    {
        $contents = (string) file_get_contents($schemaFile);

        if ($contents === '') {
            throw new InvalidArgumentException(sprintf('Schema file [%s] is empty.', $schemaFile));
        }

        if (preg_match('/(^|[\s\-\[\{,])![^\s]+/m', $contents) === 1) {
            throw new InvalidArgumentException('Schema parsing rejected: custom YAML tags are not allowed for security reasons.');
        }

        $extension = strtolower((string) pathinfo($schemaFile, PATHINFO_EXTENSION));

        try {
            if ($extension === 'json') {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $decoded = Yaml::parse($contents, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            }
        } catch (ParseException|JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf('Failed to parse module schema [%s]: %s', $schemaFile, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Schema root must be an object/map.');
        }

        return $decoded;
    }

    private function normalizeModuleName(mixed $name): string
    {
        $candidate = trim((string) $name);

        if ($candidate === '') {
            throw new InvalidArgumentException('Module name cannot be empty.');
        }

        $normalized = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $candidate)));

        $this->assertIdentifier($normalized, 'name');
        $this->assertNotReservedKeyword($normalized, 'name');

        return $normalized;
    }

    private function normalizeSlug(mixed $slug): string
    {
        $candidate = strtolower(trim((string) $slug));

        if ($candidate === '' || preg_match(self::SLUG_REGEX, $candidate) !== 1) {
            throw new InvalidArgumentException('Schema [slug] must match ^[a-z][a-z0-9-]*$.');
        }

        return $candidate;
    }

    private function assertIdentifier(string $value, string $field): void
    {
        if ($value === '' || preg_match(self::IDENTIFIER_REGEX, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Schema [%s] must match %s.', $field, self::IDENTIFIER_REGEX));
        }
    }

    private function assertNotReservedKeyword(string $value, string $field): void
    {
        if (in_array(strtolower($value), self::RESERVED_KEYWORDS, true)) {
            throw new InvalidArgumentException(sprintf('Schema [%s] uses reserved keyword [%s].', $field, $value));
        }
    }

    private function pluralize(string $value): string
    {
        if (str_ends_with(strtolower($value), 's')) {
            return $value;
        }

        return $value.'s';
    }
}
