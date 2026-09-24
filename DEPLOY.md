# Deploying Fusion Tester on Ubuntu

Two containers and one public port. The API image carries every tool a run
shells out to — Flutter, a JDK, Maestro, Chromium and git — so the server needs
nothing pre-installed beyond Docker.

```
internet -> nginx (TLS) -> web:1999 -> api:4000 (internal, never published)
```

## What the server needs

Measured against a real Flutter app, not estimated:

| Stage | Cost |
| --- | --- |
| `flutter build web` | ~0.9 GB peak RAM, 49 MB output |
| Per-run workspace on disk | **1.66 GB** (source + `.git` + build) |
| Toolchain in the image | Flutter 3 GB, pub cache 1.6 GB |

A 4-core / 6 GB server is enough, because the queue runs **one test at a time**.
Disk is the constraint that bites first — budget **40 GB** and see *Disk growth*
below. Add 2–4 GB of swap: dart2js can spike on a large app, and swapping beats
being OOM-killed.

## 1. Install Docker

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"   # log out and back in
docker compose version
```

## 2. Get the code and configure

```bash
git clone https://github.com/H-Azem/fusiontester.git
cd fusiontester
cp .env.example .env
```

Edit `.env`:

- **`WEB_ORIGIN`** — the public URL, exactly (`https://tester.example.com`).
  Login origin checks compare against it; a mismatch shows up as a login that
  succeeds and then bounces straight back to the sign-in page.
- **`APP_SECRET`** — generate with `openssl rand -base64 48`. Losing or changing
  it makes every stored GitLab token unreadable.
- **`GIT_HOST` / `GIT_TOKEN`** — only if the apps you test declare private
  `git:` packages in their `pubspec.yaml`. A token with `read_repository` is
  enough. Without these, such a run fails at *Getting packages*.

## 3. Build and start

```bash
docker compose up -d --build
docker compose logs -f api
```

The first build downloads Flutter and Maestro, so it takes a while. Wait for:

```
git: authenticated to <host> for private pub dependencies
API listening on http://0.0.0.0:4000
```

## 4. First login

Open the site and sign in with `admin` / `admin`, then **change the password
immediately** from the Account page. The dashboard is reachable from the
internet, and the default is public knowledge.

## 5. Connect GitLab

Settings → GitLab. The token needs **`read_api` + `read_repository`**:

- `read_api` alone lists projects but cannot read repository contents.
- `read_repository` alone cannot list projects (`403 insufficient_scope`).
- Avoid `api` — it grants write access you do not need.

Test the connection before relying on it. The API is reachable only from inside
the compose network, so use the dashboard rather than curling port 4000.

## 6. Put it behind nginx

The compose file binds the dashboard to `127.0.0.1:1999`, so it is not publicly
reachable until the proxy is configured.

```nginx
server {
    listen 80;
    server_name tester.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name tester.example.com;

    ssl_certificate     /etc/letsencrypt/live/tester.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tester.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:1999;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        # Required: the API runs with TRUST_PROXY=true and reads this header to
        # apply per-IP login blocks. Without it everyone shares one bucket.
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

```bash
sudo certbot --nginx -d tester.example.com
```

## Updating

```bash
git pull
docker compose up -d --build
```

Dependencies are cached in named volumes, so rebuilds after a code change are
much faster than the first one.

## Backups

Two things are irreplaceable:

1. **`.env`** — without `APP_SECRET`, the stored GitLab token cannot be
   decrypted and must be re-entered.
2. **The `api-data` volume** — the embedded database plus every run's
   screenshots and artifacts.

```bash
docker run --rm -v fusiontester_api-data:/data -v "$PWD:/backup" alpine \
  tar czf /backup/api-data.tgz -C /data .
```

## Disk growth

Every run leaves its full clone behind — **1.66 GB each** — and nothing deletes
them automatically. Ten runs is ~17 GB.

```bash
# How much is in use
docker compose exec api du -sh /app/apps/api/.data/runs

# Delete workspaces older than 7 days (frees the space; those runs keep their
# database rows, but their screenshots and artifacts are gone)
docker compose run --rm api sh -c \
  'find /app/apps/api/.data/runs -mindepth 1 -maxdepth 1 -type d -mtime +7 -exec rm -rf {} +'
```

Run that from cron if the server sees regular use.

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| Login succeeds, then returns to sign-in | `WEB_ORIGIN` does not match the public URL exactly |
| Run fails at *Getting packages* | Private `git:` pub dependencies — set `GIT_HOST`/`GIT_TOKEN` |
| `403 insufficient_scope` listing projects | Token is missing `read_api` |
| Everyone is blocked at once after failed logins | NAT: one office IP is shared, and blocks are per IP |
| Container exits repeatedly | `docker compose logs api` — usually `APP_SECRET` unset |
| Runs fail at *Opening in a browser* | Chromium missing; check `CHROME_PATH=/usr/bin/chromium` |
| OOM-killed mid-build | Raise `mem_limit` and check that swap exists |

## Notes

- The API port is deliberately unpublished. Reach it through the dashboard.
- `init: true` reaps the git/Flutter/Chromium children a run spawns, so a
  restart cannot leave a build running in the background.
- `PGLITE_DIR` points at the mounted volume, so the database survives rebuilds.
