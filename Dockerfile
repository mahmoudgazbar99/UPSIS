FROM php:8.3-apache

ENV LANG=en_US.UTF-8 \
    LC_ALL=en_US.UTF-8 \
    LANGUAGE=en_US:en

RUN apt-get update \
	&& apt-get install -y --no-install-recommends ca-certificates libzip-dev libpq-dev libreoffice-writer poppler-utils locales \
	&& echo "en_US.UTF-8 UTF-8" > /etc/locale.gen \
	&& locale-gen \
	&& docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql pgsql zip \
	&& rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

RUN echo 'default_charset = "UTF-8"' >> /usr/local/etc/php/conf.d/charset.ini && \
    echo 'mbstring.encoding_translation = On' >> /usr/local/etc/php/conf.d/charset.ini && \
    echo 'mbstring.func_overload = 0' >> /usr/local/etc/php/conf.d/charset.ini && \
    echo 'upload_max_filesize = 16M' >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo 'post_max_size = 20M' >> /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

ENV PORT=10000
CMD ["bash", "/var/www/html/docker/start-render.sh"]
