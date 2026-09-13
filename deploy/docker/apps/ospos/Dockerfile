# Open Source POS, built on the container node from a GitHub release tag.
#
# Upstream publishes only moving tags (master, 3.4.2-<branch>-<sha>), so the
# platform builds its own image per release. Upstream CI runs composer and the
# gulp asset build *before* docker build, which is why this Dockerfile has a
# vendor stage and an assets stage ahead of the runtime stage; the runtime
# stage itself mirrors upstream's Dockerfile so behaviour matches the official
# image.

ARG OSPOS_REF=3.4.1
ARG OSPOS_REPOSITORY=https://github.com/opensourcepos/opensourcepos.git

FROM composer:2 AS vendor
ARG OSPOS_REF
ARG OSPOS_REPOSITORY
RUN git clone --depth 1 --branch "${OSPOS_REF}" "${OSPOS_REPOSITORY}" /src
WORKDIR /src
# The composer image has no intl/gd/mysqli; the runtime stage does.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --ignore-platform-reqs

FROM node:20-bookworm AS assets
COPY --from=vendor /src /src
WORKDIR /src
RUN npm ci --no-audit --no-fund \
    && npm run build \
    && rm -rf node_modules

FROM php:8.2-apache
ARG OSPOS_REF
LABEL org.opencontainers.image.title="Open Source POS" \
      org.opencontainers.image.source="https://github.com/opensourcepos/opensourcepos" \
      org.opencontainers.image.version="${OSPOS_REF}" \
      org.talksasa.app="ospos"

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libgd-dev \
    && docker-php-ext-install mysqli bcmath intl gd \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite

# Upstream reads the timezone from the environment at request time.
RUN echo "date.timezone = \"\${PHP_TIMEZONE}\"" > /usr/local/etc/php/conf.d/timezone.ini

WORKDIR /app
COPY --from=assets --chown=www-data:www-data /src /app
RUN chmod 750 /app/writable/logs /app/writable/uploads /app/writable/cache /app/public/uploads /app/public/uploads/item_pics \
    && chmod 640 /app/writable/uploads/importCustomers.csv \
    && ln -s /app/*[^public] /var/www \
    && rm -rf /var/www/html \
    && ln -nsf /app/public /var/www/html

EXPOSE 80
