#!/bin/bash
# =============================================================================
# Edulution Moodle - Health Check
# =============================================================================
# Prueft Moodle-Gesundheit und startet bei Problemen automatisch neu
#
# Usage: health_check.sh [--verbose]
# =============================================================================

# =============================================================================
# CONFIGURATION
# =============================================================================

MOODLE_URL="${MOODLE_WWWROOT:-http://localhost}"
MOODLE_PATH="/var/www/html/moodle"

MAX_FAILURES="${HEALTH_MAX_FAILURES:-3}"
FAILURE_FILE="/tmp/moodle_health_failures"
STATUS_FILE="/tmp/moodle_health_status"

AUTO_RESTART="${AUTO_RESTART_ON_FAILURE:-1}"
VERBOSE=0

# Parse arguments
[ "$1" = "--verbose" ] && VERBOSE=1

# =============================================================================
# LOGGING
# =============================================================================

LOG_FILE="/var/log/moodle-maintenance/health.log"
mkdir -p "$(dirname "$LOG_FILE")"

log() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    [ "$VERBOSE" = "1" ] && echo "$message"
    echo "$message" >> "$LOG_FILE"
}

log_error() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1"
    echo "$message" >&2
    echo "$message" >> "$LOG_FILE"
}

log_warning() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] WARNING: $1"
    [ "$VERBOSE" = "1" ] && echo "$message"
    echo "$message" >> "$LOG_FILE"
}

# =============================================================================
# NOTIFICATION
# =============================================================================

send_notification() {
    local severity="$1"
    local message="$2"

    if [ -n "$WEBHOOK_URL" ]; then
        curl -s -X POST "$WEBHOOK_URL" \
            -H "Content-Type: application/json" \
            -d "{\"text\": \"[$severity] Moodle Health: $message\"}" \
            2>/dev/null || true
    fi
}

# =============================================================================
# HEALTH CHECK FUNCTIONS
# =============================================================================

# Web-Erreichbarkeit pruefen
check_web() {
    local http_code=$(curl -sf -o /dev/null -w "%{http_code}" \
        "$MOODLE_URL/login/index.php" \
        --max-time 10 \
        2>/dev/null)

    echo "$http_code"
}

# Datenbank-Verbindung pruefen
check_database() {
    if mysql -h "${MOODLE_DATABASE_HOST}" \
             -u "${MOODLE_DATABASE_USER}" \
             -p"${MOODLE_DATABASE_PASSWORD}" \
             -e "SELECT 1 FROM ${MOODLE_DATABASE_NAME}.mdl_config LIMIT 1" \
             &>/dev/null; then
        return 0
    else
        return 1
    fi
}

# Datenbank-Schema pruefen
check_database_schema() {
    cd "$MOODLE_PATH"
    if php admin/cli/check_database_schema.php 2>/dev/null | grep -q "No schema problems"; then
        return 0
    else
        return 1
    fi
}

# Keycloak/OAuth2 pruefen
check_keycloak() {
    if [ -n "${KEYCLOAK_URL}" ]; then
        local realm="${KEYCLOAK_REALM:-edulution}"
        local url="${KEYCLOAK_URL}/realms/${realm}/.well-known/openid-configuration"

        local http_code=$(curl -sf -o /dev/null -w "%{http_code}" \
            "$url" \
            --max-time 5 \
            2>/dev/null)

        if [ "$http_code" = "200" ]; then
            return 0
        else
            return 1
        fi
    fi

    # Wenn kein Keycloak konfiguriert, ist der Check OK
    return 0
}

# Redis pruefen
check_redis() {
    if [ -n "${REDIS_HOST}" ]; then
        if redis-cli -h "${REDIS_HOST}" -p "${REDIS_PORT:-6379}" \
                     ${REDIS_PASSWORD:+-a "$REDIS_PASSWORD"} \
                     ping 2>/dev/null | grep -q "PONG"; then
            return 0
        else
            return 1
        fi
    fi

    # Wenn kein Redis konfiguriert, ist der Check OK
    return 0
}

# Cron-Status pruefen
check_cron() {
    cd "$MOODLE_PATH"

    # Letzten Cron-Lauf ermitteln
    local last_cron=$(moosh -n config-get core lastcron 2>/dev/null || echo "0")
    local now=$(date +%s)
    local diff=$((now - last_cron))

    # Cron sollte alle 60 Sekunden laufen, Toleranz 10 Minuten
    if [ "$diff" -gt 600 ]; then
        return 1
    fi

    return 0
}

# Disk-Space pruefen
check_disk_space() {
    local min_space_mb="${HEALTH_MIN_DISK_MB:-500}"

    # Moodledata Partition pruefen
    local available_mb=$(df -BM /var/moodledata | awk 'NR==2 {print $4}' | tr -d 'M')

    if [ "$available_mb" -lt "$min_space_mb" ]; then
        return 1
    fi

    return 0
}

# PHP-FPM / Apache pruefen
check_php() {
    if pgrep -x "apache2" >/dev/null || pgrep -x "httpd" >/dev/null; then
        return 0
    else
        return 1
    fi
}

# =============================================================================
# RECOVERY ACTIONS
# =============================================================================

# Failure-Counter verwalten
get_failures() {
    cat "$FAILURE_FILE" 2>/dev/null || echo "0"
}

set_failures() {
    echo "$1" > "$FAILURE_FILE"
}

reset_failures() {
    echo "0" > "$FAILURE_FILE"
}

# Apache neustarten
restart_apache() {
    log "Restarting Apache..."

    if [ -x /usr/bin/supervisorctl ]; then
        supervisorctl restart apache2 2>/dev/null || \
            service apache2 restart
    else
        service apache2 restart 2>/dev/null || \
            apachectl graceful
    fi
}

# Cache leeren
purge_caches() {
    log "Purging Moodle caches..."

    cd "$MOODLE_PATH"
    sudo -u www-data php admin/cli/purge_caches.php 2>/dev/null || true
}

# Cron manuell starten
trigger_cron() {
    log "Triggering Moodle cron manually..."

    cd "$MOODLE_PATH"
    sudo -u www-data php admin/cli/cron.php &
}

# Recovery durchfuehren
perform_recovery() {
    log_error "Performing automatic recovery..."

    # 1. Apache neustarten
    restart_apache
    sleep 5

    # 2. Cache leeren
    purge_caches

    # 3. Kurze Pause
    sleep 5

    # 4. Erneut pruefen
    local http_code=$(check_web)

    if [ "$http_code" = "200" ]; then
        log "Recovery successful!"
        send_notification "SUCCESS" "Automatische Wiederherstellung erfolgreich"
        reset_failures
        return 0
    else
        log_error "Recovery failed, HTTP code: $http_code"
        send_notification "CRITICAL" "Automatische Wiederherstellung fehlgeschlagen - Manueller Eingriff erforderlich!"
        return 1
    fi
}

# =============================================================================
# STATUS REPORT
# =============================================================================

update_status() {
    local status="$1"

    cat > "$STATUS_FILE" << EOF
{
    "timestamp": "$(date '+%Y-%m-%d %H:%M:%S')",
    "status": "$status",
    "checks": {
        "web": "$web_status",
        "database": "$db_status",
        "keycloak": "$kc_status",
        "redis": "$redis_status",
        "cron": "$cron_status",
        "disk": "$disk_status",
        "php": "$php_status"
    },
    "failures": $(get_failures),
    "max_failures": $MAX_FAILURES
}
EOF
}

# =============================================================================
# MAIN
# =============================================================================

main() {
    log "Starting health check..."

    local all_ok=true

    # Web-Check
    local http_code=$(check_web)
    if [ "$http_code" = "200" ]; then
        web_status="ok"
        log "Web check: OK (HTTP $http_code)"
    else
        web_status="failed"
        log_error "Web check: FAILED (HTTP $http_code)"
        all_ok=false
    fi

    # Datenbank-Check
    if check_database; then
        db_status="ok"
        log "Database check: OK"
    else
        db_status="failed"
        log_error "Database check: FAILED"
        all_ok=false
    fi

    # Keycloak-Check
    if check_keycloak; then
        kc_status="ok"
        log "Keycloak check: OK"
    else
        kc_status="warning"
        log_warning "Keycloak check: FAILED (SSO may be unavailable)"
    fi

    # Redis-Check
    if check_redis; then
        redis_status="ok"
        log "Redis check: OK"
    else
        redis_status="warning"
        log_warning "Redis check: FAILED (performance may be degraded)"
    fi

    # Cron-Check
    if check_cron; then
        cron_status="ok"
        log "Cron check: OK"
    else
        cron_status="warning"
        log_warning "Cron check: FAILED (triggering cron)"
        trigger_cron
    fi

    # Disk-Check
    if check_disk_space; then
        disk_status="ok"
        log "Disk space check: OK"
    else
        disk_status="critical"
        log_error "Disk space check: CRITICAL"
        send_notification "WARNING" "Speicherplatz knapp auf /var/moodledata"
    fi

    # PHP-Check
    if check_php; then
        php_status="ok"
        log "PHP/Apache check: OK"
    else
        php_status="failed"
        log_error "PHP/Apache check: FAILED"
        all_ok=false
    fi

    # Ergebnis verarbeiten
    if [ "$all_ok" = "true" ]; then
        log "All health checks passed"
        reset_failures
        update_status "healthy"
        exit 0
    else
        log_error "Health checks failed"

        # Failure-Counter erhoehen
        local failures=$(get_failures)
        failures=$((failures + 1))
        set_failures "$failures"

        log_error "Failure count: $failures / $MAX_FAILURES"

        if [ "$failures" -ge "$MAX_FAILURES" ] && [ "$AUTO_RESTART" = "1" ]; then
            send_notification "CRITICAL" "Health-Check fehlgeschlagen ($failures Fehler) - Starte Wiederherstellung"
            perform_recovery
        fi

        update_status "unhealthy"
        exit 1
    fi
}

# Script ausfuehren
main "$@"
