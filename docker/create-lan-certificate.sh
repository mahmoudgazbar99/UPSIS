#!/bin/bash
set -euo pipefail

lan_ip="${LAN_IP:-192.168.10.11}"

mkdir -p /tls /ca /public

if [[ ! -s /ca/lan-ca.key || ! -s /ca/lan-ca.crt ]]; then
  openssl req -x509 -newkey rsa:3072 -sha256 -days 3650 -nodes -keyout /ca/lan-ca.key -out /ca/lan-ca.crt -subj "/CN=UPSIS Local Network CA" -addext "basicConstraints=critical,CA:TRUE,pathlen:0" -addext "keyUsage=critical,keyCertSign,cRLSign"
  chmod 600 /ca/lan-ca.key
fi

if [[ ! -s /tls/lan.key || ! -s /tls/lan.crt ]] || ! openssl x509 -in /tls/lan.crt -checkip "$lan_ip" -noout >/dev/null 2>&1; then
  openssl req -new -newkey rsa:2048 -nodes -keyout /tls/lan.key -out /tmp/lan.csr -subj "/CN=${lan_ip}"

  cat > /tmp/lan-ext.cnf <<EOF
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=IP:${lan_ip},IP:127.0.0.1,DNS:localhost
EOF

  openssl x509 -req -in /tmp/lan.csr -CA /ca/lan-ca.crt -CAkey /ca/lan-ca.key -CAcreateserial -out /tls/lan.crt -days 825 -sha256 -extfile /tmp/lan-ext.cnf
  chmod 600 /tls/lan.key
fi

chmod 644 /tls/lan.crt /ca/lan-ca.crt
cp /ca/lan-ca.crt /public/upsis-lan-root-ca.crt

root_cert_b64="$(base64 -w 0 /ca/lan-ca.crt)"
cat > /public/upsis-lan-root-ca.mobileconfig <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>PayloadContent</key>
  <array>
    <dict>
      <key>PayloadContent</key>
      <data>${root_cert_b64}</data>
      <key>PayloadDescription</key>
      <string>Trust the UPSIS local network web certificate.</string>
      <key>PayloadDisplayName</key>
      <string>UPSIS Local Network CA</string>
      <key>PayloadIdentifier</key>
      <string>tn.upsis.localca.root</string>
      <key>PayloadType</key>
      <string>com.apple.security.root</string>
      <key>PayloadUUID</key>
      <string>cd8de20a-1bd3-4ad9-9308-7218d978b6f2</string>
      <key>PayloadVersion</key>
      <integer>1</integer>
    </dict>
  </array>
  <key>PayloadDescription</key>
  <string>Installs the UPSIS local network certificate authority.</string>
  <key>PayloadDisplayName</key>
  <string>UPSIS Local Network CA</string>
  <key>PayloadIdentifier</key>
  <string>tn.upsis.localca.profile</string>
  <key>PayloadOrganization</key>
  <string>UPSIS</string>
  <key>PayloadRemovalDisallowed</key>
  <false/>
  <key>PayloadType</key>
  <string>Configuration</string>
  <key>PayloadUUID</key>
  <string>ecb637f2-4dcc-45f2-8b22-162580ad1cd4</string>
  <key>PayloadVersion</key>
  <integer>1</integer>
</dict>
</plist>
EOF
