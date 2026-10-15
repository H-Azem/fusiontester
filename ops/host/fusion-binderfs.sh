#!/bin/sh
# redroid needs binderfs, which does not survive a reboot on its own.
set -eu

modprobe binder_linux 2>/dev/null || modprobe binder 2>/dev/null || true
mkdir -p /dev/binderfs
mountpoint -q /dev/binderfs || mount -t binder binder /dev/binderfs
