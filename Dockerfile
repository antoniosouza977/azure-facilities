# syntax=docker/dockerfile:1

# Imagem base: PHP-FPM + Nginx num único container, roda como usuário sem
# privilégios (www-data) e escuta HTTP na porta 8080.
# Já inclui pdo_pgsql, redis, opcache e sodium.
ARG PHP_IMAGE=serversideup/php:8.4-fpm-nginx

############################################
# base
############################################
FROM ${PHP_IMAGE} AS base

ENV PHP_DATE_TIMEZONE=America/Campo_Grande \
    NGINX_WEBROOT=/var/www/html/public

############################################
# development — código montado via volume
############################################
FROM base AS development

ARG USER_ID=1000
ARG GROUP_ID=1000

USER root
# Alinha o UID/GID do www-data com o usuário do host para o bind mount
# não gerar problemas de permissão em storage/ e bootstrap/cache/.
RUN docker-php-serversideup-set-id www-data ${USER_ID}:${GROUP_ID} \
    && docker-php-serversideup-set-file-permissions --owner ${USER_ID}:${GROUP_ID} --service nginx
USER www-data

############################################
# vendor — dependências PHP de produção
############################################
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

############################################
# assets — build do front (Vite)
############################################
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# O front importa o Ziggy de vendor/
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

############################################
# production
############################################
FROM base AS production

ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    SSL_MODE=off

COPY --chown=www-data:www-data . /var/www/html
COPY --chown=www-data:www-data --from=vendor /app/vendor /var/www/html/vendor
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build

USER www-data
