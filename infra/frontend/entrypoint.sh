#!/bin/sh
set -eu

# Reconcile the Linux-only dependency volume with the committed lockfile.
pnpm install --frozen-lockfile
exec "$@"
