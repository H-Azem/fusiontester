#!/bin/sh
set -eu

cd /app/apps/api-laravel

# Private `git:` packages in a pubspec are fetched by the container's own git,
# not by the GitLab token stored for cloning. Tokens arrive as environment
# variables rather than build arguments so they never end up in an image layer.
if [ -n "${GIT_HOST:-}" ] && [ -n "${GIT_TOKEN:-}" ]; then
  git config --global \
    url."https://oauth2:${GIT_TOKEN}@${GIT_HOST}/".insteadOf "https://${GIT_HOST}/"
  echo "git: authenticated to ${GIT_HOST} for private pub dependencies"
else
  echo "git: GIT_HOST and GIT_TOKEN are unset - private git: pub dependencies will fail"
fi

if [ -z "${APP_KEY:-}" ]; then
  echo "APP_KEY is required (php artisan key:generate --show)" >&2
  exit 1
fi

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
