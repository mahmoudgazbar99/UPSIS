FROM php:8.3-apache-bookworm

ENV LANG=en_US.UTF-8 \
    LC_ALL=en_US.UTF-8 \
    LANGUAGE=en_US:en \
    COMPOSER_ALLOW_SUPERUSER=1

# Postgres runtime + headless Writer for attestation DOCX→PDF on Render.
# Full LibreOffice desktop suite stays omitted to limit image bloat.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends \
		ca-certificates \
		libzip-dev \
		libpq-dev \
		libpng-dev \
		libjpeg62-turbo-dev \
		libfreetype6-dev \
		poppler-utils \
		libreoffice-writer-nogui \
		fonts-dejavu-core \
		fonts-liberation \
		locales \
		unzip \
		git \
	&& echo "en_US.UTF-8 UTF-8" > /etc/locale.gen \
	&& locale-gen \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install pdo pdo_pgsql pgsql zip gd \
	&& curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
	&& rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers \
	&& printf '%s\n' \
		'opcache.enable=1' \
		'opcache.enable_cli=0' \
		'opcache.memory_consumption=128' \
		'opcache.interned_strings_buffer=16' \
		'opcache.max_accelerated_files=20000' \
		'opcache.validate_timestamps=0' \
		'opcache.revalidate_freq=0' \
		> /usr/local/etc/php/conf.d/opcache-render.ini

RUN echo 'default_charset = "UTF-8"' >> /usr/local/etc/php/conf.d/charset.ini && \
    echo 'mbstring.encoding_translation = On' >> /usr/local/etc/php/conf.d/charset.ini && \
    echo 'mbstring.func_overload = 0' >> /usr/local/etc/php/conf.d/charset.ini && \
    echo 'upload_max_filesize = 16M' >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo 'post_max_size = 20M' >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo 'max_execution_time = 120' >> /usr/local/etc/php/conf.d/timeouts.ini && \
    echo 'default_socket_timeout = 30' >> /usr/local/etc/php/conf.d/timeouts.ini

WORKDIR /var/www/html

# Install PHP deps before copying the full tree so Composer layer caches better.
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --no-scripts

COPY . /var/www/html/
RUN composer dump-autoload --optimize --no-dev \
	&& chown -R www-data:www-data /var/www/html \
	&& rm -f /usr/local/bin/composer

ENV PORT=10000
CMD ["bash", "/var/www/html/docker/start-render.sh"]
