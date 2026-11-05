FROM php:8.3-apache

ENV LANG=en_US.UTF-8 \
    LC_ALL=en_US.UTF-8 \
    LANGUAGE=en_US:en

# LibreOffice is huge and slows Render free cold starts. Certificates that need
# DOCX→PDF conversion stay available locally; Render keeps PDF preview via poppler.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends ca-certificates libzip-dev libpq-dev poppler-utils locales \
	&& echo "en_US.UTF-8 UTF-8" > /etc/locale.gen \
	&& locale-gen \
	&& docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql pgsql zip \
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
    echo 'post_max_size = 20M' >> /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

ENV PORT=10000
CMD ["bash", "/var/www/html/docker/start-render.sh"]
