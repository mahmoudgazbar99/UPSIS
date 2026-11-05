#!/bin/bash
set -euo pipefail

port="${PORT:-10000}"
hostname="${RENDER_EXTERNAL_HOSTNAME:-localhost}"
a2enmod rewrite headers >/dev/null

sed -ri "s/^Listen .*/Listen 0.0.0.0:${port}/" /etc/apache2/ports.conf
printf 'ServerName %s\n' "$hostname" > /etc/apache2/conf-available/upsis-server-name.conf
a2enconf upsis-server-name >/dev/null || true

cat > /etc/apache2/sites-available/000-default.conf <<EOF
<VirtualHost *:${port}>
    ServerName ${hostname}
    UseCanonicalName Off
    UseCanonicalPhysicalPort Off
    DocumentRoot /var/www/html/public
    <Directory /var/www/html/public>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    # Render terminates TLS at the edge and forwards HTTP + X-Forwarded-Proto.
    SetEnvIf X-Forwarded-Proto https HTTPS=on
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache /var/www/html/storage/uploads /var/www/html/storage/generated /var/www/html/storage/tmp
chown -R www-data:www-data /var/www/html/storage || true

exec apache2-foreground
