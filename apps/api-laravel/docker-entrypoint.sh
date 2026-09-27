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

# The database lives on a volume so a redeploy never loses runs or credentials.
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction

# A restart cannot leave a run stuck as "running".
php artisan fusion:recover

exec "$@"
