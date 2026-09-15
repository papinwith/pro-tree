#!/bin/sh
# Render (and most container hosts) pick a $PORT and expect the container
# to listen on it — default to 80 for a plain `docker run` with no $PORT
# set (e.g. testing this image locally).
set -e

PORT="${PORT:-80}"
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec "$@"
