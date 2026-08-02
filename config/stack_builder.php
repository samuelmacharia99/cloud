<?php

/**
 * Compatibility matrix for the customer stack builder (v1).
 *
 * Frontend values (vite-spa / nextjs) enable Node.js in the PHP/Laravel app runtime
 * for builds. A separate frontend sidecar container is not provisioned yet.
 */
return [

    'version' => 1,

    'frontend_labels' => [
        'none' => 'None (API / backend only)',
        'vite-spa' => 'Vite / React SPA',
        'nextjs' => 'Next.js',
        'static' => 'Static site',
    ],

    'framework_labels' => [
        'express' => 'Express',
        'nest' => 'NestJS',
        'nextjs' => 'Next.js',
        'django' => 'Django',
        'fastapi' => 'FastAPI',
        'flask' => 'Flask',
        'rails' => 'Rails',
        'other' => 'Other / custom',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per app-type (container template slug) role rules
    |--------------------------------------------------------------------------
    |
    | framework.required / frontend.show / database.required drive the UI.
    | locked_* values are applied automatically when the role is not choosable.
    |
    */
    'stacks' => [

        'wordpress' => [
            'backend' => 'wordpress',
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => null,
            ],
            'frontend' => [
                'required' => false,
                'show' => false,
                'options' => ['none'],
                'locked' => 'none',
            ],
            'database' => [
                'required' => true,
                'show' => true,
                'allow_none' => false,
                'types' => ['mysql', 'mariadb'],
            ],
        ],

        'php' => [
            'backend' => 'php',
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => null,
            ],
            'frontend' => [
                'required' => false,
                'show' => true,
                'options' => ['none', 'static'],
                'locked' => null,
            ],
            'database' => [
                'required' => true,
                'show' => true,
                'allow_none' => false,
                'types' => ['mysql', 'mariadb'],
            ],
        ],

        'laravel' => [
            'backend' => 'laravel',
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'laravel',
            ],
            'frontend' => [
                'required' => true,
                'show' => true,
                'options' => ['none', 'vite-spa', 'nextjs'],
                'locked' => null,
            ],
            'database' => [
                'required' => true,
                'show' => true,
                'allow_none' => false,
                'types' => ['mysql', 'mariadb', 'postgresql', 'mongodb', 'redis'],
            ],
        ],

        'nodejs' => [
            'backend' => 'nodejs',
            'framework' => [
                'required' => true,
                'show' => true,
                'options' => ['express', 'nest', 'nextjs', 'other'],
                'locked' => null,
            ],
            'frontend' => [
                'required' => true,
                'show' => true,
                'options' => ['none', 'vite-spa', 'nextjs'],
                'locked' => null,
                // When framework is nextjs, frontend is forced to nextjs.
                'lock_when_framework' => [
                    'nextjs' => 'nextjs',
                ],
            ],
            'database' => [
                'required' => false,
                'show' => true,
                'allow_none' => true,
                'types' => ['mysql', 'mariadb', 'postgresql', 'mongodb', 'redis'],
            ],
        ],

        'python' => [
            'backend' => 'python',
            'framework' => [
                'required' => true,
                'show' => true,
                'options' => ['django', 'fastapi', 'flask', 'other'],
                'locked' => null,
            ],
            'frontend' => [
                'required' => true,
                'show' => true,
                'options' => ['none', 'vite-spa', 'nextjs'],
                'locked' => null,
            ],
            'database' => [
                'required' => false,
                'show' => true,
                'allow_none' => true,
                'types' => ['mysql', 'mariadb', 'postgresql', 'mongodb', 'redis'],
            ],
        ],

        'ruby' => [
            'backend' => 'ruby',
            'framework' => [
                'required' => false,
                'show' => true,
                'options' => ['rails', 'other'],
                'locked' => null,
            ],
            'frontend' => [
                'required' => true,
                'show' => true,
                'options' => ['none', 'vite-spa'],
                'locked' => null,
            ],
            'database' => [
                'required' => false,
                'show' => true,
                'allow_none' => true,
                'types' => ['mysql', 'mariadb', 'postgresql', 'mongodb', 'redis'],
            ],
        ],

        'static-site' => [
            'backend' => 'static-site',
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => null,
            ],
            'frontend' => [
                'required' => false,
                'show' => false,
                'options' => ['static'],
                'locked' => 'static',
            ],
            'database' => [
                'required' => false,
                'show' => false,
                'allow_none' => true,
                'types' => [],
            ],
        ],

        'ghost' => [
            'backend' => 'ghost',
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'ghost',
            ],
            'frontend' => [
                'required' => false,
                'show' => false,
                'options' => ['none'],
                'locked' => 'none',
            ],
            'database' => [
                'required' => true,
                'show' => true,
                'allow_none' => false,
                'types' => ['mysql', 'mariadb'],
            ],
        ],

        'strapi' => [
            'backend' => 'strapi',
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'strapi',
            ],
            'frontend' => [
                'required' => false,
                'show' => false,
                'options' => ['none'],
                'locked' => 'none',
            ],
            'database' => [
                'required' => true,
                'show' => true,
                'allow_none' => false,
                'types' => ['mysql', 'mariadb', 'postgresql', 'mongodb'],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback for unknown / new container template slugs
    |--------------------------------------------------------------------------
    */
    'default' => [
        'backend' => null,
        'framework' => [
            'required' => false,
            'show' => false,
            'options' => [],
            'locked' => null,
        ],
        'frontend' => [
            'required' => true,
            'show' => true,
            'options' => ['none'],
            'locked' => null,
        ],
        'database' => [
            'required' => false,
            'show' => true,
            'allow_none' => true,
            'types' => ['mysql', 'mariadb', 'postgresql', 'mongodb', 'redis'],
        ],
    ],

];
