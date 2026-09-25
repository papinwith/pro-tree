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

RUN a2enmod rewrite expires deflate

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

COPY docker/php-app.ini /usr/local/etc/php/conf.d/zz-app.ini

# Replaces the base image's default vhost with our own template (see the
# file itself for why: Railway/any TLS-terminating proxy forwards plain
# HTTP internally, and Apache's own auto-redirects otherwise leak the
# internal host:port). entrypoint.sh substitutes __PORT__ at container
# start.
COPY docker/vhost.conf.template /etc/apache2/sites-available/000-default.conf

# Render (and most container hosts) inject $PORT and expect the app to
# bind to it — Apache's default config hardcodes port 80, so this rewrites
# it at container start instead of at build time.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
