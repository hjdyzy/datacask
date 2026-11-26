ARG RUNTIME_IMAGE=ghcr.io/david-crty/databasement-php@sha256:ea6f8191e04895cc866c7d483ea35d0471e953ef0a15629fe9e59df7783ccdc7

FROM ${RUNTIME_IMAGE} AS runtime

# Extract the Alpine 3.17 MariaDB 10.6 client for MySQL servers below 5.7.
FROM --platform=$BUILDPLATFORM ${RUNTIME_IMAGE} AS mysql56-client
ARG TARGETARCH
# Alpine repository indexes are signed by architecture-specific keys.
COPY --from=runtime /etc/apk/keys/ /tmp/target-apk-keys/
RUN case "${TARGETARCH}" in \
        amd64) MUSL_ARCH=x86_64 ;; \
        arm64) MUSL_ARCH=aarch64 ;; \
        *) echo "Unsupported TARGETARCH: ${TARGETARCH}" >&2; exit 1 ;; \
    esac \
    && apk --root /tmp/legacy --arch "${MUSL_ARCH}" --keys-dir /tmp/target-apk-keys \
        --repositories-file /dev/null --repository https://dl-cdn.alpinelinux.org/alpine/v3.17/main \
        --no-cache --no-scripts add --initdb mariadb-client \
    && mkdir -p /opt/mysql56-client/bin /opt/mysql56-client/share /opt/mysql56-client/lib \
    && cp /tmp/legacy/usr/bin/mariadb-dump /opt/mysql56-client/bin/mariadb-dump-10.6 \
    && cp -a /tmp/legacy/usr/share/mariadb /opt/mysql56-client/share/ \
    && cp -L "/tmp/legacy/lib/ld-musl-${MUSL_ARCH}.so.1" /opt/mysql56-client/lib/ld-musl.so.1 \
    && cp -L /tmp/legacy/lib/libssl.so.3 /tmp/legacy/lib/libcrypto.so.3 /tmp/legacy/lib/libz.so.1 /opt/mysql56-client/lib/ \
    && printf '%s\n' '#!/bin/sh' 'exec /opt/mysql56-client/lib/ld-musl.so.1 --library-path /opt/mysql56-client/lib /opt/mysql56-client/bin/mariadb-dump-10.6 --character-sets-dir=/opt/mysql56-client/share/mariadb/charsets "$@"' > /opt/mysql56-client/bin/mariadb-dump-10.6-wrapper \
    && chmod 755 /opt/mysql56-client/bin/mariadb-dump-10.6-wrapper

FROM runtime

COPY --from=mysql56-client /opt/mysql56-client /opt/mysql56-client
RUN ln -s /opt/mysql56-client/bin/mariadb-dump-10.6-wrapper /usr/local/bin/mariadb-dump-10.6 \
    && mariadb-dump-10.6 --version

ENV BACKUP_JOB_TIMEOUT_SECONDS=10800
COPY supervisord.conf /etc/supervisor/supervisord.conf
RUN sed '/^user=/d' /etc/supervisor/supervisord.conf > /etc/supervisor/supervisord-nonroot.conf \
    && chmod 644 /etc/supervisor/supervisord.conf /etc/supervisor/supervisord-nonroot.conf
COPY php-custom-production.ini /usr/local/etc/php/php-custom-production.ini
COPY php-custom.ini /usr/local/etc/php/conf.d/zz-php-custom.ini
COPY Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 scripts/ /usr/local/bin/
