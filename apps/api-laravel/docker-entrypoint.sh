#!/bin/sh
set -eu

cd /app/apps/api-laravel

# Private `git:` packages in a pubspec are fetched with git during a run, and the
# pipeline authenticates that fetch with the token stored in Settings. The
# entrypoint deliberately does not configure git itself: a second credential for the
# same host is what let a stale token shadow the configured one.

if [ -z "${APP_KEY:-}" ]; then
  echo "APP_KEY is required (php artisan key:generate --show)" >&2
  exit 1
fi

# Flutter is baked into the image, but an SDK moved onto the volume by the
# Settings page wins: it is the one that survived a redeploy. The chosen path is
# exported (supervisord and every build inherit it) and written to .env so the
# dashboard can say which SDK is in force.
if [ -x /sdk/flutter/bin/flutter ]; then
  FLUTTER_HOME=/sdk/flutter
else
  FLUTTER_HOME=/opt/flutter
fi
export FLUTTER_HOME
export PATH="$FLUTTER_HOME/bin:$PATH"

# The served app cannot see this environment: `php artisan serve` starts the built-in
# server with a filtered one, so DB_DATABASE, APP_KEY and the rest never reach the
# request handler — the app falls back to its own defaults and writes to a throwaway
# SQLite file inside the image, which is how a seeded admin became invisible to login.
# Writing .env from the environment keeps one source of truth for CLI and requests.
umask 077
: > .env
for name in APP_ENV APP_KEY APP_DEBUG APP_URL WEB_ORIGIN \
            DB_CONNECTION DB_DATABASE \
            SESSION_DRIVER SESSION_LIFETIME SESSION_ENCRYPT \
            QUEUE_CONNECTION CACHE_STORE LOG_CHANNEL \
            ADMIN_USERNAME ADMIN_PASSWORD \
            GIT_HOST GIT_TOKEN \
            ANDROID_SDK_PATH FUSION_ADB FUSION_ANDROID_DEVICE \
            FLUTTER_HOME \
            SIDECAR_DIR SIDECAR_SCRIPTS SIDECAR_NODE; do
  value=$(printenv "$name" 2>/dev/null || true)
  if [ -n "$value" ]; then
    printf '%s="%s"\n' "$name" "$value" >> .env
  fi
done

# The database lives on a volume so a redeploy never loses runs or credentials.
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction

# A restart cannot leave a run stuck as "running".
php artisan fusion:recover

exec "$@"
