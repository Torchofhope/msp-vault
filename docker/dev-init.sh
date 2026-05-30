#!/usr/bin/env bash
# MSP-Vault dev environment first-time setup
# Run inside the app container: docker compose -f docker-compose.dev.yml exec app bash docker/dev-init.sh

set -euo pipefail

echo "=== MSP-Vault Dev Setup ==="

# 1. Install Composer dependencies
echo ">> Installing Composer dependencies..."
composer install --no-interaction --prefer-dist

# 2. Copy app.php config if not present
if [ ! -f config/app.php ]; then
    echo ">> Creating config/app.php..."
    cp config/app.default.php config/app.php
fi

# 3. Copy passbolt.php config if not present
if [ ! -f config/passbolt.php ]; then
    echo ">> Creating config/passbolt.php..."
    cp config/passbolt.default.php config/passbolt.php
fi

# 4. Generate JWT keys if not present
if [ ! -f config/jwt/jwt.key ] || [ ! -f config/jwt/jwt.pem ]; then
    echo ">> Generating JWT keys..."
    mkdir -p config/jwt
    openssl genrsa -out config/jwt/jwt.key 4096 2>/dev/null
    openssl rsa -in config/jwt/jwt.key -pubout -out config/jwt/jwt.pem 2>/dev/null
    chmod 640 config/jwt/jwt.key config/jwt/jwt.pem
fi

# 5. Import GPG server key (uses the pre-existing test key in the repo)
echo ">> Setting up GPG server key..."
gpg --import config/gpg/unsecure_private.key 2>/dev/null || true
gpg --import config/gpg/unsecure.key 2>/dev/null || true

# 6. Run database migrations (includes all MSP-Vault plugin migrations)
echo ">> Running database migrations..."
bin/cake migrations migrate
bin/cake migrations migrate --plugin Passbolt/MultiTenant
bin/cake migrations migrate --plugin Passbolt/Devices
bin/cake migrations migrate --plugin Passbolt/CredentialRotation
bin/cake migrations migrate --plugin Passbolt/CredentialEscrow
bin/cake migrations migrate --plugin Passbolt/MspEntraId
bin/cake migrations migrate --plugin Passbolt/SuperOpsSync

# 7. Seed initial data (admin user + first org)
echo ">> Seeding initial MSP-Vault data..."
bin/cake passbolt install --no-admin 2>/dev/null || true
bin/cake passbolt register_user \
    --username admin@msp-vault.local \
    --first-name MSP \
    --last-name Admin \
    --role admin 2>/dev/null || echo "(admin user may already exist)"

# 8. Clear cache
echo ">> Clearing cache..."
bin/cake cache clear_all 2>/dev/null || true

echo ""
echo "=== Setup complete! ==="
echo ""
echo "  App:     http://localhost:8080"
echo "  Emails:  http://localhost:8025  (Mailpit)"
echo "  DB UI:   http://localhost:8081  (Adminer — server: db, user: mspvault, pass: mspvault_secret)"
echo ""
echo "  Admin login: admin@msp-vault.local"
echo "  (Complete setup by visiting http://localhost:8080/setup/start/...)"
echo ""
