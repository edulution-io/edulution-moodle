#!/bin/bash
# =============================================================================
# Edulution Moodle - Language Installation
# =============================================================================
# Installiert Sprachpakete via moosh und setzt Default-Sprache
#
# Usage: install_languages.sh [--force]
# =============================================================================

set -e

# =============================================================================
# CONFIGURATION
# =============================================================================

MOODLE_PATH="/var/www/html/moodle"

# Konfigurierte Sprachen (kommasepariert)
LANGUAGES="${MOODLE_INSTALL_LANGUAGES:-de,de_comm}"
DEFAULT_LANG="${MOODLE_DEFAULT_LANGUAGE:-de_comm}"

# Force-Flag fuer Neuinstallation
FORCE=0
[ "$1" = "--force" ] && FORCE=1

# =============================================================================
# LOGGING
# =============================================================================

LOG_FILE="/var/log/moodle-maintenance/languages.log"
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
# FUNCTIONS
# =============================================================================

# Pruefen ob Sprache bereits installiert
is_language_installed() {
    local lang="$1"
    local lang_dir="/var/moodledata/lang/${lang}"

    if [ -d "$lang_dir" ]; then
        return 0
    else
        return 1
    fi
}

# Sprache installieren
install_language() {
    local lang="$1"

    log "Installing language: $lang"

    cd "$MOODLE_PATH"

    # Sprache via moosh installieren
    if moosh -n language-install "$lang" 2>&1; then
        log "Language $lang installed successfully"
        return 0
    else
        log_warning "Could not install language $lang"
        return 1
    fi
}

# Sprache aktualisieren
update_language() {
    local lang="$1"

    log "Updating language: $lang"

    cd "$MOODLE_PATH"

    # Sprache via CLI aktualisieren
    sudo -u www-data php admin/tool/langimport/cli/import.php --langupdate "$lang" 2>/dev/null || \
        moosh -n language-install "$lang" 2>/dev/null || \
        log_warning "Could not update language $lang"
}

# Alle Sprachen installieren
install_all_languages() {
    log "Installing language packs: $LANGUAGES"

    # Sprachen parsen (kommasepariert)
    IFS=',' read -ra LANGS <<< "$LANGUAGES"

    local installed_count=0
    local failed_count=0

    for lang in "${LANGS[@]}"; do
        # Whitespace entfernen
        lang=$(echo "$lang" | tr -d ' ')

        if [ -z "$lang" ]; then
            continue
        fi

        # Pruefen ob bereits installiert
        if is_language_installed "$lang" && [ "$FORCE" != "1" ]; then
            log "Language $lang already installed, skipping (use --force to reinstall)"
            continue
        fi

        # Sprache installieren
        if install_language "$lang"; then
            installed_count=$((installed_count + 1))
        else
            failed_count=$((failed_count + 1))
        fi
    done

    log "Languages installed: $installed_count, failed: $failed_count"
}

# Default-Sprache setzen
set_default_language() {
    log "Setting default language to: $DEFAULT_LANG"

    cd "$MOODLE_PATH"

    # Default-Sprache setzen
    moosh -n config-set lang "$DEFAULT_LANG" 2>/dev/null || {
        log_warning "Could not set default language via moosh, trying PHP..."
        sudo -u www-data php admin/cli/cfg.php --name=lang --set="$DEFAULT_LANG" 2>/dev/null || \
            log_error "Failed to set default language"
    }

    # Sprachmenue aktivieren
    moosh -n config-set langmenu 1 2>/dev/null || true

    # Auto-Language Detection deaktivieren
    moosh -n config-set autolang 0 2>/dev/null || true

    log "Default language set to $DEFAULT_LANG"
}

# System-Locale installieren (falls noetig)
install_system_locale() {
    local locale_lang="${DEFAULT_LANG%%_*}"  # z.B. "de" aus "de_comm"

    log "Checking system locale for: $locale_lang"

    # Nur auf Debian/Ubuntu
    if command -v locale-gen &>/dev/null; then
        case "$locale_lang" in
            de)
                if ! locale -a | grep -q "de_DE.utf8"; then
                    log "Installing German locale..."
                    echo "de_DE.UTF-8 UTF-8" >> /etc/locale.gen
                    locale-gen de_DE.UTF-8 2>/dev/null || true
                fi
                ;;
            fr)
                if ! locale -a | grep -q "fr_FR.utf8"; then
                    log "Installing French locale..."
                    echo "fr_FR.UTF-8 UTF-8" >> /etc/locale.gen
                    locale-gen fr_FR.UTF-8 2>/dev/null || true
                fi
                ;;
            es)
                if ! locale -a | grep -q "es_ES.utf8"; then
                    log "Installing Spanish locale..."
                    echo "es_ES.UTF-8 UTF-8" >> /etc/locale.gen
                    locale-gen es_ES.UTF-8 2>/dev/null || true
                fi
                ;;
        esac
    fi
}

# Sprachcache leeren
clear_language_cache() {
    log "Clearing language cache..."

    cd "$MOODLE_PATH"

    # Sprachcache leeren
    sudo -u www-data php admin/cli/purge_caches.php --lang 2>/dev/null || \
        sudo -u www-data php admin/cli/purge_caches.php 2>/dev/null || \
        log_warning "Could not clear language cache"

    log "Language cache cleared"
}

# Verfuegbare Sprachen auflisten
list_available_languages() {
    log "Listing available languages..."

    cd "$MOODLE_PATH"

    moosh -n language-list 2>/dev/null | head -50 || \
        log_warning "Could not list available languages"
}

# Installierte Sprachen anzeigen
show_installed_languages() {
    log "Currently installed languages:"

    if [ -d "/var/moodledata/lang" ]; then
        ls -la /var/moodledata/lang/ 2>/dev/null || true
    fi

    # Aktuelle Sprache anzeigen
    cd "$MOODLE_PATH"
    local current_lang=$(moosh -n config-get core lang 2>/dev/null || echo "unknown")
    log "Current default language: $current_lang"
}

# =============================================================================
# MAIN
# =============================================================================

main() {
    log "========================================"
    log "Starting language installation"
    log "========================================"

    # In Moodle-Verzeichnis wechseln
    cd "$MOODLE_PATH"

    # System-Locale installieren
    install_system_locale

    # Sprachen installieren
    install_all_languages

    # Default-Sprache setzen
    set_default_language

    # Cache leeren
    clear_language_cache

    # Berechtigungen korrigieren
    chown -R www-data:www-data /var/moodledata/lang 2>/dev/null || true

    # Status anzeigen
    show_installed_languages

    log "========================================"
    log "Language installation completed"
    log "========================================"
}

# Script ausfuehren
main "$@"
