#!/bin/sh
# Render (and most container hosts) pick a $PORT and expect the container
# to listen on it — default to 80 for a plain `docker run` with no $PORT
# set (e.g. testing this image locally).
set -e

PORT="${PORT:-80}"
sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/__PORT__/${PORT}/g" /etc/apache2/sites-available/000-default.conf

# Force exactly one MPM (prefork, required by mod_php) at container start.
# The Dockerfile already runs `a2dismod mpm_event mpm_worker && a2enmod
# mpm_prefork` at BUILD time, but on Railway the final image still had
# mpm_event.load/.conf enabled alongside mpm_prefork ("More than one MPM
# loaded" — confirmed by dumping /etc/apache2/mods-enabled/ from a debug
# build) — something in the apt-get layer re-enables Debian's default MPM
# after our fix runs. Doing it here too, at every container start right
# before Apache reads its config, is immune to whatever re-enables it
# during the build.
rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf
rm -f /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf
if [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    ln -s ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
    ln -s ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf
fi

exec "$@"
