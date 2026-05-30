#!/usr/bin/env bash
# =============================================================================
# MSP-Vault Installer for Debian 13 (Trixie)
# Usage: sudo bash install.sh
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

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${GREEN}[MSP-Vault]${NC} $1"; }
warning() { echo -e "${YELLOW}[WARNING]${NC} $1"; }
error()   { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

[ "$(id -u)" -ne 0 ] && error "Run as root: sudo bash install.sh"

# Auto-detect available PHP version (prefers 8.3, falls back to 8.2 or 8.4)
detect_php_version() {
    for ver in 8.3 8.2 8.4; do
        if apt-cache show "php${ver}" &>/dev/null 2>&1; then
            echo "$ver"
            return
        fi
    done
    error "No supported PHP version (8.2/8.3/8.4) found in apt. Run: apt-get update first."
}
PHP_VER=$(detect_php_version)
info "Detected PHP version: ${PHP_VER}"

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
# 2. PHP 8.2 + EXTENSIONS
# =============================================================================
info "Step 2/9 — Installing PHP 8.2 and extensions..."

# Debian 13 ships PHP 8.2 in main repos
apt-get install -y -qq \
    php${PHP_VER} php${PHP_VER}-cli php${PHP_VER}-fpm \
    php${PHP_VER}-mysql php${PHP_VER}-mbstring php${PHP_VER}-intl \
    php${PHP_VER}-zip php${PHP_VER}-gd php${PHP_VER}-bcmath php${PHP_VER}-opcache \
    php${PHP_VER}-curl php${PHP_VER}-ldap php${PHP_VER}-xml php${PHP_VER}-gnupg \
    gnupg2 curl git unzip openssl nginx

# Verify gnupg extension is available
php -m | grep -q gnupg || error "PHP gnupg extension not loaded. Check: apt install php${PHP_VER}-gnupg"

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

# Create database and user
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

if [ -d "$INSTALL_DIR" ]; then
    warning "$INSTALL_DIR already exists — pulling latest changes."
    git -C "$INSTALL_DIR" pull --quiet
else
    git clone --quiet https://github.com/Torchofhope/msp-vault.git "$INSTALL_DIR"
fi

chown -R www-data:www-data "$INSTALL_DIR"

info "Installing Composer dependencies (this takes a minute)..."
sudo -u www-data composer install \
    --working-dir="$INSTALL_DIR" \
    --no-dev \
    --no-interaction \
    --optimize-autoloader \
    --quiet

# =============================================================================
# 6. CONFIGURATION FILES
# =============================================================================
info "Step 6/9 — Writing configuration..."

# app.php
if [ ! -f "$INSTALL_DIR/config/app.php" ]; then
    sudo -u www-data cp "$INSTALL_DIR/config/app.default.php" "$INSTALL_DIR/config/app.php"
fi

# passbolt.php — generate a fresh one with our settings
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
            'host' => '${SMTP_HOST}',
            'port' => ${SMTP_PORT},
            'timeout' => 30,
            'tls'  => null,
        ],
    ],
    'Email' => [
        'default' => [
            'transport' => 'default',
            'from' => ['noreply@${DOMAIN:-localhost}' => '${SMTP_FROM_NAME}'],
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

chown www-data:www-data "$INSTALL_DIR/config/passbolt.php"

# =============================================================================
# 7. JWT KEYS
# =============================================================================
info "Step 7/9 — Generating JWT and GPG keys..."

JWT_DIR="$INSTALL_DIR/config/jwt"
sudo -u www-data mkdir -p "$JWT_DIR"

if [ ! -f "$JWT_DIR/jwt.key" ]; then
    sudo -u www-data openssl genrsa -out "$JWT_DIR/jwt.key" 4096 2>/dev/null
    sudo -u www-data openssl rsa -in "$JWT_DIR/jwt.key" -pubout -out "$JWT_DIR/jwt.pem" 2>/dev/null
    chmod 640 "$JWT_DIR/jwt.key"
    info "JWT keys generated."
else
    info "JWT keys already exist, skipping."
fi

# GPG server key
GPG_DIR="$INSTALL_DIR/config/gpg"
sudo -u www-data mkdir -p "$GPG_DIR"
export GNUPGHOME="$GPG_DIR"
chown www-data:www-data "$GPG_DIR"
chmod 700 "$GPG_DIR"

if ! sudo -u www-data gpg --list-secret-keys "$GPG_EMAIL" &>/dev/null; then
    sudo -u www-data gpg --batch --gen-key 2>/dev/null <<GPGEOF
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

GPG_FPR=$(sudo -u www-data gpg --list-keys --with-colons "$GPG_EMAIL" 2>/dev/null | awk -F: '/^fpr/{print $10; exit}')
sudo -u www-data gpg --armor --export "$GPG_EMAIL" > "$GPG_DIR/serverkey.asc" 2>/dev/null
sudo -u www-data gpg --armor --export-secret-keys "$GPG_EMAIL" > "$GPG_DIR/serverkey_private.asc" 2>/dev/null
chmod 640 "$GPG_DIR/serverkey_private.asc"

# Append GPG config to passbolt.php
cat >> "$INSTALL_DIR/config/passbolt.php" <<PHP

// Appended by installer — GPG server key config
\$config['passbolt']['gpg'] = [
    'serverKey' => [
        'fingerprint' => '${GPG_FPR}',
        'public'  => '${GPG_DIR}/serverkey.asc',
        'private' => '${GPG_DIR}/serverkey_private.asc',
    ],
];
PHP

# Fix the PHP file — remove the closing ?> so appended code is valid
# (passbolt.php doesn't use closing tags — this is correct as-is)

chown www-data:www-data "$GPG_DIR/serverkey.asc" "$GPG_DIR/serverkey_private.asc"
info "GPG fingerprint: ${GPG_FPR}"

# =============================================================================
# 8. DATABASE MIGRATIONS
# =============================================================================
info "Step 8/9 — Running database migrations..."

cd "$INSTALL_DIR"

run_migration() {
    local label=$1
    local args=${2:-""}
    info "  Migrating: ${label}"
    sudo -u www-data bin/cake migrations migrate $args --no-lock 2>&1 | tail -3
}

run_migration "Core"
run_migration "MultiTenant"      "--plugin Passbolt/MultiTenant"
run_migration "Devices"          "--plugin Passbolt/Devices"
run_migration "CredentialRotation" "--plugin Passbolt/CredentialRotation"
run_migration "CredentialEscrow" "--plugin Passbolt/CredentialEscrow"
run_migration "MspEntraId"       "--plugin Passbolt/MspEntraId"
run_migration "SuperOpsSync"     "--plugin Passbolt/SuperOpsSync"

# Seed admin user
info "Creating admin user: ${ADMIN_EMAIL}"
sudo -u www-data bin/cake passbolt register_user \
    --username  "$ADMIN_EMAIL" \
    --first-name "$ADMIN_FIRST" \
    --last-name  "$ADMIN_LAST" \
    --role admin 2>&1 | tail -5 || warning "Admin user may already exist."

# =============================================================================
# 9. NGINX
# =============================================================================
info "Step 9/9 — Configuring nginx..."

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

    location ~ /\. { deny all; }
    location ~* \.(log|key|pem)$ { deny all; }
}
NGINX

ln -sf /etc/nginx/sites-available/msp-vault /etc/nginx/sites-enabled/msp-vault
rm -f /etc/nginx/sites-enabled/default

nginx -t && systemctl reload nginx
systemctl enable nginx --quiet

# =============================================================================
# CRON JOBS
# =============================================================================
info "Setting up cron jobs..."

CRON_FILE="/etc/cron.d/msp-vault"
cat > "$CRON_FILE" <<CRON
# MSP-Vault scheduled jobs
# Credential rotation check — every hour
0 * * * * www-data cd ${INSTALL_DIR} && bin/cake msp_vault rotation:run_scheduled >> /var/log/msp-vault-rotation.log 2>&1

# SuperOps bidirectional sync — every 30 minutes
*/30 * * * * www-data cd ${INSTALL_DIR} && bin/cake msp_vault superops:sync >> /var/log/msp-vault-superops.log 2>&1

# Email queue — every minute
* * * * * www-data cd ${INSTALL_DIR} && bin/cake email_queue send >> /var/log/msp-vault-email.log 2>&1
CRON

chmod 644 "$CRON_FILE"

# =============================================================================
# FILE PERMISSIONS
# =============================================================================
chown -R www-data:www-data "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 755 {} \;
find "$INSTALL_DIR" -type f -exec chmod 644 {} \;
chmod 640 "$INSTALL_DIR/config/passbolt.php"
chmod 640 "$INSTALL_DIR/config/jwt/jwt.key"
chmod 640 "$INSTALL_DIR/config/gpg/serverkey_private.asc"
chmod 700 "$INSTALL_DIR/config/gpg"

# =============================================================================
# OPTIONAL: SSL WITH CERTBOT
# =============================================================================
if [ -n "$DOMAIN" ]; then
    info "Installing SSL certificate for ${DOMAIN}..."
    apt-get install -y -qq certbot python3-certbot-nginx
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
        -m "$ADMIN_EMAIL" --redirect || warning "SSL setup failed — run manually: certbot --nginx -d ${DOMAIN}"
fi

# =============================================================================
# DONE
# =============================================================================
echo ""
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}  MSP-Vault installation complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo ""
echo -e "  URL:          ${GREEN}${BASE_URL}${NC}"
echo -e "  Admin login:  ${GREEN}${ADMIN_EMAIL}${NC}"
echo -e "  GPG key:      ${GREEN}${GPG_FPR}${NC}"
echo -e "  Install dir:  ${GREEN}${INSTALL_DIR}${NC}"
echo ""
echo -e "${YELLOW}Next steps:${NC}"
echo "  1. Visit ${BASE_URL} to complete the admin account setup"
echo "  2. Edit /var/www/msp-vault/config/passbolt.php to configure SMTP"
if [ -z "$DOMAIN" ]; then
echo "  3. Point a domain at this server and re-run with DOMAIN set for SSL"
fi
echo ""
echo "  Logs: /var/log/msp-vault-*.log"
echo "  Config: ${INSTALL_DIR}/config/passbolt.php"
echo ""
