<?php

declare(strict_types=1);

return [
    'modules' => [
        0 => [
            'slug' => 'sample-entity',
            'title' => 'Sample Entity',
            'route_path' => '/tenant/modules/sample-entities',
            'ability_prefix' => 'tenant.sample-entity',
            'model_class' => 'App\\Models\\Generated\\Tenant\\SampleEntity',
            'policy_class' => 'App\\Policies\\Generated\\Tenant\\SampleEntityPolicy',
        ],
    ],
    'business_models' => [
        0 => 'App\\Models\\Generated\\Tenant\\SampleEntity',
    ],
];
