# Plain PHP app (no framework) — this just gives it a PHP+Apache runtime
# with the extensions it needs (pdo_pgsql for Supabase, gd for the vendored
# QR library), for hosts that only support Docker deploys (e.g. Render)
# rather than auto-detecting PHP directly (e.g. Railway, which needs no
# Dockerfile).
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libpq-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# The apt-get install above pulls in a newer apache2 package as a dependency,
# which re-enables Debian's default mpm_event alongside the mpm_prefork that
# the base php:8.2-apache image already switched to for mod_php (which isn't
# thread-safe) — having both loaded is a fatal Apache config error
# ("More than one MPM loaded") that prevented Apache from starting at all on
# Railway, causing every single request (even static files) to 502.
RUN (a2dismod mpm_event || true) && (a2dismod mpm_worker || true) && a2enmod mpm_prefork

RUN a2enmod rewrite headers

# Serve the whole repo (not just public/) so both /public/... (visitor
# pages) and /admin/... (admin panel) are reachable, same as running
# `php -S localhost:8000` from the project root — see SETUP.md.
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/public/assets/uploads

# public/.htaccess needs AllowOverride On for its pretty-URL rewrite rule.
RUN { \
        echo '<Directory /var/www/html/>'; \
        echo '    AllowOverride All'; \
        echo '    Require all granted'; \
        echo '</Directory>'; \
    } > /etc/apache2/conf-available/allow-override.conf \
    && a2enconf allow-override

# Railway (and every other TLS-terminating proxy host) forwards requests to
# this container as plain HTTP on its internal $PORT. Apache doesn't know
# the outside world is actually on https/443, so any auto-redirect it
# generates itself -- e.g. mod_dir adding the trailing slash for a bare
# /admin or /public request -- comes out as "http://host:8080/admin/"
# instead of "https://host/admin/". That's not reachable from outside the
# container, so visiting /admin or /public without a trailing slash 301'd
# into a dead end. Rewrite the scheme/port back on the way out instead of
# trying to make Apache aware of the proxy (simpler and host-agnostic).
RUN { \
        echo 'Header always edit Location "^http://([^/:]+):[0-9]+/" "https://$1/"'; \
    } > /etc/apache2/conf-available/fix-redirect-scheme.conf \
    && a2enconf fix-redirect-scheme

# Render (and most container hosts) inject $PORT and expect the app to
# bind to it — Apache's default config hardcodes port 80, so this rewrites
# it at container start instead of at build time.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
