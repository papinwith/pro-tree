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

# Uploads folder: on Railway a persistent volume is mounted here (see the
# Dockerfile). A fresh volume is empty and owned by root, so Apache
# (www-data) couldn't save photos into it. Recreate the subfolders, put back
# any seed image the volume doesn't have yet (never overwriting an existing
# file), and hand the folder to www-data. Harmless with no volume mounted.
UPLOADS=/var/www/html/public/assets/uploads
SEED=/usr/local/share/uploads-seed
for dir in tree species maps logo qr; do
    mkdir -p "$UPLOADS/$dir"
done
if [ -d "$SEED" ]; then
    (cd "$SEED" && find . -type f) | while IFS= read -r file; do
        if [ ! -e "$UPLOADS/$file" ]; then
            mkdir -p "$(dirname "$UPLOADS/$file")"
            cp -p "$SEED/$file" "$UPLOADS/$file"
        fi
    done
fi
chown -R www-data:www-data "$UPLOADS"
chmod -R u+rwX,g+rwX "$UPLOADS"

exec "$@"
