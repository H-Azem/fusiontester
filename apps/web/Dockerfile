# The dashboard only needs Node: it serves the UI and proxies /api to the API.
FROM node:22-bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0

WORKDIR /app
RUN corepack enable

COPY pnpm-workspace.yaml package.json pnpm-lock.yaml ./
COPY apps/web/package.json apps/web/
COPY apps/api/package.json apps/api/
RUN pnpm install --frozen-lockfile

COPY apps/web apps/web

# The API rewrite in next.config.ts is compiled into the build, so the API URL
# has to be known at build time, not just at runtime.
ARG API_URL=http://api:4000
ENV API_URL=$API_URL
RUN pnpm --filter @fusion-tester/web build

EXPOSE 1999

CMD ["pnpm", "--filter", "@fusion-tester/web", "start"]
