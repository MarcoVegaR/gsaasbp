<?php

declare(strict_types=1);

namespace App\Support\Phase8;

use Illuminate\Support\Str;

final readonly class ModuleDefinition
{
    /**
     * @param  list<ModuleFieldDefinition>  $fields
     * @param  list<ModuleIndexDefinition>  $indexes
     * @param  list<ModuleForeignKeyDefinition>  $foreignKeys
     */
    public function __construct(
        public string $name,
        public string $slug,
        public string $table,
        public bool $softDeletes,
        public string $databaseDriver,
        public array $fields,
        public array $indexes,
        public array $foreignKeys,
    ) {}

    public function modelClass(): string
    {
        return sprintf('App\\Models\\Generated\\Tenant\\%s', $this->classBaseName());
    }

    public function policyClass(): string
    {
        return sprintf('App\\Policies\\Generated\\Tenant\\%sPolicy', $this->classBaseName());
    }

    public function controllerClass(): string
    {
        return sprintf('App\\Http\\Controllers\\Generated\\Tenant\\%1$s\\%1$sController', $this->classBaseName());
    }

    public function storeRequestClass(): string
    {
        return sprintf('App\\Http\\Requests\\Generated\\Tenant\\%1$s\\Store%1$sRequest', $this->classBaseName());
    }

    public function updateRequestClass(): string
    {
        return sprintf('App\\Http\\Requests\\Generated\\Tenant\\%1$s\\Update%1$sRequest', $this->classBaseName());
    }

    public function classBaseName(): string
    {
        return Str::studly($this->name);
    }

    public function routeSlug(): string
    {
        return Str::kebab(Str::pluralStudly($this->classBaseName()));
    }

    public function routePath(): string
    {
        return '/tenant/modules/'.$this->routeSlug();
    }

    public function routeNamePrefix(): string
    {
        return 'tenant.generated.'.$this->slug;
    }

    public function routeParameter(): string
    {
        return Str::camel($this->classBaseName());
    }

    public function abilityPrefix(): string
    {
        return 'tenant.'.$this->slug;
    }

    public function title(): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::headline($this->name))) ?: $this->classBaseName();
    }

    public function frontendBasePath(): string
    {
        return 'tenant/modules/'.$this->slug;
    }
}
