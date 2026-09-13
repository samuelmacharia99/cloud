<?php

/**
 * Compatibility matrix for the customer stack builder (v1).
 *
 * Frontend value nextjs provisions Compose sidecars: backend (Laravel), frontend (Next),
 * and edge (public router), plus the usual database sidecar when selected.
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
            'version_picker' => [
                'show' => true,
                'required' => false,
                'label' => 'WordPress and PHP version',
                'help' => 'Official WordPress images bundle a PHP version. Latest tracks the current release.',
                'source' => 'template_versions',
                'default' => 'latest',
            ],
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
            'version_picker' => [
                'show' => true,
                'required' => false,
                'label' => 'PHP version',
                'help' => 'The runtime image is built for this PHP version. Change it later from the PHP version tab in the console.',
                'default' => '8.3',
                'options' => [
                    ['value' => '8.4', 'label' => 'PHP 8.4', 'description' => 'Newest release.'],
                    ['value' => '8.3', 'label' => 'PHP 8.3', 'description' => 'Recommended for current Laravel and most apps.'],
                    ['value' => '8.2', 'label' => 'PHP 8.2', 'description' => 'For apps not yet on 8.3.'],
                    ['value' => '8.1', 'label' => 'PHP 8.1', 'description' => 'Older Laravel 9 / 10 apps and legacy code.'],
                ],
            ],
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
            'version_picker' => [
                'show' => true,
                'required' => false,
                'label' => 'PHP version',
                'help' => 'The runtime image is built for this PHP version. Change it later from the PHP version tab in the console.',
                'default' => '8.3',
                'options' => [
                    ['value' => '8.4', 'label' => 'PHP 8.4', 'description' => 'Newest release.'],
                    ['value' => '8.3', 'label' => 'PHP 8.3', 'description' => 'Recommended for current Laravel and most apps.'],
                    ['value' => '8.2', 'label' => 'PHP 8.2', 'description' => 'For apps not yet on 8.3.'],
                    ['value' => '8.1', 'label' => 'PHP 8.1', 'description' => 'Older Laravel 9 / 10 apps and legacy code.'],
                ],
            ],
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
            'skip_modal' => true,
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

        'hermes' => [
            'backend' => 'hermes',
            'skip_modal' => true,
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'hermes',
            ],
            'frontend' => [
                'required' => false,
                'show' => false,
                'options' => ['none'],
                'locked' => 'none',
            ],
            'database' => [
                'required' => false,
                'show' => false,
                'allow_none' => true,
                'types' => [],
            ],
        ],

        'openclaw' => [
            'backend' => 'openclaw',
            'skip_modal' => true,
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'openclaw',
            ],
            'frontend' => [
                'required' => false,
                'show' => false,
                'options' => ['none'],
                'locked' => 'none',
            ],
            'database' => [
                'required' => false,
                'show' => false,
                'allow_none' => true,
                'types' => [],
            ],
        ],

        'n8n' => [
            'backend' => 'n8n',
            'skip_modal' => true,
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'n8n',
            ],
            'frontend' => [
                'required' => false,
                'show' => false,
                'options' => ['none'],
                'locked' => 'none',
            ],
            'database' => [
                'required' => false,
                'show' => false,
                'allow_none' => true,
                'types' => [],
            ],
        ],

        'go' => [
            'backend' => 'go',
            'framework' => [
                'required' => false,
                'show' => true,
                'options' => ['other'],
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

        'directus' => [
            'backend' => 'directus',
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'directus',
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
                'types' => ['mysql', 'mariadb', 'postgresql'],
            ],
        ],

        'chatwoot' => [
            'backend' => 'chatwoot',
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'chatwoot',
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
                'types' => ['postgresql'],
            ],
        ],

        'odoo' => [
            'backend' => 'odoo',
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'odoo',
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
                'types' => ['postgresql'],
            ],
        ],

        'ospos' => [
            'backend' => 'ospos',
            'skip_modal' => false,
            'version_picker' => [
                'show' => true,
                'required' => false,
                'label' => 'Release',
                'help' => 'Open Source POS release tag. The image is built on the node from that tag.',
                'options' => [
                    ['value' => '3.4.1', 'label' => 'OSPOS 3.4.1', 'description' => 'Current release.'],
                    ['value' => '3.4.0', 'label' => 'OSPOS 3.4.0', 'description' => 'Previous release.'],
                ],
            ],
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'ospos',
            ],
            'frontend' => [
                'required' => false,
                'show' => false,
                'options' => ['none'],
                'locked' => 'none',
            ],
            'database' => [
                'required' => false,
                'show' => false,
                'allow_none' => true,
                'types' => [],
            ],
        ],

        'erpnext' => [
            'backend' => 'erpnext',
            'skip_modal' => true,
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'erpnext',
            ],
            'frontend' => [
                'required' => false,
                'show' => false,
                'options' => ['none'],
                'locked' => 'none',
            ],
            'database' => [
                'required' => false,
                'show' => false,
                'allow_none' => true,
                'types' => [],
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
