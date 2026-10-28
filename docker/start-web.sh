#!/bin/bash
set -euo pipefail

a2enmod ssl rewrite headers >/dev/null
mkdir -p /etc/apache2/ssl /etc/apache2/conf-available /etc/apache2/sites-available

lan_ip="${LAN_IP:-192.168.10.11}"
test -s /etc/apache2/ssl/lan.key && test -s /etc/apache2/ssl/lan.crt || {
  echo "TLS certificate is missing; certificate-init must run before Apache." >&2
  exit 1
}

printf 'ServerName %s\n' "$lan_ip" > /etc/apache2/conf-available/upsis-server-name.conf
printf 'AddType application/x-apple-aspen-config .mobileconfig\n' > /etc/apache2/conf-available/upsis-mobileconfig.conf
a2enconf upsis-server-name upsis-mobileconfig >/dev/null

cat > /etc/apache2/conf-available/upsis-public.conf <<'EOF'
DocumentRoot /var/www/html/public

<Directory /var/www/html/public>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

<Directory /var/www/html>
    Options FollowSymLinks
    AllowOverride None
    Require all denied
</Directory>

<Directory /var/www/html/public>
    Require all granted
</Directory>
EOF
a2enconf upsis-public >/dev/null

cat > /etc/apache2/sites-available/000-default.conf <<EOF
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    ServerName ${lan_ip}
    DocumentRoot /var/www/html/public
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/certificates/upsis-lan-root-ca\.(crt|mobileconfig)$
    RewriteRule ^ https://${lan_ip}:8443%{REQUEST_URI} [R=302,L,NE]
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

cat > /etc/apache2/sites-available/lan-ssl.conf <<EOF
<VirtualHost *:443>
    ServerName ${lan_ip}
    SSLEngine on
    SSLCertificateFile /etc/apache2/ssl/lan.crt
    SSLCertificateKeyFile /etc/apache2/ssl/lan.key
    DocumentRoot /var/www/html/public
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF
a2ensite lan-ssl >/dev/null

mkdir -p /var/lib/upsis/stage-documents
chown www-data:www-data /var/lib/upsis/stage-documents || true
mkdir -p /var/www/html/storage/logs /var/www/html/storage/cache /var/www/html/storage/uploads /var/www/html/storage/generated /var/www/html/storage/tmp
chown -R www-data:www-data /var/www/html/storage || true

exec apache2-foreground
