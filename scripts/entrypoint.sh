#!/bin/bash
#
# Edulution Moodle Entrypoint Script
#
# This script initializes and configures Moodle for the edulution.io platform.
# It handles database setup, Moodle installation, configuration, and cron setup.
#
# When MOODLE_WWWROOT contains a path (e.g. /learningmanagement1), Apache
# gets an Alias so Moodle is accessible at that path. Traefik forwards
# the full path (no stripPrefix) and handles the trailing-slash redirect.
#
# Environment Variables:
#   See .env.example for full list of supported variables
#
# @copyright 2026 edulution
# @license   MIT
#

set -euo pipefail

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
}

# Banner
cat << 'EOF'

   ___  _____  _   _  _     _   _  _____  ___  _____ _   _
  / _ \|  _  || | | || |   | | | ||_   _||_ _||  _  | \ | |
 | | | | | | || | | || |   | | | |  | |   | | | | | |  \| |
 | |_| | |_| || |_| || |___| |_| |  | |   | | | |_| | |\  |
 |_| \_|_____|\_____/|_____|_____|  |_|  |___|\_____/_| \_|

          M O O D L E   E D I T I O N

EOF

echo "=============================================="
echo "  Moodle for edulution.io"
echo "  Optimized for reverse proxy & iframe embedding"
echo "=============================================="
echo ""

# Configuration
# Moodle 5.x: web-accessible files are in public/, CLI tools remain in root
MOODLE_BASE="/var/www/html/moodle"
MOODLE_DIR="${MOODLE_BASE}/public"
MOODLE_DATA="${MOODLE_DATA:-/var/moodledata}"
CONFIG_FILE="${MOODLE_BASE}/config.php"

# Load secrets from files if provided
if [ -f "${MOODLE_DATABASE_PASSWORD_FILE:-}" ]; then
    export MOODLE_DATABASE_PASSWORD=$(cat "$MOODLE_DATABASE_PASSWORD_FILE")
    log_info "Loaded database password from file"
fi

if [ -f "${MOODLE_ADMIN_PASSWORD_FILE:-}" ]; then
    export MOODLE_ADMIN_PASSWORD=$(cat "$MOODLE_ADMIN_PASSWORD_FILE")
    log_info "Loaded admin password from file"
fi

log_info "wwwroot: ${MOODLE_WWWROOT:-not set, will use hostname}"

# Generate Apache config from template
log_info "Generating Apache configuration..."
cp /etc/apache2/sites-available/moodle.conf.template /etc/apache2/sites-available/moodle.conf

# Auto-detect URL path from MOODLE_WWWROOT (e.g. https://host/learningmanagement1 → /learningmanagement1)
URL_PATH=""
if [ -n "${MOODLE_WWWROOT:-}" ]; then
    URL_PATH=$(echo "${MOODLE_WWWROOT}" | sed -E 's|https?://[^/]+||; s|/$||')
fi

# If there's a URL path, add Apache Alias so Moodle is accessible at that path
if [ -n "${URL_PATH}" ]; then
    log_info "URL path detected: ${URL_PATH} - adding Apache Alias"
    sed -i "/<\/VirtualHost>/i\\
    # Path prefix: Moodle accessible at ${URL_PATH}\\
    Alias ${URL_PATH} ${MOODLE_DIR}" /etc/apache2/sites-available/moodle.conf
fi

# Enable the moodle site
a2ensite moodle > /dev/null 2>&1 || true
log_success "Apache configuration generated (DocumentRoot: ${MOODLE_DIR}, Path: ${URL_PATH:-/})"

# Wait for database
log_info "Waiting for database to be ready..."
MAX_TRIES=60
COUNT=0
while ! mariadb -h "${MOODLE_DATABASE_HOST}" -u "${MOODLE_DATABASE_USER}" -p"${MOODLE_DATABASE_PASSWORD}" -e "SELECT 1" &>/dev/null; do
    COUNT=$((COUNT + 1))
    if [ $COUNT -ge $MAX_TRIES ]; then
        log_error "Database not available after ${MAX_TRIES} attempts. Exiting."
        exit 1
    fi
    echo -n "."
    sleep 2
done
echo ""
log_success "Database is ready!"

# Ensure data directory permissions
log_info "Setting up data directory permissions..."
mkdir -p "${MOODLE_DATA}"
chown -R www-data:www-data "${MOODLE_DATA}"
chmod -R 755 "${MOODLE_DATA}"
log_success "Permissions set!"

# Check if Moodle is installed
log_info "Checking Moodle installation status..."
DB_TABLES=$(mariadb -h "${MOODLE_DATABASE_HOST}" -u "${MOODLE_DATABASE_USER}" -p"${MOODLE_DATABASE_PASSWORD}" "${MOODLE_DATABASE_NAME}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${MOODLE_DATABASE_NAME}'" 2>/dev/null || echo "0")

if [ "${DB_TABLES}" -lt 10 ]; then
    log_info "Installing Moodle (this may take a few minutes)..."

    # WICHTIG: config.php muss gelöscht werden vor der Installation!
    rm -f "${CONFIG_FILE}"

    # Determine wwwroot for installation
    INSTALL_WWWROOT="${MOODLE_WWWROOT:-https://${MOODLE_HOSTNAME:-localhost}}"

    cd "${MOODLE_BASE}"
    sudo -u www-data php admin/cli/install.php \
        --wwwroot="${INSTALL_WWWROOT}" \
        --dataroot="${MOODLE_DATA}" \
        --dbtype=mariadb \
        --dbhost="${MOODLE_DATABASE_HOST}" \
        --dbname="${MOODLE_DATABASE_NAME}" \
        --dbuser="${MOODLE_DATABASE_USER}" \
        --dbpass="${MOODLE_DATABASE_PASSWORD}" \
        --adminuser="${MOODLE_ADMIN_USER}" \
        --adminpass="${MOODLE_ADMIN_PASSWORD}" \
        --adminemail="${MOODLE_ADMIN_EMAIL}" \
        --fullname="${MOODLE_SITE_NAME}" \
        --shortname="Moodle" \
        --agree-license \
        --non-interactive || {
            log_error "Moodle installation failed!"
            exit 1
        }

    log_success "Moodle installed successfully!"

    # Nach erfolgreicher Installation: config.php mit korrekten Werten generieren
    log_info "Generating configuration with actual values..."
    /usr/local/bin/generate-config.sh
    log_success "Configuration generated!"
else
    log_info "Moodle already installed."

    # Config.php neu generieren mit aktuellen Werten
    log_info "Regenerating configuration..."
    /usr/local/bin/generate-config.sh

    # Upgrade check
    cd "${MOODLE_BASE}"
    sudo -E -u www-data php admin/cli/upgrade.php --non-interactive || true
    log_success "Upgrade check completed!"
fi

# Apply iframe embedding setting in database
log_info "Configuring iframe embedding..."
cd "${MOODLE_BASE}"
if [ "${MOODLE_ALLOWFRAMEMBEDDING:-true}" = "true" ] || [ "${MOODLE_ALLOWFRAMEMBEDDING:-true}" = "1" ]; then
    sudo -E -u www-data php admin/cli/cfg.php --name=allowframembedding --set=1 2>/dev/null || true
    log_success "iframe embedding ENABLED"
else
    sudo -E -u www-data php admin/cli/cfg.php --name=allowframembedding --set=0 2>/dev/null || true
    log_warn "iframe embedding DISABLED"
fi

# Configure security settings (force login, no guest)
log_info "Configuring security settings..."
cd "${MOODLE_BASE}"
sudo -E -u www-data php admin/cli/cfg.php --name=forcelogin --set=1 2>/dev/null || true
sudo -E -u www-data php admin/cli/cfg.php --name=guestloginbutton --set=0 2>/dev/null || true
sudo -E -u www-data php admin/cli/cfg.php --name=forceloginforprofiles --set=1 2>/dev/null || true
log_success "Security settings configured"

# Configure course visibility (students see only enrolled courses)
log_info "Configuring course visibility settings..."
cd "${MOODLE_BASE}"
sudo -E -u www-data php admin/cli/cfg.php --name=frontpage --set='' 2>/dev/null || true
sudo -E -u www-data php admin/cli/cfg.php --name=frontpageloggedin --set='' 2>/dev/null || true
sudo -E -u www-data php admin/cli/cfg.php --name=maxcategorydepth --set=0 2>/dev/null || true
sudo -E -u www-data php admin/cli/cfg.php --component=block_myoverview --name=displaycategories --set=0 2>/dev/null || true
log_success "Course visibility configured (students see only enrolled courses)"

# Configure OAuth2/Keycloak SSO (if enabled)
if [ "${ENABLE_SSO:-0}" = "1" ] || [ "${ENABLE_SSO:-0}" = "true" ]; then
    if [ -n "${KEYCLOAK_CLIENT_SECRET:-}" ]; then
        log_info "Configuring Keycloak SSO..."
        if [ -f "/sync-data/configure-oauth2.php" ]; then
            php /sync-data/configure-oauth2.php 2>/dev/null && \
                log_success "Keycloak SSO configured" || \
                log_warn "Could not configure Keycloak SSO"
        else
            log_warn "configure-oauth2.php not found - SSO not configured"
        fi
    else
        log_warn "ENABLE_SSO=1 but KEYCLOAK_CLIENT_SECRET not set"
    fi
fi

# Download German language pack (no CLI available in Moodle)
log_info "Downloading German language pack..."
LANG_DIR="${MOODLE_DATA}/lang"
mkdir -p "${LANG_DIR}"
if [ ! -d "${LANG_DIR}/de" ]; then
    curl -sSL "https://download.moodle.org/download.php/direct/langpack/5.1/de.zip" -o /tmp/de.zip && \
        unzip -q /tmp/de.zip -d "${LANG_DIR}/" && \
        rm /tmp/de.zip && \
        log_success "German language pack installed!" || \
        log_warn "Could not download German language pack (install manually via admin UI)"
else
    log_info "German language pack already installed"
fi
chown -R www-data:www-data "${LANG_DIR}"

# Set up cron job
log_info "Setting up Moodle cron..."
echo "* * * * * www-data /usr/bin/php ${MOODLE_BASE}/admin/cli/cron.php > /dev/null 2>&1" > /etc/cron.d/moodle
chmod 644 /etc/cron.d/moodle
log_success "Cron configured!"

# Set up Keycloak sync cron (if sync-data is mounted)
if [ -f "/sync-data/keycloak-sync.php" ]; then
    log_info "Setting up Keycloak sync cron..."

    # Create sync wrapper script
    cat > /usr/local/bin/keycloak-sync-cron.sh << 'SYNCEOF'
#!/bin/bash
LOGFILE="/var/log/moodle/keycloak-sync.log"
LOCKFILE="/tmp/keycloak-sync.lock"

# Prevent multiple instances
if [ -f "$LOCKFILE" ]; then
    pid=$(cat "$LOCKFILE")
    if kill -0 "$pid" 2>/dev/null; then
        exit 0
    fi
fi
echo $$ > "$LOCKFILE"
trap "rm -f $LOCKFILE" EXIT

echo "$(date '+%Y-%m-%d %H:%M:%S') [START] Keycloak sync" >> "$LOGFILE"
php /sync-data/keycloak-sync.php >> "$LOGFILE" 2>&1
echo "$(date '+%Y-%m-%d %H:%M:%S') [DONE] Exit code: $?" >> "$LOGFILE"
echo "" >> "$LOGFILE"
SYNCEOF
    chmod +x /usr/local/bin/keycloak-sync-cron.sh

    # Create log file
    touch /var/log/moodle/keycloak-sync.log
    chown www-data:www-data /var/log/moodle/keycloak-sync.log

    # Add cron job (every 5 minutes)
    SYNC_INTERVAL="${KEYCLOAK_SYNC_INTERVAL:-5}"
    echo "*/${SYNC_INTERVAL} * * * * root /usr/local/bin/keycloak-sync-cron.sh" > /etc/cron.d/keycloak-sync
    chmod 644 /etc/cron.d/keycloak-sync

    log_success "Keycloak sync cron configured (every ${SYNC_INTERVAL} minutes)"
fi

# Create local cache directory
mkdir -p /var/moodledata/localcache
chown -R www-data:www-data /var/moodledata

# Fix ownership one more time
chown -R www-data:www-data "${MOODLE_BASE}"
chown -R www-data:www-data "${MOODLE_DATA}"

echo ""
echo "=============================================="
log_success "Edulution Moodle is ready!"
echo ""
echo "  wwwroot: ${MOODLE_WWWROOT:-https://${MOODLE_HOSTNAME:-localhost}}"
echo "  Admin: ${MOODLE_ADMIN_USER}"
echo ""
echo "  Settings:"
echo "    - SSL Proxy: ${MOODLE_SSLPROXY:-false}"
echo "    - Reverse Proxy: ${MOODLE_REVERSEPROXY:-false}"
echo "    - iframe Embedding: ${MOODLE_ALLOWFRAMEMBEDDING:-true}"
echo ""
echo "=============================================="
echo ""

# Start the main process (supervisord)
exec "$@"
