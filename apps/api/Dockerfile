# The API also *runs* the tests, so this image carries every tool a run shells
# out to. That is what keeps "no separate installs" true: Flutter, a JDK and
# Maestro, Chromium, and git all arrive with the image.
#
# Base is Node 22 deliberately: the API imports undici 8, which needs Node >= 21
# (on Node 20 it fails at startup with "markAsUncloneable is not a function").
FROM node:22-bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates curl git unzip xz-utils procps \
      chromium fonts-liberation \
 && rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------- JDK 21 ----
# Maestro is a JVM tool; a JDK is not optional.
RUN curl -fsSL -o /tmp/jdk.tar.gz \
      "https://api.adoptium.net/v3/binary/latest/21/ga/linux/x64/jdk/hotspot/normal/eclipse" \
 && mkdir -p /opt/jdk \
 && tar -xzf /tmp/jdk.tar.gz -C /opt/jdk --strip-components=1 \
 && rm /tmp/jdk.tar.gz
ENV JAVA_HOME=/opt/jdk
ENV PATH=/opt/jdk/bin:$PATH

# --------------------------------------------------------------- Flutter ----
# Web builds only, so the Android/Host toolchains are skipped entirely.
ARG FLUTTER_VERSION=3.44.8
RUN curl -fsSL -o /tmp/flutter.tar.xz \
      "https://storage.googleapis.com/flutter_infra_release/releases/stable/linux/flutter_linux_${FLUTTER_VERSION}-stable.tar.xz" \
 && tar -xf /tmp/flutter.tar.xz -C /opt \
 && rm /tmp/flutter.tar.xz \
 && git config --global --add safe.directory /opt/flutter
ENV PATH=/opt/flutter/bin:$PATH

# Download the Dart SDK and the web artifacts up front, so the first run does
# not have to fetch a few hundred megabytes before it can build.
RUN flutter config --no-analytics \
 && flutter precache --web \
 && flutter --version

# --------------------------------------------------------------- Maestro ----
RUN curl -fsSL https://get.maestro.mobile.dev | bash
ENV PATH=/root/.maestro/bin:$PATH

# puppeteer-core is given no browser of its own, so point it at the packaged one.
ENV CHROME_PATH=/usr/bin/chromium

# ------------------------------------------------------------------ app -----
WORKDIR /app
RUN corepack enable

# Workspace manifests first, so dependency installation is cached separately
# from source changes.
COPY pnpm-workspace.yaml package.json pnpm-lock.yaml ./
COPY apps/api/package.json apps/api/
COPY apps/web/package.json apps/web/
RUN pnpm install --frozen-lockfile

COPY . .
RUN pnpm --filter @fusion-tester/api build

EXPOSE 4000

# node dist/server.js, never tsx watch: a watcher that reloads mid-run leaves the
# Flutter build orphaned and burning memory.
CMD ["node", "apps/api/dist/server.js"]
