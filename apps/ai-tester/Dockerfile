# The AI lane's brain: the Command Code CLI, driving the app on the device.
#
# Nothing else in the platform is reachable from here — no repository mount, no
# database, no docker socket. The only host it may talk to is the Android device
# over adb, and the only tool the agent may run is adb: that is enforced
# fail-closed by the CLI's own permission engine (see settings.json), not by
# convention or by asking the model nicely.
FROM node:22-bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive

# adb is the agent's only tool. The platform-tools client needs no privileges.
RUN apt-get update \
 && apt-get install -y --no-install-recommends adb ca-certificates passwd \
 && rm -rf /var/lib/apt/lists/*

# The brain itself. Pinned by major so a surprise breaking release cannot change
# how a run behaves mid-flight; the image is rebuilt deliberately.
RUN npm install -g command-code --no-audit --no-fund

RUN useradd --create-home --shell /bin/sh tester \
 && mkdir -p /home/tester/lane/.commandcode /home/tester/.commandcode

# The pipeline's own workflows have no business here, and taste learning would
# only add model calls. The lane does one thing: run the mission.
COPY settings.json /home/tester/.commandcode/settings.json
COPY settings.json /home/tester/lane/.commandcode/settings.json
COPY entrypoint.sh /usr/local/bin/ai-tester-entrypoint.sh

RUN chmod +x /usr/local/bin/ai-tester-entrypoint.sh \
 && chown -R tester:tester /home/tester

# Never root. A compromised agent owns nothing but its own home and the device.
USER tester
WORKDIR /home/tester/lane

ENTRYPOINT ["/usr/local/bin/ai-tester-entrypoint.sh"]
