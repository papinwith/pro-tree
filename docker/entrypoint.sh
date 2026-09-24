#!/bin/sh
# Render (and most container hosts) pick a $PORT and expect the container
# to listen on it — default to 80 for a plain `docker run` with no $PORT
# set (e.g. testing this image locally).
set -e

PORT="${PORT:-80}"
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# TEMPORARY diagnostic (2026-09-24): Apache is failing to start on Railway
# with "More than one MPM loaded", even though the Dockerfile explicitly
# disables mpm_event/mpm_worker and enables mpm_prefork at build time.
# Dump the actual runtime module state to the deploy logs so this can be
# debugged without a live shell (SSH isn't available while the container
# keeps exiting). Remove this block once the real cause is found.
echo "--- DEBUG: mods-enabled/*mpm* ---"
ls -la /etc/apache2/mods-enabled/ | grep -i mpm || echo "(no mpm entries found)"
echo "--- DEBUG: apache2ctl -M ---"
apache2ctl -M 2>&1 || true
echo "--- END DEBUG ---"

exec "$@"
