FROM php:8.2-apache

# Common PHP extensions needed by typical PDO/MySQL + HTTPS + JSON usage.
RUN docker-php-ext-install pdo_mysql

# Redis sessions (phpredis)
RUN apt-get update \
  && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
  && pecl install redis \
  && docker-php-ext-enable redis \
  && apt-get purge -y --auto-remove $PHPIZE_DEPS \
  && rm -rf /var/lib/apt/lists/*

# Enable Apache modules used by this app (.htaccess + clean rewrites).
RUN a2enmod rewrite headers

# Allow .htaccess overrides in /var/www/html.
COPY deploy/apache-allowoverride.conf /etc/apache2/conf-available/allowoverride.conf
RUN a2enconf allowoverride

# Cloud Run expects the container to listen on $PORT (default 8080).
# Apache does not expand env vars in config, so we patch Listen at runtime.
COPY deploy/docker-entrypoint.sh /usr/local/bin/mch-entrypoint.sh
RUN chmod +x /usr/local/bin/mch-entrypoint.sh

# App source
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/mch-entrypoint.sh"]
