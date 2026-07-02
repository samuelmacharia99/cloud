<?php

return [

    /*
  |--------------------------------------------------------------------------
  | PHP extension install timeout (seconds)
  |--------------------------------------------------------------------------
  */
    'install_timeout_seconds' => (int) env('PHP_EXTENSION_INSTALL_TIMEOUT', 300),

    /*
  |--------------------------------------------------------------------------
  | Optional PHP extensions (customer-selectable)
  |--------------------------------------------------------------------------
  |
  | Core extensions shipped in the Talksasa runtime image are not listed here.
  | Keys must match the PHP module name returned by `php -m`.
  |
  */
    'extensions' => [
        'gd' => [
            'label' => 'GD',
            'description' => 'Image processing and manipulation (JPEG, PNG, WebP, etc.).',
            'apt' => ['libfreetype6-dev', 'libjpeg62-turbo-dev', 'libpng-dev', 'libwebp-dev'],
            'configure' => 'docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp',
            'install' => 'gd',
        ],
        'exif' => [
            'label' => 'EXIF',
            'description' => 'Read metadata from images uploaded by your application.',
            'install' => 'exif',
        ],
        'soap' => [
            'label' => 'SOAP',
            'description' => 'SOAP web service client and server support.',
            'install' => 'soap',
        ],
        'sockets' => [
            'label' => 'Sockets',
            'description' => 'Low-level socket networking.',
            'install' => 'sockets',
        ],
        'mysqli' => [
            'label' => 'MySQLi',
            'description' => 'MySQL improved extension (in addition to PDO MySQL).',
            'install' => 'mysqli',
        ],
        'pdo_pgsql' => [
            'label' => 'PDO PostgreSQL',
            'description' => 'PostgreSQL database driver for PDO.',
            'apt' => ['libpq-dev'],
            'install' => 'pdo_pgsql',
        ],
        'pgsql' => [
            'label' => 'PostgreSQL',
            'description' => 'Native PostgreSQL database extension.',
            'apt' => ['libpq-dev'],
            'install' => 'pgsql',
        ],
        'gettext' => [
            'label' => 'Gettext',
            'description' => 'Localization and translation file support.',
            'apt' => ['gettext', 'libgettextpo-dev'],
            'install' => 'gettext',
        ],
        'ldap' => [
            'label' => 'LDAP',
            'description' => 'LDAP directory authentication and queries.',
            'apt' => ['libldap2-dev'],
            'configure' => 'docker-php-ext-configure ldap',
            'install' => 'ldap',
        ],
        'xsl' => [
            'label' => 'XSL',
            'description' => 'XSLT transformation support.',
            'apt' => ['libxslt1-dev'],
            'install' => 'xsl',
        ],
        'ffi' => [
            'label' => 'FFI',
            'description' => 'Foreign Function Interface for calling C code from PHP.',
            'install' => 'ffi',
        ],
        'redis' => [
            'label' => 'Redis',
            'description' => 'Redis cache and queue client (PECL).',
            'apt' => ['libssl-dev'],
            'pecl' => 'redis',
            'install' => 'redis',
        ],
        'imagick' => [
            'label' => 'Imagick',
            'description' => 'ImageMagick bindings for advanced image processing (PECL).',
            'apt' => ['libmagickwand-dev'],
            'pecl' => 'imagick',
            'install' => 'imagick',
        ],
    ],

    /*
  |--------------------------------------------------------------------------
  | Built-in runtime extensions (always present in Talksasa PHP images)
  |--------------------------------------------------------------------------
  */
    'builtin' => [
        'bcmath',
        'gmp',
        'intl',
        'mbstring',
        'opcache',
        'pcntl',
        'pdo_mysql',
        'xml',
        'zip',
    ],

];
