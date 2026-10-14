# syntax=docker/dockerfile:1
# The AI lane's brain: a multimodal Android agent driving the app on the device.
#
# Nothing else in the platform is reachable from here — no repository mount, no
# database, no docker socket. The only hosts it may talk to are the Android
# device over adb and the run's model endpoint.
#
# The agent is pinned by commit: a surprise release cannot change how a lane
# behaves mid-flight, and the image is rebuilt deliberately.
FROM python:3.12-slim-bookworm

ARG ARTEMIS_REF=351ca8422f7b5b54e80a9c1ce03a222e02415b6b

ENV DEBIAN_FRONTEND=noninteractive \
    UV_LINK_MODE=copy \
    UV_PROJECT_ENVIRONMENT=/opt/artemis/.venv \
    UV_CACHE_DIR=/tmp/uv-cache

# adb is the agent's hands; git is only needed to fetch the pinned source. The
# rest are the shared libraries OpenCV (imported at start-up) links against; the
# slim base does not carry them.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      adb ca-certificates git \
      libgl1 libglib2.0-0 libxcb1 libsm6 libxext6 libxrender1 libxfixes3 libgomp1 \
 && rm -rf /var/lib/apt/lists/*

COPY --from=ghcr.io/astral-sh/uv:0.13.1 /uv /usr/local/bin/uv

# Never root, and never a uid that exists on the host: a container's own files are
# charged to its uid, so picking a uid that a host account already owns makes every
# write count against that account's disk quota (which is how a lane run can fail
# with "quota exceeded" on a host that has gigabytes free). The high uid below is
# deliberately unassigned. Everything after this line is built as that user, so
# nothing is handed over with a chown pass over a 1.5 GB tree.
RUN useradd --create-home --uid 10001 --shell /bin/sh tester \
 && mkdir -p /opt/artemis /opt/lane /opt/patches \
 && chown tester:tester /opt/artemis /opt/lane

USER tester
WORKDIR /opt/lane

RUN git clone --depth 1 https://github.com/google/artemis.git /opt/artemis \
 && cd /opt/artemis \
 && git fetch --depth 1 origin "${ARTEMIS_REF}" \
 && git checkout --detach FETCH_HEAD \
 && rm -rf .git

# One source change: the agent's history-chunking model must use the run's own
# provider instead of hard-coding Google. The script fails the build if the
# anchor has moved, so the image can never drift silently.
COPY --chown=tester:tester patches/0001-non-google-provider.py /opt/patches/0001-non-google-provider.py
RUN python /opt/patches/0001-non-google-provider.py /opt/artemis

# The lane runner and the config deltas it merges into the agent's own config.
COPY --chown=tester:tester lane.py /opt/lane/lane.py
COPY --chown=tester:tester artemis.overrides.jsonc /opt/lane/artemis.overrides.jsonc

# The environment lands already owned by the lane's user. Extraction scratch is a
# tmpfs: on hosts whose overlay is under a tight quota, uv's many small writes
# otherwise fail with EDQUOT even though the disk has room.
RUN --mount=type=tmpfs,target=/tmp \
    cd /opt/artemis \
 && uv sync --frozen --no-dev --no-cache \
 && rm -rf "${UV_CACHE_DIR}"

ENTRYPOINT ["/opt/artemis/.venv/bin/python", "/opt/lane/lane.py"]
