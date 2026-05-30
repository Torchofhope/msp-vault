#!/usr/bin/env bash
# =============================================================================
# MSP-Vault Installer for Debian 13 (Trixie)
# Usage: bash install.sh  (run as root)
# =============================================================================

set -euo pipefail

# --- Configuration (edit before running) -------------------------------------
DB_NAME="mspvault"
DB_USER="mspvault"
DB_PASS="CHANGE_THIS_PASSWORD"
ADMIN_EMAIL="admin@your-domain.com"
ADMIN_FIRST="MSP"
ADMIN_LAST="Admin"
GPG_EMAIL="noreply@your-domain.com"
INSTALL_DIR="/var/www/msp-vault"
DOMAIN=""   # Leave blank to use server IP; set to "yourdomain.com" for SSL
SMTP_HOST="localhost"
SMTP_PORT="25"
SMTP_FROM_NAME="MSP-Vault"
# -----------------------------------------------------------------------------

export COMPOSER_ALLOW_SUPERUSER=1

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${GREEN}[MSP-Vault]${NC} $1"; }
warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
error()   { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

[ "$(id -u)" -ne 0 ] && error "Run as root: bash install.sh"

# Ensure sbin is in PATH (minimal Debian installs may omit it)
export PATH="$PATH:/usr/sbin:/sbin"

# Helper: run a command as www-data without requiring sudo
as_webuser() {
    su -s /bin/bash www-data -c "$*"
}

# Detect server IP if no domain set
if [ -z "$DOMAIN" ]; then
    SERVER_IP=$(hostname -I | awk '{print $1}')
    BASE_URL="http://${SERVER_IP}"
else
    BASE_URL="https://${DOMAIN}"
fi

info "Starting MSP-Vault installation..."
info "Base URL will be: ${BASE_URL}"

# =============================================================================
# 1. SYSTEM UPDATE
# =============================================================================
info "Step 1/9 — Updating system packages..."
apt-get update -qq
apt-get upgrade -y -qq

# =============================================================================
# 2. PHP + EXTENSIONS
# =============================================================================
info "Step 2/9 — Installing PHP and extensions..."

apt-get install -y -qq \
    php php-cli php-fpm \
    php-mysql php-mbstring php-intl \
    php-zip php-gd php-bcmath php-opcache \
    php-curl php-ldap php-xml \
    php-dev php-pear \
    libgpgme-dev gnupg2 \
    curl git unzip openssl

# Install nginx; if it fails, fall back to Apache2 (may already be installed)
if apt-get install -y -qq nginx 2>/dev/null; then
    WEB_SERVER="nginx"
    info "nginx installed."
elif command -v apache2 &>/dev/null || apt-get install -y -qq apache2 libapache2-mod-php 2>/dev/null; then
    WEB_SERVER="apache2"
    info "Apache2 will be used as web server."
else
    error "Could not install nginx or Apache2."
fi

# Detect actual PHP version now that php is installed
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
info "PHP ${PHP_VER} installed."

# Install php-gnupg: try apt first, fall back to PECL
if apt-get install -y -qq php-gnupg 2>/dev/null; then
    info "php-gnupg installed via apt."
else
    info "php-gnupg not in apt — installing via PECL (takes ~2 min)..."
    pecl install gnupg 2>/dev/null
    PHP_INI_DIR=$(php --ini | grep "Scan for additional" | awk '{print $NF}')
    echo "extension=gnupg.so" > "${PHP_INI_DIR}/gnupg.ini"
    info "php-gnupg installed via PECL."
fi

php -m | grep -q gnupg || error "PHP gnupg extension failed to load."

# =============================================================================
# 3. COMPOSER
# =============================================================================
info "Step 3/9 — Installing Composer 2..."
if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --quiet
fi
composer --version

# =============================================================================
# 4. MARIADB
# =============================================================================
info "Step 4/9 — Installing and configuring MariaDB..."
apt-get install -y -qq mariadb-server

systemctl enable mariadb --quiet
systemctl start mariadb

mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${DB_NAME}_test\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

info "Database '${DB_NAME}' created."

# =============================================================================
# 5. CLONE & INSTALL MSP-VAULT
# =============================================================================
info "Step 5/9 — Cloning MSP-Vault..."

git config --global --add safe.directory "$INSTALL_DIR"

if [ -d "$INSTALL_DIR" ]; then
    warning "$INSTALL_DIR already exists — pulling latest changes."
    git -C "$INSTALL_DIR" pull --quiet
else
    git clone --quiet https://github.com/Torchofhope/msp-vault.git "$INSTALL_DIR"
fi

chown -R www-data:www-data "$INSTALL_DIR"

info "Installing Composer dependencies (this takes a minute)..."
COMPOSER_ALLOW_SUPERUSER=1 as_webuser composer install \
    --working-dir="$INSTALL_DIR" \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --quiet

# =============================================================================
# 6. CONFIGURATION FILES
# =============================================================================
info "Step 6/9 — Writing configuration..."

if [ ! -f "$INSTALL_DIR/config/app.php" ]; then
    cp "$INSTALL_DIR/config/app.default.php" "$INSTALL_DIR/config/app.php"
fi

SMTP_FROM_DOMAIN="${DOMAIN:-localhost}"

cat > "$INSTALL_DIR/config/passbolt.php" <<PHP
<?php
return [
    'App' => [
        'fullBaseUrl' => '${BASE_URL}',
    ],
    'Datasources' => [
        'default' => [
            'host'     => 'localhost',
            'port'     => 3306,
            'username' => '${DB_USER}',
            'password' => '${DB_PASS}',
            'database' => '${DB_NAME}',
        ],
    ],
    'EmailTransport' => [
        'default' => [
            'host'    => '${SMTP_HOST}',
            'port'    => ${SMTP_PORT},
            'timeout' => 30,
            'tls'     => null,
        ],
    ],
    'Email' => [
        'default' => [
            'transport' => 'default',
            'from' => ['noreply@${SMTP_FROM_DOMAIN}' => '${SMTP_FROM_NAME}'],
        ],
    ],
    'passbolt' => [
        'meta' => [
            'title'       => 'MSP-Vault',
            'description' => 'Secure. Manage. Protect.',
        ],
    ],
];
PHP

chown www-data:www-data "$INSTALL_DIR/config/app.php" "$INSTALL_DIR/config/passbolt.php"

# =============================================================================
# 7. JWT + GPG KEYS
# =============================================================================
info "Step 7/9 — Generating JWT and GPG keys..."

JWT_DIR="$INSTALL_DIR/config/jwt"
mkdir -p "$JWT_DIR"

if [ ! -f "$JWT_DIR/jwt.key" ]; then
    openssl genrsa -out "$JWT_DIR/jwt.key" 4096 2>/dev/null
    openssl rsa -in "$JWT_DIR/jwt.key" -pubout -out "$JWT_DIR/jwt.pem" 2>/dev/null
    chmod 640 "$JWT_DIR/jwt.key"
    chown www-data:www-data "$JWT_DIR/jwt.key" "$JWT_DIR/jwt.pem"
    info "JWT keys generated."
else
    info "JWT keys already exist, skipping."
fi

# GPG server key — generated as www-data so the web process can use it
GPG_DIR="$INSTALL_DIR/config/gpg"
mkdir -p "$GPG_DIR"
chmod 700 "$GPG_DIR"
chown www-data:www-data "$GPG_DIR"

# Set GNUPGHOME for this session
export GNUPGHOME="$GPG_DIR"

if ! as_webuser gpg --list-secret-keys "$GPG_EMAIL" &>/dev/null; then
    as_webuser gpg --batch --gen-key 2>/dev/null <<GPGEOF
%no-protection
Key-Type: RSA
Key-Length: 4096
Subkey-Type: RSA
Subkey-Length: 4096
Name-Real: MSP-Vault Server
Name-Email: ${GPG_EMAIL}
Expire-Date: 0
GPGEOF
    info "GPG key generated for ${GPG_EMAIL}"
fi

GPG_FPR=$(as_webuser gpg --list-keys --with-colons "$GPG_EMAIL" 2>/dev/null \
    | awk -F: '/^fpr/{print $10; exit}')

as_webuser gpg --armor --export "$GPG_EMAIL" \
    > "$GPG_DIR/serverkey.asc" 2>/dev/null
as_webuser gpg --armor --export-secret-keys "$GPG_EMAIL" \
    > "$GPG_DIR/serverkey_private.asc" 2>/dev/null

chmod 640 "$GPG_DIR/serverkey_private.asc"
chown www-data:www-data "$GPG_DIR/serverkey.asc" "$GPG_DIR/serverkey_private.asc"
info "GPG fingerprint: ${GPG_FPR}"

# Append GPG config to passbolt.php
cat >> "$INSTALL_DIR/config/passbolt.php" <<PHP

// GPG server key — appended by installer
\$config['passbolt']['gpg'] = [
    'serverKey' => [
        'fingerprint' => '${GPG_FPR}',
        'public'      => '${GPG_DIR}/serverkey.asc',
        'private'     => '${GPG_DIR}/serverkey_private.asc',
    ],
];
PHP

# =============================================================================
# 8. DATABASE MIGRATIONS
# =============================================================================
info "Step 8/9 — Running database migrations..."

cd "$INSTALL_DIR"

# Ensure bin/cake is executable
chmod +x bin/cake

run_migration() {
    local label=$1
    local plugin_arg=${2:-""}
    info "  Migrating: ${label}"
    php bin/cake.php migrations migrate ${plugin_arg} --no-lock 2>&1 | tail -3
}

run_migration "Core"
run_migration "MultiTenant"        "--plugin Passbolt/MultiTenant"
run_migration "Devices"            "--plugin Passbolt/Devices"
run_migration "CredentialRotation" "--plugin Passbolt/CredentialRotation"
run_migration "CredentialEscrow"   "--plugin Passbolt/CredentialEscrow"
run_migration "MspEntraId"         "--plugin Passbolt/MspEntraId"
run_migration "SuperOpsSync"       "--plugin Passbolt/SuperOpsSync"

info "Creating admin user: ${ADMIN_EMAIL}"
php bin/cake.php passbolt register_user \
    --username   "$ADMIN_EMAIL" \
    --first-name "$ADMIN_FIRST" \
    --last-name  "$ADMIN_LAST" \
    --role admin 2>&1 | tail -5 \
    || warning "Admin user may already exist — continuing."

# =============================================================================
# 9. WEB SERVER CONFIGURATION
# =============================================================================
info "Step 9/9 — Configuring web server (${WEB_SERVER})..."

if [ "$WEB_SERVER" = "nginx" ]; then

    # Stop Apache2 if it's running so it doesn't conflict
    systemctl stop apache2 2>/dev/null || true
    systemctl disable apache2 2>/dev/null || true

    cat > /etc/nginx/sites-available/msp-vault <<NGINX
server {
    listen 80;
    server_name ${DOMAIN:-_};
    root ${INSTALL_DIR}/webroot;
    index index.php;

    client_max_body_size 5m;

    location / {
        try_files \$uri \$uri/ /index.php?\$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php${PHP_VER}-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 120;
    }

    location ~ /\.                { deny all; }
    location ~* \.(log|key|pem)$ { deny all; }
}
NGINX

    ln -sf /etc/nginx/sites-available/msp-vault /etc/nginx/sites-enabled/msp-vault
    rm -f /etc/nginx/sites-enabled/default

    systemctl enable "php${PHP_VER}-fpm" --quiet
    systemctl restart "php${PHP_VER}-fpm"
    nginx -t && systemctl reload nginx
    systemctl enable nginx --quiet

else
    # Apache2 path
    apt-get install -y -qq libapache2-mod-php 2>/dev/null || true
    a2enmod rewrite php${PHP_VER} 2>/dev/null || a2enmod rewrite 2>/dev/null || true

    cat > /etc/apache2/sites-available/msp-vault.conf <<APACHE
<VirtualHost *:80>
    ServerName ${DOMAIN:-_}
    DocumentRoot ${INSTALL_DIR}/webroot

    <Directory ${INSTALL_DIR}/webroot>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/msp-vault-error.log
    CustomLog \${APACHE_LOG_DIR}/msp-vault-access.log combined
</VirtualHost>
APACHE

    a2ensite msp-vault.conf
    a2dissite 000-default.conf 2>/dev/null || true
    systemctl restart apache2
    systemctl enable apache2 --quiet

fi

# =============================================================================
# CRON JOBS
# =============================================================================
info "Setting up cron jobs..."

cat > /etc/cron.d/msp-vault <<CRON
# MSP-Vault scheduled jobs
0    * * * * www-data cd ${INSTALL_DIR} && bin/cake msp_vault rotation:run_scheduled >> /var/log/msp-vault-rotation.log 2>&1
*/30 * * * * www-data cd ${INSTALL_DIR} && bin/cake msp_vault superops:sync >> /var/log/msp-vault-superops.log 2>&1
*    * * * * www-data cd ${INSTALL_DIR} && bin/cake email_queue send >> /var/log/msp-vault-email.log 2>&1
CRON

chmod 644 /etc/cron.d/msp-vault

# =============================================================================
# FILE PERMISSIONS
# =============================================================================
chown -R www-data:www-data "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 755 {} \;
find "$INSTALL_DIR" -type f -exec chmod 644 {} \;
chmod 640 "$INSTALL_DIR/config/passbolt.php"
chmod 640 "$INSTALL_DIR/config/jwt/jwt.key"
chmod 700 "$INSTALL_DIR/config/gpg"
chmod 640 "$INSTALL_DIR/config/gpg/serverkey_private.asc"

# =============================================================================
# OPTIONAL SSL
# =============================================================================
if [ -n "$DOMAIN" ]; then
    info "Installing SSL certificate for ${DOMAIN}..."
    apt-get install -y -qq certbot python3-certbot-nginx
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
        -m "$ADMIN_EMAIL" --redirect \
        || warning "SSL setup failed — run manually: certbot --nginx -d ${DOMAIN}"
fi

# =============================================================================
# DONE
# =============================================================================
echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  MSP-Vault installation complete!         ${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo -e "  URL:         ${GREEN}${BASE_URL}${NC}"
echo -e "  Admin login: ${GREEN}${ADMIN_EMAIL}${NC}"
echo -e "  GPG key:     ${GREEN}${GPG_FPR}${NC}"
echo -e "  Install dir: ${GREEN}${INSTALL_DIR}${NC}"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "  1. Visit ${BASE_URL} — complete the admin account setup"
echo "  2. Edit ${INSTALL_DIR}/config/passbolt.php to configure SMTP"
if [ -z "$DOMAIN" ]; then
echo "  3. Point a domain at this server and re-run with DOMAIN set for SSL"
fi
echo ""
echo "  Logs:   /var/log/msp-vault-*.log"
echo "  Config: ${INSTALL_DIR}/config/passbolt.php"
echo ""
