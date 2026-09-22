# Plain PHP app (no framework) — this just gives it a PHP+Apache runtime
# with the extensions it needs (pdo_mysql, gd for the vendored QR library),
# for hosts that only support Docker deploys (e.g. Render) rather than
# auto-detecting PHP directly (e.g. Railway, which needs no Dockerfile).
FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

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

# Render (and most container hosts) inject $PORT and expect the app to
# bind to it — Apache's default config hardcodes port 80, so this rewrites
# it at container start instead of at build time.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
