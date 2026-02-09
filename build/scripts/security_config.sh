#!/bin/bash
# =============================================================================
# Edulution Moodle - Security Configuration
# =============================================================================
# Setzt Moodle Security-Einstellungen via moosh
#
# Usage: security_config.sh [--strict]
# =============================================================================

set -e

# =============================================================================
# CONFIGURATION
# =============================================================================

MOODLE_PATH="/var/www/html/moodle"

# Strict-Mode fuer erhoehte Sicherheit
STRICT_MODE=0
[ "$1" = "--strict" ] && STRICT_MODE=1

# =============================================================================
# LOGGING
# =============================================================================

LOG_FILE="/var/log/moodle-maintenance/security.log"
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
# HELPER FUNCTIONS
# =============================================================================

# Moosh-Konfiguration setzen mit Fehlerbehandlung
set_config() {
    local name="$1"
    local value="$2"
    local plugin="${3:-}"

    cd "$MOODLE_PATH"

    if [ -n "$plugin" ]; then
        moosh -n config-set "$name" "$value" "$plugin" 2>/dev/null && \
            log "Set $name = $value (plugin: $plugin)" || \
            log_error "Failed to set $name"
    else
        moosh -n config-set "$name" "$value" 2>/dev/null && \
            log "Set $name = $value" || \
            log_error "Failed to set $name"
    fi
}

# =============================================================================
# PASSWORD POLICY
# =============================================================================

configure_password_policy() {
    log "Configuring password policy..."

    # Mindestlaenge (ENV oder Default)
    local min_length="${MOODLE_PASSWORD_MIN_LENGTH:-12}"
    set_config "minpasswordlength" "$min_length"

    # Komplexitaetsanforderungen
    set_config "minpassworddigits" "${MOODLE_PASSWORD_MIN_DIGITS:-1}"
    set_config "minpasswordlower" "${MOODLE_PASSWORD_MIN_LOWER:-1}"
    set_config "minpasswordupper" "${MOODLE_PASSWORD_MIN_UPPER:-1}"
    set_config "minpasswordnonalphanum" "${MOODLE_PASSWORD_MIN_SPECIAL:-1}"

    # Keine aufeinanderfolgenden identischen Zeichen
    set_config "maxconsecutiveidentchars" "${MOODLE_PASSWORD_MAX_CONSECUTIVE:-3}"

    # Passwort-Wiederverwendung verhindern
    set_config "passwordreuselimit" "${MOODLE_PASSWORD_REUSE_LIMIT:-5}"

    # Passwort-Ablauf (0 = nie, Wert in Tagen)
    set_config "passwordrotation" "${MOODLE_PASSWORD_ROTATION_DAYS:-0}"

    log "Password policy configured"
}

# =============================================================================
# LOGIN SECURITY
# =============================================================================

configure_login_security() {
    log "Configuring login security..."

    # Account-Sperrung nach fehlgeschlagenen Versuchen
    set_config "lockoutthreshold" "${MOODLE_LOCKOUT_THRESHOLD:-5}"

    # Zeitfenster fuer Fehlversuche (Sekunden)
    set_config "lockoutwindow" "${MOODLE_LOCKOUT_WINDOW:-1800}"

    # Sperrdauer (Sekunden)
    set_config "lockoutduration" "${MOODLE_LOCKOUT_DURATION:-1800}"

    # Gast-Login deaktivieren (Sicherheitsempfehlung)
    if [ "${MOODLE_ALLOW_GUEST:-0}" != "1" ]; then
        set_config "guestloginbutton" "0"
        log "Guest login disabled"
    fi

    # E-Mail-Login erlauben
    set_config "authloginviaemail" "${MOODLE_LOGIN_VIA_EMAIL:-0}"

    # Standard-Authentifizierung
    if [ "${ENABLE_SSO:-0}" = "1" ]; then
        # Bei SSO: Email-Auth deaktivieren
        cd "$MOODLE_PATH"
        moosh -n auth-manage disable email 2>/dev/null || true
        log "Email authentication disabled (SSO mode)"
    fi

    log "Login security configured"
}

# =============================================================================
# SESSION SECURITY
# =============================================================================

configure_session_security() {
    log "Configuring session security..."

    # Session-Timeout (Sekunden)
    set_config "sessiontimeout" "${MOODLE_SESSION_TIMEOUT:-7200}"

    # Session-Timeout-Warnung (Sekunden vor Ablauf)
    set_config "sessiontimeoutwarning" "${MOODLE_SESSION_WARNING:-300}"

    # Sichere Cookies
    set_config "cookiesecure" "1"
    set_config "cookiehttponly" "1"

    # SameSite Cookie-Attribut
    set_config "cookiesamesite" "${MOODLE_COOKIE_SAMESITE:-Lax}"

    log "Session security configured"
}

# =============================================================================
# NETWORK SECURITY
# =============================================================================

configure_network_security() {
    log "Configuring network security..."

    # Geblockte Hosts fuer cURL (SSRF-Schutz)
    local blocked_hosts=$(cat <<'EOF'
127.0.0.0/8
192.168.0.0/16
10.0.0.0/8
172.16.0.0/12
0.0.0.0
localhost
169.254.169.254
0000::1
::1
EOF
)

    # Neue Zeilen durch Zeilenumbrueche ersetzen
    blocked_hosts=$(echo "$blocked_hosts" | tr '\n' $'\n')

    set_config "curlsecurityblockedhosts" "$blocked_hosts"

    # Erlaubte Ports
    set_config "curlsecurityallowedport" "443,80"

    # Proxy-Einstellungen (falls konfiguriert)
    if [ -n "${HTTP_PROXY}" ]; then
        set_config "proxyhost" "${HTTP_PROXY_HOST:-}"
        set_config "proxyport" "${HTTP_PROXY_PORT:-8080}"
        set_config "proxytype" "${HTTP_PROXY_TYPE:-HTTP}"
    fi

    log "Network security configured"
}

# =============================================================================
# FILE SECURITY
# =============================================================================

configure_file_security() {
    log "Configuring file security..."

    # Erlaubte Dateierweiterungen fuer Uploads
    # Standard-Moodle-Extensions beibehalten, gefaehrliche entfernen
    local blocked_extensions="exe,bat,cmd,com,scr,pif,vbs,vbe,js,jse,wsf,wsh,msc,jar,php,php3,php4,php5,phtml,htaccess"
    set_config "blockedextensions" "$blocked_extensions"

    # Maximale Upload-Groesse (Bytes)
    set_config "maxbytes" "${MOODLE_MAX_UPLOAD_SIZE:-104857600}"  # 100MB

    # Antivirus (falls ClamAV verfuegbar)
    if command -v clamscan &>/dev/null; then
        set_config "runclamavonupload" "1" "antivirus_clamav"
        set_config "pathtoclam" "$(which clamscan)" "antivirus_clamav"
        log "ClamAV antivirus enabled"
    fi

    log "File security configured"
}

# =============================================================================
# DEBUG & LOGGING
# =============================================================================

configure_debug_settings() {
    log "Configuring debug settings..."

    # Produktionsmodus: Debug ausschalten
    if [ "${MOODLE_DEBUG:-0}" != "1" ]; then
        set_config "debug" "0"
        set_config "debugdisplay" "0"
        set_config "debugstringids" "0"
        set_config "debugsqltrace" "0"
        set_config "perfdebug" "0"
        set_config "debugpageinfo" "0"
        log "Debug mode disabled (production)"
    else
        log "Debug mode enabled (development)"
    fi

    # Error-Logging
    set_config "showcampaigncontent" "0"

    log "Debug settings configured"
}

# =============================================================================
# CRON SECURITY
# =============================================================================

configure_cron_security() {
    log "Configuring cron security..."

    # Cron-Passwort fuer externe Aufrufe
    if [ -z "${MOODLE_CRON_PASSWORD}" ]; then
        local cron_password=$(openssl rand -base64 32)
        set_config "cronremotepassword" "$cron_password"
        log "Generated new cron password"
    else
        set_config "cronremotepassword" "${MOODLE_CRON_PASSWORD}"
    fi

    # Cron nur von CLI erlauben
    set_config "cronclionly" "${MOODLE_CRON_CLI_ONLY:-1}"

    log "Cron security configured"
}

# =============================================================================
# EMAIL SECURITY
# =============================================================================

configure_email_security() {
    log "Configuring email security..."

    # E-Mail-Aenderung bestaetigen
    set_config "emailchangeconfirmation" "1"

    # E-Mail-Validierung
    set_config "emailvalidationrequirement" "${MOODLE_EMAIL_VALIDATION:-0}"

    # Bounce-Handling
    set_config "handlebounces" "${MOODLE_HANDLE_BOUNCES:-0}"

    log "Email security configured"
}

# =============================================================================
# STRICT MODE (Additional Hardening)
# =============================================================================

configure_strict_mode() {
    if [ "$STRICT_MODE" != "1" ]; then
        return
    fi

    log "Applying strict mode settings..."

    # HTTPS erzwingen
    set_config "loginhttps" "1"

    # IP-Bindung fuer Sessions
    set_config "strictsessionstorage" "1"

    # Strengere Content-Security-Policy
    set_config "cspheader" "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:;"

    # Referrer-Policy
    set_config "referrerpolicy" "strict-origin-when-cross-origin"

    # X-Frame-Options
    set_config "xframeheader" "SAMEORIGIN"

    # HSTS (wird normalerweise vom Reverse-Proxy gesetzt)
    # set_config "hstsheader" "max-age=31536000; includeSubDomains"

    # Profilfelder verstecken
    set_config "hiddenuserfields" "email,phone1,phone2,address,city,country,description"

    # User-Enumeration verhindern
    set_config "protectusernames" "1"

    log "Strict mode settings applied"
}

# =============================================================================
# VERIFICATION
# =============================================================================

verify_settings() {
    log "Verifying security settings..."

    cd "$MOODLE_PATH"

    # Wichtige Einstellungen pruefen
    local checks_passed=0
    local checks_failed=0

    # Password-Laenge pruefen
    local min_pass=$(moosh -n config-get core minpasswordlength 2>/dev/null || echo "0")
    if [ "$min_pass" -ge 12 ]; then
        log "Password length check: PASS ($min_pass)"
        checks_passed=$((checks_passed + 1))
    else
        log_error "Password length check: FAIL ($min_pass < 12)"
        checks_failed=$((checks_failed + 1))
    fi

    # Cookie-Security pruefen
    local cookie_secure=$(moosh -n config-get core cookiesecure 2>/dev/null || echo "0")
    if [ "$cookie_secure" = "1" ]; then
        log "Secure cookies check: PASS"
        checks_passed=$((checks_passed + 1))
    else
        log_error "Secure cookies check: FAIL"
        checks_failed=$((checks_failed + 1))
    fi

    # Debug-Mode pruefen (sollte in Produktion aus sein)
    local debug_mode=$(moosh -n config-get core debug 2>/dev/null || echo "0")
    if [ "$debug_mode" = "0" ] || [ "${MOODLE_DEBUG:-0}" = "1" ]; then
        log "Debug mode check: PASS"
        checks_passed=$((checks_passed + 1))
    else
        log_error "Debug mode check: WARNING (debug enabled in production)"
        checks_failed=$((checks_failed + 1))
    fi

    log "Security verification: $checks_passed passed, $checks_failed failed"
}

# =============================================================================
# MAIN
# =============================================================================

main() {
    log "========================================"
    log "Starting security configuration"
    [ "$STRICT_MODE" = "1" ] && log "Mode: STRICT"
    log "========================================"

    # In Moodle-Verzeichnis wechseln
    cd "$MOODLE_PATH"

    # Alle Sicherheitseinstellungen konfigurieren
    configure_password_policy
    configure_login_security
    configure_session_security
    configure_network_security
    configure_file_security
    configure_debug_settings
    configure_cron_security
    configure_email_security

    # Strict-Mode falls aktiviert
    configure_strict_mode

    # Cache leeren
    log "Purging caches..."
    sudo -u www-data php admin/cli/purge_caches.php 2>/dev/null || true

    # Einstellungen verifizieren
    verify_settings

    log "========================================"
    log "Security configuration completed"
    log "========================================"
}

# Script ausfuehren
main "$@"
