#!/bin/bash
# =============================================================================
# Edulution Moodle - Container Entrypoint
# =============================================================================
# Haupteinstiegspunkt des Containers
# Orchestriert alle Startup-Prozesse
# =============================================================================

set -e

# =============================================================================
# BANNER
# =============================================================================

cat <<'EOF'

           _       _       _   _                                             _ _
   ___  __| |_   _| |_   _| |_(_) ___  _ __        _ __ ___   ___   ___   __| | | ___
  / _ \/ _` | | | | | | | | __| |/ _ \| '_ \ _____| '_ ` _ \ / _ \ / _ \ / _` | |/ _ \
 |  __/ (_| | |_| | | |_| | |_| | (_) | | | |_____| | | | | | (_) | (_) | (_| | |  __/
  \___|\__,_|\__,_|_|\__,_|\__|_|\___/|_| |_|     |_| |_| |_|\___/ \___/ \__,_|_|\___|

EOF

echo "========================================"
echo "  Edulution Moodle - Starting..."
echo "  Version: ${EDULUTION_MOODLE_VERSION:-1.0.0}"
echo "========================================"
echo ""

# =============================================================================
# SECRET LOADING (Docker Secrets / _FILE Variablen)
# =============================================================================

load_secrets() {
    echo "* Loading secrets from files..."

    # Liste aller _FILE Variablen die geladen werden sollen
    local secret_vars=(
        "MOODLE_DATABASE_PASSWORD"
        "MOODLE_ADMIN_PASSWORD"
        "KEYCLOAK_CLIENT_SECRET"
        "REDIS_PASSWORD"
        "SMTP_PASSWORD"
    )

    for var in "${secret_vars[@]}"; do
        local file_var="${var}_FILE"
        local file_path="${!file_var}"

        if [ -n "$file_path" ] && [ -f "$file_path" ]; then
            echo "  Loading $var from $file_path"
            export "$var"="$(cat "$file_path")"
        fi
    done

    echo "  Secrets loaded successfully"
}

# =============================================================================
# LOGGING HELPERS
# =============================================================================

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

log_error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1" >&2
}

log_success() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] SUCCESS: $1"
}

# =============================================================================
# DATABASE FUNCTIONS
# =============================================================================

wait_for_db() {
    echo "* Waiting for database..."
    local max_attempts="${DB_WAIT_TIMEOUT:-60}"
    local attempt=0

    until mysql -h "${MOODLE_DATABASE_HOST}" \
                -u "${MOODLE_DATABASE_USER}" \
                -p"${MOODLE_DATABASE_PASSWORD}" \
                -e "SELECT 1" &>/dev/null; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge "$max_attempts" ]; then
            log_error "Database connection timeout after $max_attempts attempts"
            exit 1
        fi
        echo "  Database not ready, waiting... ($attempt/$max_attempts)"
        sleep 5
    done

    log_success "Database is ready!"
}

# =============================================================================
# REDIS CHECK
# =============================================================================

wait_for_redis() {
    if [ -n "${REDIS_HOST}" ]; then
        echo "* Waiting for Redis..."
        local max_attempts=30
        local attempt=0

        until redis-cli -h "${REDIS_HOST}" -p "${REDIS_PORT:-6379}" \
                        ${REDIS_PASSWORD:+-a "$REDIS_PASSWORD"} ping &>/dev/null; do
            attempt=$((attempt + 1))
            if [ "$attempt" -ge "$max_attempts" ]; then
                log "Warning: Redis not available, continuing without Redis cache"
                return 0
            fi
            echo "  Redis not ready, waiting... ($attempt/$max_attempts)"
            sleep 2
        done

        log_success "Redis is ready!"
    fi
}

# =============================================================================
# MOODLE INITIALIZATION
# =============================================================================

init_moodle() {
    echo "* Initializing Moodle..."

    local config_file="/var/www/html/moodle/config.php"

    if [ ! -f "$config_file" ]; then
        echo "  Creating config.php..."

        # Redis-Konfiguration vorbereiten
        local redis_config=""
        if [ -n "${REDIS_HOST}" ]; then
            redis_config="
// Redis Session Store
\$CFG->session_handler_class = '\\\\core\\\\session\\\\redis';
\$CFG->session_redis_host = '${REDIS_HOST}';
\$CFG->session_redis_port = ${REDIS_PORT:-6379};
\$CFG->session_redis_database = ${REDIS_SESSION_DB:-0};
\$CFG->session_redis_prefix = 'moodle_sess_';
\$CFG->session_redis_acquire_lock_timeout = 120;
\$CFG->session_redis_lock_expire = 7200;
${REDIS_PASSWORD:+\$CFG->session_redis_auth = '${REDIS_PASSWORD}';}

// Redis Cache Store
\$CFG->cachestore_redis_server = '${REDIS_HOST}:${REDIS_PORT:-6379}';
${REDIS_PASSWORD:+\$CFG->cachestore_redis_password = '${REDIS_PASSWORD}';}
"
        fi

        cat > "$config_file" << EOF
<?php
// Moodle configuration file - Auto-generated by Edulution
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();

// Database Configuration
\$CFG->dbtype    = 'mariadb';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = '${MOODLE_DATABASE_HOST}';
\$CFG->dbname    = '${MOODLE_DATABASE_NAME}';
\$CFG->dbuser    = '${MOODLE_DATABASE_USER}';
\$CFG->dbpass    = '${MOODLE_DATABASE_PASSWORD}';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = array(
    'dbpersist' => 0,
    'dbport' => ${MOODLE_DATABASE_PORT:-3306},
    'dbsocket' => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
);

// Site Configuration
\$CFG->wwwroot   = '${MOODLE_WWWROOT}';
\$CFG->dataroot  = '/var/moodledata';
\$CFG->admin     = 'admin';
\$CFG->directorypermissions = 0750;

// Reverse Proxy / SSL
\$CFG->sslproxy = ${MOODLE_SSL_PROXY:-1};
${MOODLE_REVERSEPROXY:+\$CFG->reverseproxy = true;}

// Performance Settings
\$CFG->cachejs = true;
\$CFG->langstringcache = true;
\$CFG->themedesignermode = false;

$redis_config

// Local Cache Directory
\$CFG->localcachedir = '/var/moodledata/localcache';
\$CFG->tempdir = '/var/moodledata/temp';

// Security
\$CFG->passwordsaltmain = '${MOODLE_PASSWORD_SALT:-$(openssl rand -base64 32)}';
\$CFG->cookiesecure = true;
\$CFG->loginhttps = true;

require_once(__DIR__ . '/lib/setup.php');
EOF

        chown www-data:www-data "$config_file"
        chmod 640 "$config_file"

        echo "  Running Moodle database installation..."
        sudo -u www-data php /var/www/html/moodle/admin/cli/install_database.php \
            --adminuser="${MOODLE_ADMIN_USER:-admin}" \
            --adminpass="${MOODLE_ADMIN_PASSWORD}" \
            --adminemail="${MOODLE_ADMIN_EMAIL}" \
            --fullname="${MOODLE_SITE_NAME:-Edulution Moodle}" \
            --shortname="${MOODLE_SITE_SHORTNAME:-moodle}" \
            --agree-license

        log_success "Moodle installation completed!"
    else
        echo "  Moodle already installed, checking for upgrades..."
        sudo -u www-data php /var/www/html/moodle/admin/cli/upgrade.php --non-interactive || true
        log_success "Moodle upgrade check completed"
    fi
}

# =============================================================================
# PLUGIN SYNCHRONIZATION
# =============================================================================

sync_plugins() {
    echo "* Synchronizing plugins with configuration..."

    local plugins_config="${PLUGINS_CONFIG_FILE:-/config/plugins.json}"

    if [ -f "$plugins_config" ]; then
        log "  Running plugin manager..."

        # Plugin-Manager via Python ausführen
        if [ -f "/opt/edulution-moodle-sync/plugin_manager.py" ]; then
            /opt/sync-venv/bin/python /opt/edulution-moodle-sync/plugin_manager.py \
                --config "$plugins_config" \
                --moodle-path /var/www/html/moodle
        else
            log "  Warning: Plugin manager not found, using moosh fallback"

            # Fallback: Plugin-Liste via moosh
            cd /var/www/html/moodle

            # Parse JSON und installiere Plugins
            if command -v jq &>/dev/null; then
                for plugin in $(jq -r '.plugins[].name' "$plugins_config" 2>/dev/null); do
                    moosh -n plugin-install "$plugin" 2>/dev/null || \
                        log "  Warning: Could not install $plugin"
                done
            fi
        fi

        # Moodle-Upgrade nach Plugin-Installation
        sudo -u www-data php /var/www/html/moodle/admin/cli/upgrade.php --non-interactive || true

        log_success "Plugin sync completed"
    else
        log "  No plugins configuration found at $plugins_config"
    fi
}

# =============================================================================
# OAUTH2 / SSO CONFIGURATION
# =============================================================================

configure_oauth2() {
    if [ "${ENABLE_SSO:-0}" = "1" ]; then
        echo "* Configuring OAuth2 SSO..."

        cd /var/www/html/moodle

        # OIDC Plugin aktivieren
        moosh -n auth-manage enable oidc 2>/dev/null || true

        # Keycloak-spezifische Konfiguration
        if [ -n "${KEYCLOAK_URL}" ]; then
            local realm="${KEYCLOAK_REALM:-edulution}"
            local base_url="${KEYCLOAK_URL}/realms/${realm}/protocol/openid-connect"

            moosh -n config-set idptype 3 auth_oidc
            moosh -n config-set clientauthmethod 1 auth_oidc
            moosh -n config-set clientid "${KEYCLOAK_CLIENT_ID}" auth_oidc
            moosh -n config-set clientsecret "${KEYCLOAK_CLIENT_SECRET}" auth_oidc
            moosh -n config-set authendpoint "${base_url}/auth" auth_oidc
            moosh -n config-set tokenendpoint "${base_url}/token" auth_oidc
            moosh -n config-set oidcresource "${KEYCLOAK_URL}/" auth_oidc

            # SSO-Verhalten
            [ "${SSO_FORCE_REDIRECT:-0}" = "1" ] && \
                moosh -n config-set forceredirect 1 auth_oidc

            # Feld-Mapping
            moosh -n config-set field_map_firstname givenName auth_oidc
            moosh -n config-set field_lock_firstname locked auth_oidc
            moosh -n config-set field_map_lastname familyName auth_oidc
            moosh -n config-set field_lock_lastname locked auth_oidc
            moosh -n config-set field_map_email email auth_oidc
            moosh -n config-set field_lock_email locked auth_oidc

            # Display-Einstellungen
            moosh -n config-set opname "${SSO_PROVIDER_NAME:-edulution.io}" auth_oidc
            moosh -n config-set icon "moodle:t/locked" auth_oidc

            log_success "OAuth2/OIDC configuration completed"
        else
            log "  Warning: KEYCLOAK_URL not set, skipping detailed OAuth2 config"
        fi
    else
        log "  SSO disabled (ENABLE_SSO != 1)"
    fi
}

# =============================================================================
# LANGUAGE INSTALLATION
# =============================================================================

install_languages() {
    echo "* Installing language packs..."

    # Wartungs-Skript aufrufen falls vorhanden
    if [ -x "/opt/scripts/install_languages.sh" ]; then
        /opt/scripts/install_languages.sh
    else
        # Fallback: Direkte Installation
        cd /var/www/html/moodle

        local languages="${MOODLE_INSTALL_LANGUAGES:-de,de_comm}"
        local default_lang="${MOODLE_DEFAULT_LANGUAGE:-de_comm}"

        IFS=',' read -ra LANGS <<< "$languages"
        for lang in "${LANGS[@]}"; do
            lang=$(echo "$lang" | tr -d ' ')
            moosh -n language-install "$lang" 2>/dev/null || \
                log "  Warning: Could not install language $lang"
        done

        # Default-Sprache setzen
        moosh -n config-set lang "$default_lang"

        log_success "Language installation completed"
    fi
}

# =============================================================================
# SECURITY CONFIGURATION
# =============================================================================

security_config() {
    echo "* Applying security configuration..."

    # Wartungs-Skript aufrufen falls vorhanden
    if [ -x "/opt/scripts/security_config.sh" ]; then
        /opt/scripts/security_config.sh
    else
        # Fallback: Basis-Security-Settings
        cd /var/www/html/moodle

        # Password-Policy
        moosh -n config-set minpasswordlength 12
        moosh -n config-set minpassworddigits 1
        moosh -n config-set minpasswordlower 1
        moosh -n config-set minpasswordupper 1
        moosh -n config-set minpasswordnonalphanum 1

        # Session-Sicherheit
        moosh -n config-set cookiesecure 1
        moosh -n config-set cookiehttponly 1
        moosh -n config-set sessiontimeout 7200

        # Debug ausschalten in Produktion
        if [ "${MOODLE_DEBUG:-0}" != "1" ]; then
            moosh -n config-set debug 0
            moosh -n config-set debugdisplay 0
        fi

        log_success "Security configuration applied"
    fi
}

# =============================================================================
# MAIL CONFIGURATION
# =============================================================================

configure_mail() {
    if [ -n "${SMTP_HOST}" ]; then
        echo "* Configuring mail settings..."

        # Wartungs-Skript aufrufen falls vorhanden
        if [ -x "/opt/scripts/mail_config.sh" ]; then
            /opt/scripts/mail_config.sh
        else
            # Fallback: Direkte Konfiguration
            cd /var/www/html/moodle

            moosh -n config-set smtphosts "${SMTP_HOST}:${SMTP_PORT:-587}"
            [ -n "${SMTP_USER}" ] && moosh -n config-set smtpuser "${SMTP_USER}"
            [ -n "${SMTP_PASSWORD}" ] && moosh -n config-set smtppass "${SMTP_PASSWORD}"
            moosh -n config-set smtpsecure "${SMTP_SECURITY:-tls}"
            moosh -n config-set noreplyaddress "${SMTP_NOREPLY:-noreply@example.com}"

            log_success "Mail configuration applied"
        fi
    fi
}

# =============================================================================
# SYNC SERVICE
# =============================================================================

start_sync_service() {
    # SYNC_ENABLED kann 0, 1, true, false sein
    local sync_enabled="${SYNC_ENABLED:-0}"

    if [ "$sync_enabled" = "1" ] || [ "$sync_enabled" = "true" ]; then
        echo "* Starting sync service..."

        if [ -f "/opt/edulution-moodle-sync/sync.py" ]; then
            # Validate Keycloak configuration
            if [ -z "${KEYCLOAK_SECRET_KEY}" ] && [ -z "${KEYCLOAK_CLIENT_SECRET}" ]; then
                log_error "!!! WARNING: KEYCLOAK_SECRET_KEY is not set!"
                log_error "Sync will NOT work without Keycloak credentials."
                log "  Please set KEYCLOAK_SECRET_KEY or KEYCLOAK_CLIENT_SECRET"
            fi

            # Backward compatibility: KEYCLOAK_CLIENT_SECRET -> KEYCLOAK_SECRET_KEY
            if [ -z "${KEYCLOAK_SECRET_KEY}" ] && [ -n "${KEYCLOAK_CLIENT_SECRET}" ]; then
                export KEYCLOAK_SECRET_KEY="${KEYCLOAK_CLIENT_SECRET}"
                log "  Using KEYCLOAK_CLIENT_SECRET as KEYCLOAK_SECRET_KEY"
            fi

            log_success "Sync service configured (managed by Supervisor)"
            log "  Sync interval: ${SYNC_INTERVAL:-300} seconds"
            log "  Dry run: ${DRY_RUN:-0}"
        else
            log "  Warning: Sync service not found at /opt/edulution-moodle-sync/sync.py"
        fi
    else
        log "  Sync service disabled (SYNC_ENABLED=${sync_enabled})"
        # Disable sync in supervisor config
        if [ -f "/etc/supervisor/conf.d/supervisord.conf" ]; then
            sed -i 's/autostart=true/autostart=false/g' /etc/supervisor/conf.d/supervisord.conf 2>/dev/null || true
        fi
    fi
}

# =============================================================================
# CRON SETUP
# =============================================================================

setup_cron() {
    echo "* Setting up Moodle cron..."

    # Cron wird ueber Supervisor verwaltet
    log_success "Cron configured (managed by Supervisor)"
}

# =============================================================================
# DIRECTORY PERMISSIONS
# =============================================================================

fix_permissions() {
    echo "* Fixing directory permissions..."

    chown -R www-data:www-data /var/moodledata
    chmod -R 750 /var/moodledata

    # Config-Dateien
    [ -f /var/www/html/moodle/config.php ] && \
        chmod 640 /var/www/html/moodle/config.php

    log_success "Permissions fixed"
}

# =============================================================================
# STARTUP HEALTH CHECK
# =============================================================================

startup_health_check() {
    echo "* Running startup health checks..."

    # PHP Check
    if ! php -v &>/dev/null; then
        log_error "PHP not working!"
        exit 1
    fi

    # Moodle-Verzeichnis Check
    if [ ! -f /var/www/html/moodle/version.php ]; then
        log_error "Moodle installation not found!"
        exit 1
    fi

    # Moodledata Check
    if [ ! -d /var/moodledata ]; then
        log_error "Moodledata directory not found!"
        exit 1
    fi

    log_success "All startup health checks passed"
}

# =============================================================================
# START SUPERVISOR
# =============================================================================

start_supervisor() {
    echo "* Starting Supervisor..."
    echo ""
    echo "========================================"
    echo "  Edulution Moodle - Ready!"
    echo "  URL: ${MOODLE_WWWROOT}"
    echo "========================================"
    echo ""

    # Supervisor im Vordergrund starten
    exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf -n
}

# =============================================================================
# SIGNAL HANDLING (Graceful Shutdown)
# =============================================================================

cleanup() {
    echo ""
    echo "* Received shutdown signal, cleaning up..."

    # Wartungsmodus aktivieren
    cd /var/www/html/moodle
    php admin/cli/maintenance.php --enable 2>/dev/null || true

    # Supervisor stoppen
    supervisorctl shutdown 2>/dev/null || true

    log_success "Cleanup completed"
    exit 0
}

trap cleanup SIGTERM SIGINT

# =============================================================================
# MAIN
# =============================================================================

main() {
    # Secrets laden
    load_secrets

    # Startup Health Check
    startup_health_check

    # Auf Dienste warten
    wait_for_db
    wait_for_redis

    # Moodle initialisieren
    init_moodle

    # Plugins synchronisieren
    sync_plugins

    # Konfigurationen anwenden
    configure_oauth2
    install_languages
    security_config
    configure_mail

    # Permissions korrigieren
    fix_permissions

    # Services konfigurieren
    start_sync_service
    setup_cron

    # Supervisor starten (blockiert)
    start_supervisor
}

# Script ausfuehren
main "$@"
