<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plan pool defaults
    |--------------------------------------------------------------------------
    |
    | When a customer deploys an additional included service onto an existing
    | Application Hosting project, resource_share is taken from the remaining
    | plan pool using these defaults (capped by what is still unallocated).
    |
    */

    'plan_pool' => [
        'default_workload_share' => [
            'cpu' => 0.25,
            'memory' => 0.25,
        ],
        'min_workload_share' => [
            'cpu' => 0.05,
            'memory' => 0.05,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Project recipes
    |--------------------------------------------------------------------------
    |
    | Maps tech-stack session selections to multi-service project layouts.
    | P0: laravel + nextjs → separate API and Web container services under one
    | CustomerProject, billed once via the billing-anchor role.
    |
    */

    'recipes' => [

        'laravel_next' => [
            'label' => 'Laravel + Next.js',
            'match' => [
                'language_slug' => 'laravel',
                'frontend' => 'nextjs',
            ],
            'roles' => [
                [
                    'key' => 'backend',
                    'suffix' => 'api',
                    'label' => 'API',
                    'template_from' => 'product',
                    'billing_anchor' => true,
                    'cpu_share' => 0.55,
                    'memory_share' => 0.55,
                ],
                [
                    'key' => 'frontend',
                    'suffix' => 'web',
                    'label' => 'Web',
                    'template_slug' => 'nodejs',
                    'billing_anchor' => false,
                    'cpu_share' => 0.45,
                    'memory_share' => 0.45,
                ],
            ],
        ],

    ],

];
