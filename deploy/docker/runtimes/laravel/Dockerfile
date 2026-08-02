ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-cli-bookworm

ARG PHP_VERSION

LABEL org.opencontainers.image.title="Talksasa Laravel Runtime"
LABEL org.opencontainers.image.description="PHP CLI runtime with Composer and Laravel extensions"
LABEL org.talksasa.php.version="${PHP_VERSION}"

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    PATH="/usr/local/bin:/usr/bin:/bin"

    RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        ca-certificates \
        libzip-dev \
        libpng-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libonig-dev \
        libxml2-dev \
        libicu-dev \
        libgmp-dev \
        libpq-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        gmp \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        xml \
        zip \
    && git config --system --add safe.directory '*' \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY entrypoint.sh /usr/local/bin/talksasa-entrypoint
RUN chmod +x /usr/local/bin/talksasa-entrypoint

WORKDIR /app

ENTRYPOINT ["/usr/local/bin/talksasa-entrypoint"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app/public"]
