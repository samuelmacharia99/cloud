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

        'ollama' => [
            'backend' => 'ollama',
            'skip_modal' => false,
            'version_as_image_tag' => false,
            'version_picker' => [
                'show' => true,
                'required' => true,
                'label' => 'Model size',
                'help' => 'Mistral-family models. 7B fits 8 GB plans. 8B is stronger and needs about 16 GB RAM.',
                'options' => [
                    [
                        'value' => '7b',
                        'label' => 'Mistral 7B',
                        'description' => 'Faster replies. Official ollama.com/library/mistral:7b.',
                    ],
                    [
                        'value' => '8b',
                        'label' => 'Ministral 8B',
                        'description' => 'Stronger Mistral-family model. Official ollama.com/library/ministral-3:8b.',
                    ],
                ],
            ],
            'framework' => [
                'required' => false,
                'show' => false,
                'options' => [],
                'locked' => 'ollama',
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
