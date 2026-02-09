#!/bin/bash
# =============================================================================
# Edulution Moodle - Database Optimization
# =============================================================================
# MariaDB Wartung und Optimierung
#
# Usage: optimize_database.sh [--analyze-only] [--force]
# =============================================================================

set -e

# =============================================================================
# CONFIGURATION
# =============================================================================

DB_HOST="${MOODLE_DATABASE_HOST:-localhost}"
DB_NAME="${MOODLE_DATABASE_NAME:-moodle}"
DB_USER="${MOODLE_DATABASE_USER:-moodle}"
DB_PASS="${MOODLE_DATABASE_PASSWORD}"
DB_PORT="${MOODLE_DATABASE_PORT:-3306}"

# Log-Retention (Tage)
LOG_RETENTION_DAYS="${DB_LOG_RETENTION_DAYS:-90}"
TASK_LOG_RETENTION_DAYS="${DB_TASK_LOG_RETENTION_DAYS:-30}"

# Fragmentierungs-Schwellwert (Bytes) fuer Optimierung
FRAGMENTATION_THRESHOLD="${DB_FRAGMENTATION_THRESHOLD:-10485760}"  # 10MB

# Optionen
ANALYZE_ONLY=0
FORCE=0

while [[ $# -gt 0 ]]; do
    case $1 in
        --analyze-only)
            ANALYZE_ONLY=1
            shift
            ;;
        --force)
            FORCE=1
            shift
            ;;
        *)
            echo "Unknown option: $1"
            echo "Usage: optimize_database.sh [--analyze-only] [--force]"
            exit 1
            ;;
    esac
done

# =============================================================================
# LOGGING
# =============================================================================

LOG_FILE="/var/log/moodle-maintenance/db-optimize.log"
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

log_warning() {
    local message="[$(date '+%Y-%m-%d %H:%M:%S')] WARNING: $1"
    echo "$message"
    echo "$message" >> "$LOG_FILE"
}

# =============================================================================
# DATABASE CONNECTION
# =============================================================================

# MySQL-Befehl mit Credentials
mysql_cmd() {
    mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" "$@" 2>/dev/null
}

# Verbindung testen
test_connection() {
    log "Testing database connection..."

    if mysql_cmd -e "SELECT 1" &>/dev/null; then
        log "Database connection: OK"
        return 0
    else
        log_error "Cannot connect to database"
        return 1
    fi
}

# =============================================================================
# TABLE ANALYSIS
# =============================================================================

analyze_tables() {
    log "Analyzing tables..."

    local tables=$(mysql_cmd -N -e "
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = '$DB_NAME'
        AND table_type = 'BASE TABLE'
    ")

    local count=0

    while IFS= read -r table; do
        if [ -n "$table" ]; then
            mysql_cmd -e "ANALYZE TABLE \`$table\`" >/dev/null
            count=$((count + 1))
        fi
    done <<< "$tables"

    log "Analyzed $count tables"
}

# =============================================================================
# TABLE OPTIMIZATION
# =============================================================================

get_fragmented_tables() {
    mysql_cmd -N -e "
        SELECT table_name, data_free
        FROM information_schema.tables
        WHERE table_schema = '$DB_NAME'
        AND data_free > $FRAGMENTATION_THRESHOLD
        AND table_type = 'BASE TABLE'
        ORDER BY data_free DESC
    "
}

optimize_tables() {
    log "Looking for fragmented tables (threshold: $((FRAGMENTATION_THRESHOLD / 1024 / 1024))MB)..."

    local fragmented=$(get_fragmented_tables)

    if [ -z "$fragmented" ]; then
        log "No fragmented tables found"
        return
    fi

    local count=0
    local total_freed=0

    while IFS=$'\t' read -r table data_free; do
        if [ -n "$table" ]; then
            local size_mb=$((data_free / 1024 / 1024))
            log "Optimizing table $table (fragmented: ${size_mb}MB)..."

            if [ "$ANALYZE_ONLY" != "1" ]; then
                mysql_cmd -e "OPTIMIZE TABLE \`$table\`" >/dev/null
                total_freed=$((total_freed + data_free))
                count=$((count + 1))
            else
                log "  (skipped - analyze-only mode)"
            fi
        fi
    done <<< "$fragmented"

    local freed_mb=$((total_freed / 1024 / 1024))
    log "Optimized $count tables, freed approximately ${freed_mb}MB"
}

# =============================================================================
# LOG CLEANUP
# =============================================================================

cleanup_logs() {
    log "Cleaning up old log entries..."

    if [ "$ANALYZE_ONLY" = "1" ]; then
        log "Skipping cleanup (analyze-only mode)"
        return
    fi

    # Standard-Logs bereinigen
    local deleted_logs=$(mysql_cmd -N -e "
        SELECT COUNT(*) FROM mdl_logstore_standard_log
        WHERE timecreated < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $LOG_RETENTION_DAYS DAY))
    ")

    if [ "$deleted_logs" -gt 0 ]; then
        log "Deleting $deleted_logs old log entries (older than $LOG_RETENTION_DAYS days)..."

        # In Batches loeschen um Lock-Timeouts zu vermeiden
        local batch_size=10000
        local deleted_total=0

        while true; do
            local deleted=$(mysql_cmd -N -e "
                DELETE FROM mdl_logstore_standard_log
                WHERE timecreated < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $LOG_RETENTION_DAYS DAY))
                LIMIT $batch_size;
                SELECT ROW_COUNT();
            ")

            if [ "$deleted" -eq 0 ]; then
                break
            fi

            deleted_total=$((deleted_total + deleted))
            log "  Deleted batch: $deleted (total: $deleted_total)"
            sleep 1  # Kurze Pause zwischen Batches
        done

        log "Deleted $deleted_total log entries"
    else
        log "No old log entries to delete"
    fi

    # Task-Logs bereinigen
    local deleted_tasks=$(mysql_cmd -N -e "
        DELETE FROM mdl_task_log
        WHERE timestart < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL $TASK_LOG_RETENTION_DAYS DAY));
        SELECT ROW_COUNT();
    ")

    log "Deleted $deleted_tasks old task log entries"
}

# =============================================================================
# SESSION CLEANUP
# =============================================================================

cleanup_sessions() {
    log "Cleaning up expired sessions..."

    if [ "$ANALYZE_ONLY" = "1" ]; then
        log "Skipping session cleanup (analyze-only mode)"
        return
    fi

    # Abgelaufene Sessions loeschen
    local deleted=$(mysql_cmd -N -e "
        DELETE FROM mdl_sessions
        WHERE timemodified < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 DAY));
        SELECT ROW_COUNT();
    ")

    log "Deleted $deleted expired sessions"
}

# =============================================================================
# CACHE CLEANUP
# =============================================================================

cleanup_cache_tables() {
    log "Cleaning up cache tables..."

    if [ "$ANALYZE_ONLY" = "1" ]; then
        log "Skipping cache cleanup (analyze-only mode)"
        return
    fi

    # Cache-Tabellen leeren (werden automatisch neu aufgebaut)
    local cache_tables=(
        "mdl_cache_flags"
        "mdl_cache_text"
    )

    for table in "${cache_tables[@]}"; do
        if mysql_cmd -e "SELECT 1 FROM \`$table\` LIMIT 1" &>/dev/null; then
            local count=$(mysql_cmd -N -e "SELECT COUNT(*) FROM \`$table\`")
            if [ "$count" -gt 10000 ]; then
                log "Truncating cache table $table ($count entries)..."
                mysql_cmd -e "TRUNCATE TABLE \`$table\`"
            fi
        fi
    done

    log "Cache tables cleaned"
}

# =============================================================================
# DATABASE STATISTICS
# =============================================================================

show_statistics() {
    log "Database Statistics:"
    log "===================="

    # Gesamtgroesse
    local total_size=$(mysql_cmd -N -e "
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
        FROM information_schema.tables
        WHERE table_schema = '$DB_NAME'
    ")
    log "Total database size: ${total_size}MB"

    # Anzahl Tabellen
    local table_count=$(mysql_cmd -N -e "
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = '$DB_NAME'
        AND table_type = 'BASE TABLE'
    ")
    log "Number of tables: $table_count"

    # Groesste Tabellen
    log "Largest tables:"
    mysql_cmd -e "
        SELECT
            table_name AS 'Table',
            ROUND((data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)',
            table_rows AS 'Rows'
        FROM information_schema.tables
        WHERE table_schema = '$DB_NAME'
        AND table_type = 'BASE TABLE'
        ORDER BY (data_length + index_length) DESC
        LIMIT 10
    " | while IFS= read -r line; do
        log "  $line"
    done

    # Fragmentierte Tabellen
    log "Fragmented tables (>10MB free space):"
    mysql_cmd -e "
        SELECT
            table_name AS 'Table',
            ROUND(data_free / 1024 / 1024, 2) AS 'Fragmented (MB)'
        FROM information_schema.tables
        WHERE table_schema = '$DB_NAME'
        AND data_free > 10485760
        AND table_type = 'BASE TABLE'
        ORDER BY data_free DESC
        LIMIT 5
    " | while IFS= read -r line; do
        log "  $line"
    done
}

# =============================================================================
# CHECK ENGINE STATUS
# =============================================================================

check_innodb_status() {
    log "Checking InnoDB status..."

    # Buffer Pool Usage
    local buffer_stats=$(mysql_cmd -N -e "
        SELECT
            ROUND(@@innodb_buffer_pool_size / 1024 / 1024) AS pool_size_mb,
            (SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024)
             FROM information_schema.tables
             WHERE table_schema = '$DB_NAME') AS data_size_mb
    ")

    local pool_size=$(echo "$buffer_stats" | cut -f1)
    local data_size=$(echo "$buffer_stats" | cut -f2)

    log "InnoDB Buffer Pool: ${pool_size}MB"
    log "Data Size: ${data_size}MB"

    if [ "$data_size" -gt "$pool_size" ]; then
        log_warning "Data size exceeds buffer pool size - consider increasing innodb_buffer_pool_size"
    fi
}

# =============================================================================
# REPAIR TABLES (nur bei --force)
# =============================================================================

repair_tables() {
    if [ "$FORCE" != "1" ]; then
        return
    fi

    log "Checking and repairing tables..."

    local tables=$(mysql_cmd -N -e "
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = '$DB_NAME'
        AND table_type = 'BASE TABLE'
        AND engine = 'MyISAM'
    ")

    if [ -z "$tables" ]; then
        log "No MyISAM tables to repair"
        return
    fi

    while IFS= read -r table; do
        if [ -n "$table" ]; then
            log "Checking table: $table"
            mysql_cmd -e "CHECK TABLE \`$table\`" >/dev/null
            mysql_cmd -e "REPAIR TABLE \`$table\`" >/dev/null
        fi
    done <<< "$tables"

    log "Table repair completed"
}

# =============================================================================
# MAIN
# =============================================================================

main() {
    log "========================================"
    log "Starting database optimization"
    [ "$ANALYZE_ONLY" = "1" ] && log "Mode: ANALYZE ONLY"
    [ "$FORCE" = "1" ] && log "Mode: FORCE (includes repair)"
    log "========================================"

    # Verbindung testen
    test_connection || exit 1

    # Statistiken anzeigen (vorher)
    show_statistics

    # InnoDB-Status pruefen
    check_innodb_status

    # Tabellen analysieren
    analyze_tables

    # Fragmentierte Tabellen optimieren
    optimize_tables

    # Logs bereinigen
    cleanup_logs

    # Sessions bereinigen
    cleanup_sessions

    # Cache-Tabellen bereinigen
    cleanup_cache_tables

    # Tabellen reparieren (nur bei --force)
    repair_tables

    # Erneut analysieren nach Optimierung
    if [ "$ANALYZE_ONLY" != "1" ]; then
        log "Re-analyzing tables after optimization..."
        analyze_tables
    fi

    # Statistiken anzeigen (nachher)
    log ""
    log "Final Statistics:"
    show_statistics

    log "========================================"
    log "Database optimization completed"
    log "========================================"
}

# Script ausfuehren
main "$@"
