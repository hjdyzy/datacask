ARG BASE_IMAGE=ghcr.io/david-crty/databasement-php@sha256:6494373e3ec937d7ec056a0ea708b3c95f0d166dce5cdb15d2cf609a7718fd86
ARG NODE_IMAGE=public.ecr.aws/docker/library/node@sha256:83f487e0a63425e5b4d146fb5e5be574bcbe1b7b843d3ebafdd95eaf7767a7e5
FROM ${BASE_IMAGE} AS backend-build

USER 1000

COPY --chown=1000:1000 . /app

RUN composer install --dev --no-interaction --no-progress --no-suggest --optimize-autoloader
RUN php artisan vendor:publish --force --tag=livewire:assets


FROM ${NODE_IMAGE} AS frontend-build

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY --from=backend-build /app /app
RUN npm run build


ARG BASE_IMAGE=ghcr.io/david-crty/databasement-php@sha256:6494373e3ec937d7ec056a0ea708b3c95f0d166dce5cdb15d2cf609a7718fd86
FROM ${BASE_IMAGE}

ARG APP_COMMIT_HASH=""
ARG APP_VERSION=""
ENV APP_ENV="production"
ENV APP_DEBUG="false"
ENV APP_COMMIT_HASH="${APP_COMMIT_HASH}"
ENV APP_VERSION="${APP_VERSION}"

COPY --from=backend-build /app /app
COPY --from=frontend-build /app/public/build /app/public/build

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
RUN cp /usr/local/etc/php/php-custom-production.ini /usr/local/etc/php/conf.d/zz-php-custom-production.ini

# fix permission for rootless
RUN chmod -R 777 /app/storage /app/bootstrap/cache
