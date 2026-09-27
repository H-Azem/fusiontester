# The Laravel service is also the machine that runs tests, so this image carries
# everything a run shells out to: git, Flutter (web builds), a JDK and Maestro,
# Chromium — plus Node, which is kept only for the browser-driven stages in
# apps/runner that the pipeline calls as a sidecar.
FROM php:8.3-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates curl git unzip xz-utils procps supervisor \
      chromium fonts-liberation \
 && rm -rf /var/lib/apt/lists/*

# Fail the build loudly rather than at runtime if the base image lacks SQLite
# (it ships with pdo_sqlite; this asserts it instead of assuming).
RUN php -m | grep -q pdo_sqlite

# -------------------------------------------------------------------- Node ----
# Only the runner needs it, so it comes from the official image rather than a
# second runtime being installed by hand.
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules

# ---------------------------------------------------------------- Composer ----
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------------- JDK 21 ------
# Maestro is a JVM tool; a JDK is not optional.
RUN curl -fsSL -o /tmp/jdk.tar.gz \
      "https://api.adoptium.net/v3/binary/latest/21/ga/linux/x64/jdk/hotspot/normal/eclipse" \
 && mkdir -p /opt/jdk \
 && tar -xzf /tmp/jdk.tar.gz -C /opt/jdk --strip-components=1 \
 && rm /tmp/jdk.tar.gz
ENV JAVA_HOME=/opt/jdk
ENV PATH=/opt/jdk/bin:$PATH

# --------------------------------------------------------------- Flutter ------
# Web builds only, so the Android and desktop toolchains are skipped.
ARG FLUTTER_VERSION=3.44.8
RUN curl -fsSL -o /tmp/flutter.tar.xz \
      "https://storage.googleapis.com/flutter_infra_release/releases/stable/linux/flutter_linux_${FLUTTER_VERSION}-stable.tar.xz" \
 && tar -xf /tmp/flutter.tar.xz -C /opt \
 && rm /tmp/flutter.tar.xz \
 && git config --global --add safe.directory /opt/flutter
ENV PATH=/opt/flutter/bin:$PATH
RUN flutter config --no-analytics && flutter precache --web && flutter --version

# --------------------------------------------------------------- Maestro ------
RUN curl -fsSL https://get.maestro.mobile.dev | bash
ENV PATH=/root/.maestro/bin:$PATH

# puppeteer-core is given no browser of its own, so point it at the packaged one.
ENV CHROME_PATH=/usr/bin/chromium

# ------------------------------------------------------------------- app ------
WORKDIR /app

# The runner's dependencies are separate from the PHP ones, and tsx stays a
# runtime dependency because the sidecars are TypeScript.
COPY apps/runner/package.json apps/runner/
RUN cd apps/runner && npm install --no-audit --no-fund

COPY apps/api-laravel/composer.json apps/api-laravel/composer.lock apps/api-laravel/
RUN composer install --no-dev --no-interaction --working-dir=apps/api-laravel --no-scripts

COPY apps/runner apps/runner
COPY apps/api-laravel apps/api-laravel

# Laravel needs an application key; it is supplied at runtime, never baked in.
ENV APP_ENV=production \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/fusion.sqlite \
    SESSION_DRIVER=database \
    QUEUE_CONNECTION=database \
    SIDECAR_DIR=/app/apps/runner \
    SIDECAR_SCRIPTS=/app/apps/runner/src/scripts

RUN mkdir -p /data \
 && mkdir -p apps/api-laravel/storage/framework/cache apps/api-laravel/storage/framework/sessions \
             apps/api-laravel/storage/framework/views apps/api-laravel/bootstrap/cache \
 && chmod -R 0775 apps/api-laravel/storage apps/api-laravel/bootstrap/cache

COPY apps/api-laravel/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY apps/api-laravel/docker/supervisord.conf /etc/supervisor/conf.d/fusion.conf
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 4000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/fusion.conf"]
