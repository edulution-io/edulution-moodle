#!/bin/bash
set -e

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
MOODLE_DIR="/var/www/html/moodle"
MOODLE_DATA="${MOODLE_DATA:-/var/moodledata}"
CONFIG_FILE="${MOODLE_DIR}/config.php"

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

# Create config.php from template if it doesn't exist
if [ ! -f "${CONFIG_FILE}" ]; then
    log_info "Creating Moodle configuration..."
    cp "${MOODLE_DIR}/config-template.php" "${CONFIG_FILE}"
    chown www-data:www-data "${CONFIG_FILE}"
    log_success "Configuration created!"
else
    log_info "Configuration already exists, updating key settings..."
    # Update wwwroot, sslproxy, reverseproxy, allowframembedding
    /usr/local/bin/configure-moodle.sh
fi

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

    cd "${MOODLE_DIR}"
    sudo -u www-data php admin/cli/install.php \
        --wwwroot="https://${MOODLE_HOSTNAME}${MOODLE_PATH}" \
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

    # Replace generated config with our template
    cp "${MOODLE_DIR}/config-template.php" "${CONFIG_FILE}"
    chown www-data:www-data "${CONFIG_FILE}"

    log_success "Moodle installed successfully!"
else
    log_info "Moodle already installed. Checking for upgrades..."
    cd "${MOODLE_DIR}"
    sudo -u www-data php admin/cli/upgrade.php --non-interactive || true
    log_success "Upgrade check completed!"
fi

# Apply iframe embedding setting in database
log_info "Configuring iframe embedding..."
cd "${MOODLE_DIR}"
if [ "${MOODLE_ALLOWFRAMEMBEDDING}" = "true" ] || [ "${MOODLE_ALLOWFRAMEMBEDDING}" = "1" ]; then
    sudo -u www-data php admin/cli/cfg.php --name=allowframembedding --set=1 2>/dev/null || true
    log_success "iframe embedding ENABLED"
else
    sudo -u www-data php admin/cli/cfg.php --name=allowframembedding --set=0 2>/dev/null || true
    log_warn "iframe embedding DISABLED"
fi

# Install German language pack
log_info "Installing language packs..."
cd "${MOODLE_DIR}"
sudo -u www-data php admin/cli/install_langpack.php de 2>/dev/null || true
sudo -u www-data php admin/cli/install_langpack.php de_comm 2>/dev/null || true
log_success "Language packs installed!"

# Set up cron job
log_info "Setting up Moodle cron..."
echo "* * * * * www-data /usr/bin/php ${MOODLE_DIR}/admin/cli/cron.php > /dev/null 2>&1" > /etc/cron.d/moodle
chmod 644 /etc/cron.d/moodle
log_success "Cron configured!"

# Create local cache directory
mkdir -p /var/moodledata/localcache
chown -R www-data:www-data /var/moodledata

# Fix ownership one more time
chown -R www-data:www-data "${MOODLE_DIR}"
chown -R www-data:www-data "${MOODLE_DATA}"

echo ""
echo "=============================================="
log_success "Edulution Moodle is ready!"
echo ""
echo "  URL: https://${MOODLE_HOSTNAME}${MOODLE_PATH}"
echo "  Admin: ${MOODLE_ADMIN_USER}"
echo ""
echo "  Settings:"
echo "    - Reverse Proxy: ${MOODLE_REVERSEPROXY}"
echo "    - SSL Proxy: ${MOODLE_SSLPROXY}"
echo "    - iframe Embedding: ${MOODLE_ALLOWFRAMEMBEDDING}"
echo ""
echo "=============================================="
echo ""

# Start the main process (supervisord)
exec "$@"
