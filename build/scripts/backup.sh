#!/bin/bash
# =============================================================================
# Edulution Moodle - Backup System
# =============================================================================
# Vollstaendiges Backup-System mit verschiedenen Modi und Rotation
#
# Usage: backup.sh [--full|--quick|--db-only] [--notify]
# =============================================================================

set -e

# =============================================================================
# CONFIGURATION
# =============================================================================

BACKUP_PATH="${BACKUP_PATH:-/srv/backups}"
RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"
MOODLE_PATH="/var/www/html/moodle"
MOODLEDATA_PATH="/var/moodledata"

TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="$BACKUP_PATH/$TIMESTAMP"

# Parse command line arguments
BACKUP_TYPE="full"
NOTIFY=0

while [[ $# -gt 0 ]]; do
    case $1 in
        --full)
            BACKUP_TYPE="full"
            shift
            ;;
        --quick)
            BACKUP_TYPE="quick"
            shift
            ;;
        --db-only)
            BACKUP_TYPE="db-only"
            shift
            ;;
        --notify)
            NOTIFY=1
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: backup.sh [--full|--quick|--db-only] [--notify]"
            exit 1
            ;;
    esac
done

# =============================================================================
# LOGGING
# =============================================================================

LOG_FILE="/var/log/moodle-maintenance/backup.log"
mkdir -p "$(dirname "$LOG_FILE")"

log() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] $1"
    echo "$message"
    echo "$message" >> "$LOG_FILE"
}

log_error() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1"
    echo "$message" >&2
    echo "$message" >> "$LOG_FILE"
}

# =============================================================================
# NOTIFICATION
# =============================================================================

send_notification() {
    local status="$1"
    local message="$2"

    if [ "$NOTIFY" = "1" ] && [ -n "$WEBHOOK_URL" ]; then
        local emoji="INFO"
        [ "$status" = "success" ] && emoji="SUCCESS"
        [ "$status" = "error" ] && emoji="ERROR"

        curl -s -X POST "$WEBHOOK_URL" \
            -H "Content-Type: application/json" \
            -d "{\"text\": \"[$emoji] Moodle Backup: $message\"}" \
            2>/dev/null || true
    fi

    # E-Mail-Benachrichtigung falls konfiguriert
    if [ "$NOTIFY" = "1" ] && [ -n "$ADMIN_EMAIL" ] && command -v mail &>/dev/null; then
        echo "$message" | mail -s "Moodle Backup: $status" "$ADMIN_EMAIL" 2>/dev/null || true
    fi
}

# =============================================================================
# PRE-BACKUP CHECKS
# =============================================================================

pre_backup_checks() {
    log "Running pre-backup checks..."

    # Backup-Verzeichnis pruefen/erstellen
    if [ ! -d "$BACKUP_PATH" ]; then
        mkdir -p "$BACKUP_PATH"
        log "Created backup directory: $BACKUP_PATH"
    fi

    # Speicherplatz pruefen
    local available_space=$(df -BG "$BACKUP_PATH" | awk 'NR==2 {print $4}' | tr -d 'G')
    local min_space="${BACKUP_MIN_SPACE_GB:-5}"

    if [ "$available_space" -lt "$min_space" ]; then
        log_error "Insufficient disk space: ${available_space}GB available, ${min_space}GB required"
        send_notification "error" "Backup abgebrochen - Nicht genuegend Speicherplatz"
        exit 1
    fi

    # Datenbank-Verbindung pruefen
    if ! mysql -h "${MOODLE_DATABASE_HOST}" \
               -u "${MOODLE_DATABASE_USER}" \
               -p"${MOODLE_DATABASE_PASSWORD}" \
               -e "SELECT 1" &>/dev/null; then
        log_error "Cannot connect to database"
        send_notification "error" "Backup abgebrochen - Datenbankverbindung fehlgeschlagen"
        exit 1
    fi

    log "Pre-backup checks passed"
}

# =============================================================================
# DATABASE BACKUP
# =============================================================================

backup_database() {
    log "Backing up database..."

    local db_backup_file="$BACKUP_DIR/database.sql.gz"

    mysqldump \
        -h"${MOODLE_DATABASE_HOST}" \
        -u"${MOODLE_DATABASE_USER}" \
        -p"${MOODLE_DATABASE_PASSWORD}" \
        --single-transaction \
        --quick \
        --routines \
        --triggers \
        --events \
        "${MOODLE_DATABASE_NAME}" \
        2>/dev/null | gzip > "$db_backup_file"

    if [ $? -eq 0 ]; then
        log "Database backup completed: $(du -h "$db_backup_file" | cut -f1)"
    else
        log_error "Database backup failed!"
        return 1
    fi
}

# =============================================================================
# CODE BACKUP
# =============================================================================

backup_code() {
    log "Backing up Moodle code..."

    tar -czf "$BACKUP_DIR/moodle_code.tar.gz" \
        -C /var/www/html moodle \
        --exclude='moodle/.git' \
        --exclude='moodle/node_modules' \
        2>/dev/null

    if [ $? -eq 0 ]; then
        log "Code backup completed: $(du -h "$BACKUP_DIR/moodle_code.tar.gz" | cut -f1)"
    else
        log_error "Code backup failed!"
        return 1
    fi
}

# =============================================================================
# CONFIG BACKUP
# =============================================================================

backup_config() {
    log "Backing up configuration..."

    # Config.php sichern
    if [ -f "$MOODLE_PATH/config.php" ]; then
        cp "$MOODLE_PATH/config.php" "$BACKUP_DIR/config.php"
    fi

    # Environment-Konfiguration (ohne Secrets)
    env | grep -E '^MOODLE_|^ENABLE_|^PLUGIN_' | \
        sed 's/PASSWORD=.*/PASSWORD=***REDACTED***/g' | \
        sed 's/SECRET=.*/SECRET=***REDACTED***/g' \
        > "$BACKUP_DIR/environment.txt"

    log "Configuration backup completed"
}

# =============================================================================
# MOODLEDATA BACKUP (nur bei --full)
# =============================================================================

backup_moodledata() {
    log "Backing up moodledata (this may take a while)..."

    tar -czf "$BACKUP_DIR/moodledata.tar.gz" \
        -C /var moodledata \
        --exclude='moodledata/cache' \
        --exclude='moodledata/localcache' \
        --exclude='moodledata/sessions' \
        --exclude='moodledata/temp' \
        --exclude='moodledata/trashdir' \
        --exclude='moodledata/lock' \
        2>/dev/null

    if [ $? -eq 0 ]; then
        log "Moodledata backup completed: $(du -h "$BACKUP_DIR/moodledata.tar.gz" | cut -f1)"
    else
        log_error "Moodledata backup failed!"
        return 1
    fi
}

# =============================================================================
# BACKUP INFO JSON
# =============================================================================

create_backup_info() {
    log "Creating backup info..."

    # Moodle-Version ermitteln
    local moodle_version="unknown"
    if [ -f "$MOODLE_PATH/version.php" ]; then
        moodle_version=$(grep -oP "\\\$release\s*=\s*'\K[^']+" "$MOODLE_PATH/version.php" 2>/dev/null || echo "unknown")
    fi

    # Dateigroessen ermitteln
    local db_size="0"
    local code_size="0"
    local data_size="0"
    local total_size="0"

    [ -f "$BACKUP_DIR/database.sql.gz" ] && \
        db_size=$(du -h "$BACKUP_DIR/database.sql.gz" | cut -f1)
    [ -f "$BACKUP_DIR/moodle_code.tar.gz" ] && \
        code_size=$(du -h "$BACKUP_DIR/moodle_code.tar.gz" | cut -f1)
    [ -f "$BACKUP_DIR/moodledata.tar.gz" ] && \
        data_size=$(du -h "$BACKUP_DIR/moodledata.tar.gz" | cut -f1)

    total_size=$(du -sh "$BACKUP_DIR" | cut -f1)

    # JSON erstellen
    cat > "$BACKUP_DIR/backup_info.json" << EOF
{
    "timestamp": "$TIMESTAMP",
    "date": "$(date '+%Y-%m-%d %H:%M:%S')",
    "type": "$BACKUP_TYPE",
    "moodle_version": "$moodle_version",
    "edulution_version": "${EDULUTION_MOODLE_VERSION:-unknown}",
    "hostname": "$(hostname)",
    "files": {
        "database": {
            "name": "database.sql.gz",
            "size": "$db_size"
        },
        "code": {
            "name": "moodle_code.tar.gz",
            "size": "$code_size",
            "included": $([ -f "$BACKUP_DIR/moodle_code.tar.gz" ] && echo "true" || echo "false")
        },
        "moodledata": {
            "name": "moodledata.tar.gz",
            "size": "$data_size",
            "included": $([ -f "$BACKUP_DIR/moodledata.tar.gz" ] && echo "true" || echo "false")
        }
    },
    "total_size": "$total_size",
    "retention_days": $RETENTION_DAYS,
    "checksum": "$(sha256sum "$BACKUP_DIR/database.sql.gz" 2>/dev/null | cut -d' ' -f1 || echo "n/a")"
}
EOF

    log "Backup info created"
}

# =============================================================================
# BACKUP ROTATION
# =============================================================================

cleanup_old_backups() {
    log "Cleaning old backups (older than $RETENTION_DAYS days)..."

    local deleted_count=0

    # Alte Backups finden und loeschen
    while IFS= read -r -d '' dir; do
        rm -rf "$dir"
        deleted_count=$((deleted_count + 1))
        log "Deleted old backup: $(basename "$dir")"
    done < <(find "$BACKUP_PATH" -maxdepth 1 -type d -mtime +"$RETENTION_DAYS" -print0 2>/dev/null)

    if [ "$deleted_count" -gt 0 ]; then
        log "Deleted $deleted_count old backup(s)"
    else
        log "No old backups to delete"
    fi
}

# =============================================================================
# VERIFICATION
# =============================================================================

verify_backup() {
    log "Verifying backup integrity..."

    local errors=0

    # Datenbank-Backup pruefen
    if [ -f "$BACKUP_DIR/database.sql.gz" ]; then
        if gzip -t "$BACKUP_DIR/database.sql.gz" 2>/dev/null; then
            log "Database backup integrity: OK"
        else
            log_error "Database backup integrity: FAILED"
            errors=$((errors + 1))
        fi
    fi

    # Code-Backup pruefen
    if [ -f "$BACKUP_DIR/moodle_code.tar.gz" ]; then
        if tar -tzf "$BACKUP_DIR/moodle_code.tar.gz" &>/dev/null; then
            log "Code backup integrity: OK"
        else
            log_error "Code backup integrity: FAILED"
            errors=$((errors + 1))
        fi
    fi

    # Moodledata-Backup pruefen
    if [ -f "$BACKUP_DIR/moodledata.tar.gz" ]; then
        if tar -tzf "$BACKUP_DIR/moodledata.tar.gz" &>/dev/null; then
            log "Moodledata backup integrity: OK"
        else
            log_error "Moodledata backup integrity: FAILED"
            errors=$((errors + 1))
        fi
    fi

    return $errors
}

# =============================================================================
# MAIN
# =============================================================================

main() {
    log "========================================"
    log "Starting $BACKUP_TYPE backup to $BACKUP_DIR"
    log "========================================"

    # Pre-Checks
    pre_backup_checks

    # Backup-Verzeichnis erstellen
    mkdir -p "$BACKUP_DIR"

    # Datenbank sichern (immer)
    backup_database || exit 1

    # Bei db-only hier aufhoeren
    if [ "$BACKUP_TYPE" = "db-only" ]; then
        create_backup_info
        log "Database-only backup completed"
        send_notification "success" "Datenbank-Backup erfolgreich: $TIMESTAMP"
        exit 0
    fi

    # Code und Config sichern
    backup_code || exit 1
    backup_config

    # Bei full auch moodledata sichern
    if [ "$BACKUP_TYPE" = "full" ]; then
        backup_moodledata || exit 1
    fi

    # Backup-Info erstellen
    create_backup_info

    # Backup verifizieren
    if verify_backup; then
        log "Backup verification passed"
    else
        log_error "Backup verification failed!"
        send_notification "error" "Backup fehlgeschlagen: $TIMESTAMP"
        exit 1
    fi

    # Alte Backups aufraeuemen
    cleanup_old_backups

    log "========================================"
    log "Backup completed successfully!"
    log "Location: $BACKUP_DIR"
    log "Total size: $(du -sh "$BACKUP_DIR" | cut -f1)"
    log "========================================"

    send_notification "success" "$BACKUP_TYPE Backup erfolgreich: $TIMESTAMP ($(du -sh "$BACKUP_DIR" | cut -f1))"
}

# Script ausfuehren
main "$@"
