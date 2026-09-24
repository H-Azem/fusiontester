#!/bin/sh
set -eu

# Private `git:` packages in a pubspec are fetched by the container's own git,
# not by the GitLab token Fusion Tester stores for cloning. With no credentials
# in the image those fetches fail and the run stops at "Getting packages", even
# though the repository itself cloned fine.
#
# Tokens arrive as environment variables rather than build arguments so they
# never end up baked into an image layer.
if [ -n "${GIT_HOST:-}" ] && [ -n "${GIT_TOKEN:-}" ]; then
  git config --global \
    url."https://oauth2:${GIT_TOKEN}@${GIT_HOST}/".insteadOf "https://${GIT_HOST}/"
  echo "git: authenticated to ${GIT_HOST} for private pub dependencies"
else
  echo "git: GIT_HOST and GIT_TOKEN are unset - private git: pub dependencies will fail"
fi

exec "$@"
