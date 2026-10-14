#!/bin/sh
# Build the lane image and (as root) install the host-side pieces around it.
#
#   ./build.sh              build the image only
#   sudo ./build.sh         build, install the runner, restart the service
#
# The image is rebuilt deliberately, not on every push: the agent inside is
# pinned by commit so a run's behaviour cannot change underneath it.
set -eu

here="$(cd "$(dirname "$0")" && pwd)"
image="${FUSION_AI_IMAGE:-tester-ai-tester}"

docker build -t "$image" "$here"
echo "built $image"

if [ "$(id -u)" -eq 0 ]; then
    install -m 755 "$here/runner/fusion-ai-lane.sh" /usr/local/bin/fusion-ai-lane.sh
    install -m 644 "$here/runner/fusion-ai-lane.service" /etc/systemd/system/fusion-ai-lane.service
    systemctl daemon-reload
    systemctl restart fusion-ai-lane.service
    echo "installed the host runner and restarted fusion-ai-lane"
fi
