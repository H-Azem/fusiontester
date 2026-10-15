# Fusion Tester — install and operations

This is the hand-over document: everything needed to put the platform on a server, hand
it to a team, and keep it alive afterwards. It describes the installer that lives in this
repository, which is the supported path — the manual route is kept at the end for
reference only.

## What it is

A Laravel API and a Next.js dashboard, plus an Android device that the API drives over
adb. A *run* clones a Flutter app, builds it, and tests it in one of two lanes:

- the **Maestro lane** — deterministic flows from the app's own `.maestro/flows`;
- the **web lane** — an Android app is built for web and driven in a browser.

A run produces steps, screenshots, artifacts and a report; failures and reports can go to
Telegram. The AI test lane (below) drives the device itself when a run asks for it.

## What runs where

```
internet -> nginx (TLS) -> web:1999 -> api:4000 (internal, never published)
                                          |
                                          +-> redroid (Android 13) at 172.17.0.1:5555
```

| Container | Image | Published | Notes |
| --- | --- | --- | --- |
| `tester-api-1` | `ghcr.io/h-azem/tester-api` | no | Laravel + the test pipeline (Flutter, a JDK, Maestro, Chromium are inside) |
| `tester-web-1` | `ghcr.io/h-azem/tester-web` | yes, `1999` | Next.js dashboard |
| `redroid-tester` | `redroid/redroid:13.0.0_64only-latest` | `5555` on the bridge | the Android device |

The images are built by GitHub Actions and published to ghcr.io, so a server deploy is a
download. Flutter, the JDK, Maestro and Chromium are **inside the api image** — nothing
for a build is installed on the host.

## What the server needs

| | |
| --- | --- |
| OS | Ubuntu 22.04 or 24.04, systemd |
| CPU | 4 cores |
| RAM | 6–8 GB, plus 2–4 GB of swap (dart2js can spike on a large app) |
| Disk | **50 GB** — images ~12 GB, Android SDK ~3 GB, device ~2.3 GB, and every run leaves a 1.6 GB workspace |
| Kernel | `binderfs` for the Android device — a **KVM** VPS. On OpenVZ the device cannot run |
| Domain | only for HTTPS |

The queue runs one test at a time, which is why 4 cores is enough. Disk is the constraint
that bites first. The installer reads free space before downloading anything and asks
before continuing on a host that is too tight.

## Install

```bash
git clone --depth 1 https://github.com/H-Azem/fusiontester.git /opt/fusiontester
cd /opt/fusiontester
sudo sh install.sh
```

The same installer works piped, which is shorter but needs the network to serve the file
rather than a cached copy:

```bash
curl -fsSL https://raw.githubusercontent.com/H-Azem/fusiontester/main/install.sh | sudo sh
```

Answers, in order:

| Question | Answer |
| --- | --- |
| Set up the Android device lane? | **y** (needs `binderfs`), or n for a web-only host |
| Put it behind nginx with a TLS certificate? | **y** and a domain, or **n** |
| *(if no TLS)* Expose the dashboard directly on the public interface? | **y** to reach it from outside, or **n** to keep it loopback-only |
| Host port for the dashboard | Enter (1999; if taken, the next free port) |
| Host port for the device's adb | Enter (5555) |

Skip the questions entirely with flags:

```bash
sudo sh install.sh --help                    # every option
sudo sh install.sh --yes                     # take every default
sudo sh install.sh --no-device               # web lane only, no kernel requirements
sudo sh install.sh --domain tester.example.com
sudo sh install.sh --web-port 4999 --device-port 5555
```

What it did, once it finishes:

- installed Docker if the host had none (`git`, `curl`, `ss`, `openssl`, `unzip` and a
  headless JRE are ensured too — the Android SDK manager is a Java program);
- put the checkout in `/opt/fusiontester` (change with `--dir`);
- wrote `.env` there (mode 600) with a fresh `APP_KEY` and a generated admin password;
- pulled the application images and started them;
- pulled the AI lane image and installed `fusion-ai-lane.service`;
- installed the Android SDK into `/root/android-sdk` (with `adb` on the PATH);
- brought up the device and the watcher units, if asked;
- set up nginx and a certificate, if asked.

It never assumes an empty server: it takes its own port, writes under `/opt/fusiontester`
and `/etc/fusion`, and leaves other services alone. Running it again updates in place.

**The dashboard listens on `127.0.0.1` by default**, because it expects a proxy in front of
it. A host with neither a proxy nor `FUSION_WEB_BIND=0.0.0.0` answers nothing from outside
— from inside the host the page loads and from outside it does not. The installer's closing
message says which of the two you got.

## First sign-in

Open the dashboard (the URL the installer printed) and sign in as **`fusion`** with the
password it printed. Change that password, then fill in Settings (which sit behind a second
password):

- **GitLab or Gitea** + a token — the same token clones the repository and authenticates
  private `git:` pub dependencies. It needs `read_api` + `read_repository`; avoid `api`.
- **AI model** — base URL, model name and key, plus the token budget for the AI lane
  (default 10,000,000, editable in the settings card).
- **Telegram** — bot token, chat and optional topic, if reports should go to a channel.
- **Flutter** — the settings card can move the SDK in the image to a volume and upgrade it
  to the latest stable, without rebuilding the image. It refuses while a run is in flight,
  and can restore the previous SDK.

## Day to day

```bash
cd /opt/fusiontester

docker compose ps                       # what is up
docker compose logs -f api              # the pipeline and the API
docker compose logs -f web              # the dashboard
docker compose restart api              # after a settings change that needs it
docker compose down && docker compose up -d

systemctl status fusion-ai-lane         # runs the lane's container when a run asks for it
systemctl status fusion-device-watch    # keeps the device alive between runs
cat /srv/fusion-control/ai-lane.log     # one line per lane start and finish
cat /srv/fusion-control/device.log      # what the device watcher is doing

docker exec tester-api-1 /root/android-sdk/platform-tools/adb devices
```

Files and units it owns:

| Path | What |
| --- | --- |
| `/opt/fusiontester` | the checkout and `.env` |
| `/etc/fusion/fusion.env` | the device's addresses (mode 600) |
| `/srv/fusion-control` | pause/resume flags and `device.log` |
| `/root/android-sdk` | the Android SDK (`adb` linked into `/usr/local/bin`) |
| `/usr/local/bin/fusion-*.sh` | the host-side helper scripts |
| `fusion-binderfs`, `fusion-device-watch` | services |
| `fusion-device-watchdog`, `fusion-manual-cleanup` | timers |
| `fusion-ai-lane` | service that runs the AI lane on request |

`.env` keys, all written by the installer: `COMPOSE_PROJECT_NAME`, `APP_KEY`,
`WEB_ORIGIN`, `ADMIN_USERNAME`, `ADMIN_PASSWORD`, `WEB_PORT`, `FUSION_WEB_BIND`,
`ANDROID_DEVICE`, `ANDROID_SDK_PATH`, `FUSION_CONTROL_PATH`.

**`APP_KEY` is the one value you cannot lose.** It encrypts the stored forge and AI tokens;
without it they must be re-entered.

## Updating

Push to `main`; CI builds and publishes new images. On the server:

```bash
cd /opt/fusiontester && sudo sh install.sh          # fetches, pulls, restarts
```

or by hand:

```bash
cd /opt/fusiontester
git pull
docker compose pull
docker compose up -d
```

The database and the workspaces live in volumes, so an update never starts from scratch.
To hold a server on a known-good build, pin the tag in `.env` instead of tracking `latest`:

```bash
FUSION_API_IMAGE=ghcr.io/h-azem/tester-api:sha-abc1234
FUSION_WEB_IMAGE=ghcr.io/h-azem/tester-web:sha-abc1234
```

## The Android device

`redroid` needs `binderfs`, which a reboot takes away. Without it the container exits the
moment it starts, and a run reports "the device did not come back after the build" rather
than anything about binderfs. Both ends are handled:

- `fusion-binderfs.service` mounts `/dev/binderfs` before Docker starts, and the device
  watcher mounts it again before every resume;
- the device is stopped deliberately while a build runs and brought back afterwards — the
  pause/resume flags in `/srv/fusion-control` are how the container asks for that without
  ever holding a Docker socket.

To check by hand:

```bash
systemctl is-active fusion-binderfs fusion-device-watch
docker ps --filter name=redroid            # Up, or stopped deliberately between runs
docker exec tester-api-1 /root/android-sdk/platform-tools/adb devices   # 172.17.0.1:5555 device
```

## Backup and restore

Two things are irreplaceable:

1. **`.env`** — `APP_KEY` in particular.
2. **The `tester_api-data` volume** — the database, every run's screenshots and artifacts.

```bash
cd /opt/fusiontester
tar czf fusion-env-$(date +%F).tgz .env

docker run --rm -v tester_api-data:/data -v "$PWD:/backup" alpine \
  tar czf /backup/api-data-$(date +%F).tgz -C /data .
```

Restore is the reverse: unpack `.env` into the checkout, then

```bash
docker run --rm -v tester_api-data:/data -v "$PWD:/backup" alpine \
  sh -c 'rm -rf /data/* && tar xzf /backup/api-data-YYYY-MM-DD.tgz -C /data'
docker compose up -d
```

Do this **before** `uninstall.sh --purge`: that is the volume it deletes. The other volumes
(Flutter SDK, pub cache, Maestro home) are rebuildable downloads and need no backup.

## Disk growth

Every run leaves its full clone behind — **1.66 GB each** — and nothing deletes them
automatically. Ten runs is ~17 GB.

```bash
docker compose exec api du -sh /app/apps/api-laravel/storage/app/fusion

# Delete workspaces older than 7 days. Those runs keep their database rows, but
# their screenshots and artifacts go with the files.
docker compose run --rm api sh -c \
  'find /app/apps/api-laravel/storage/app/fusion -mindepth 1 -maxdepth 1 -type d -mtime +7 -exec rm -rf {} +'
```

Run that from cron on a server that sees regular use. The `fusion-manual-cleanup.timer`
does the same for the device side.

## Removing it

```bash
sudo sh /opt/fusiontester/uninstall.sh              # everything, data kept
sudo sh /opt/fusiontester/uninstall.sh --purge      # data deleted too
```

The default is deliberately conservative: containers, units, scripts and the checkout go,
the volumes stay, so a reinstall picks up where the old one left off. Docker and the
Android SDK are only removed when asked (`--remove-docker`, `--remove-sdk`,
`--remove-nginx`), because another stack may be using them.

## Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| Page loads on the server, `503` or nothing from outside | The dashboard is bound to `127.0.0.1`. Set `FUSION_WEB_BIND=0.0.0.0` in `.env` and `docker compose up -d`, or install nginx (`--domain <name>`) |
| `invalid hostPort: …` when starting | `.env` holds something that is not a number as `WEB_PORT`. `grep '^WEB_PORT' .env` and correct it |
| api restarts: `The environment file is invalid! Failed to parse dotenv file` | A value in `.env` spans a line or contains quotes/spaces — usually `WEB_ORIGIN`. Correct it and `docker compose up -d` |
| A pull dies: `no space left on device` | Disk. `df -h /` and `docker system df`; grow the disk or free space |
| The Android SDK step fails | It needs a JVM and `unzip`; the installer now installs both. Anything else: re-run `sudo sh install.sh` |
| Login succeeds, then returns to sign-in | `WEB_ORIGIN` does not match the public URL exactly (scheme and host) |
| Device container exits immediately | `binderfs`: `systemctl status fusion-binderfs`, `cat /srv/fusion-control/device.log`. On OpenVZ it cannot work at all |
| Run fails at *Getting packages* | Private `git:` pub dependencies — the token in Settings must be able to read them |
| OOM-killed mid-build | Raise `mem_limit` for `api` and check that swap exists |
| The AI lane does not run | The image must be present (`docker images | grep tester-ai-tester`) and the model endpoint in Settings must answer |

## The manual route (reference)

Without the installer: Docker, `git clone`, then a `.env` with `APP_KEY`, `WEB_ORIGIN`,
`ADMIN_USERNAME=fusion` and `ADMIN_PASSWORD`, then

```bash
docker compose up -d --build
docker compose logs -f api
```

Wait for `API listening on http://0.0.0.0:4000`. The first build downloads Flutter and
Maestro, so it takes a long time; that is what the published images exist to avoid.

The AI lane is deliberately outside compose — the API has no Docker socket — so it needs
its own image and unit. The API drops a request under `/srv/fusion-control/ai/<runId>/`
and the runner, a small daemon, claims it and runs the container exactly once with
`--cap-drop ALL` and `--security-opt no-new-privileges`. It is bounded by
`FUSION_AI_TIMEOUT` (3600 seconds) and by the token budget in Settings.

```bash
sudo apps/ai-tester/build.sh     # build, install the runner, restart the unit
```

The agent inside is **pinned by commit** in its Dockerfile: an upstream release cannot
change how a lane behaves mid-flight. Its image is large (a Python environment with vision
libraries); check `docker system df` before building it locally.

If the host already has a Flutter/JDK/Maestro toolchain, `docker-compose.host-tools.yml`
mounts it instead of using the self-contained image — a ~6 GB saving on a machine that is
already set up for Flutter development.
