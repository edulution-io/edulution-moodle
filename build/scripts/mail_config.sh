#!/bin/bash
# =============================================================================
# Edulution Moodle - Mail Configuration
# =============================================================================
# SMTP-Konfiguration via moosh
#
# Usage: mail_config.sh [--test]
# =============================================================================

set -e

# =============================================================================
# CONFIGURATION
# =============================================================================

MOODLE_PATH="/var/www/html/moodle"

# Test-Modus
TEST_MODE=0
[ "$1" = "--test" ] && TEST_MODE=1

# =============================================================================
# LOGGING
# =============================================================================

LOG_FILE="/var/log/moodle-maintenance/mail.log"
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
            log "Set $name (plugin: $plugin)" || \
            log_error "Failed to set $name"
    else
        moosh -n config-set "$name" "$value" 2>/dev/null && \
            log "Set $name" || \
            log_error "Failed to set $name"
    fi
}

# =============================================================================
# SMTP CONFIGURATION
# =============================================================================

configure_smtp() {
    log "Configuring SMTP settings..."

    # Pruefen ob SMTP_HOST gesetzt ist
    if [ -z "${SMTP_HOST}" ]; then
        log_warning "SMTP_HOST not set, using PHP mail() fallback"
        set_config "smtphosts" ""
        return
    fi

    # SMTP-Server konfigurieren
    local smtp_port="${SMTP_PORT:-587}"
    set_config "smtphosts" "${SMTP_HOST}:${smtp_port}"

    # Authentifizierung
    if [ -n "${SMTP_USER}" ]; then
        set_config "smtpuser" "${SMTP_USER}"

        if [ -n "${SMTP_PASSWORD}" ]; then
            set_config "smtppass" "${SMTP_PASSWORD}"
        elif [ -n "${SMTP_PASSWORD_FILE}" ] && [ -f "${SMTP_PASSWORD_FILE}" ]; then
            local smtp_pass=$(cat "${SMTP_PASSWORD_FILE}")
            set_config "smtppass" "$smtp_pass"
        fi
    fi

    # Verschluesselung (tls, ssl, oder leer fuer keine)
    local smtp_security="${SMTP_SECURITY:-tls}"
    case "$smtp_security" in
        tls|TLS)
            set_config "smtpsecure" "tls"
            log "SMTP security: TLS (STARTTLS)"
            ;;
        ssl|SSL)
            set_config "smtpsecure" "ssl"
            log "SMTP security: SSL"
            ;;
        none|"")
            set_config "smtpsecure" ""
            log_warning "SMTP security: NONE (not recommended)"
            ;;
        *)
            set_config "smtpsecure" "tls"
            log_warning "Unknown SMTP_SECURITY '$smtp_security', defaulting to TLS"
            ;;
    esac

    # Authentifizierungstyp (LOGIN, PLAIN, NTLM, CRAM-MD5)
    local auth_type="${SMTP_AUTH_TYPE:-LOGIN}"
    set_config "smtpauthtype" "$auth_type"

    # SMTP Max-Recipients pro Nachricht
    set_config "smtpmaxbulk" "${SMTP_MAX_BULK:-100}"

    log "SMTP settings configured"
}

# =============================================================================
# SENDER CONFIGURATION
# =============================================================================

configure_sender() {
    log "Configuring sender addresses..."

    # No-Reply Adresse
    local noreply="${SMTP_NOREPLY:-noreply@${MOODLE_DOMAIN:-example.com}}"
    set_config "noreplyaddress" "$noreply"

    # Support-E-Mail
    local support="${SMTP_SUPPORT:-support@${MOODLE_DOMAIN:-example.com}}"
    set_config "supportemail" "$support"

    # Absendername
    set_config "supportname" "${SMTP_SUPPORT_NAME:-Moodle Support}"

    # Admin-E-Mail fuer Systemnachrichten
    if [ -n "${ADMIN_EMAIL}" ]; then
        set_config "supportemail" "${ADMIN_EMAIL}"
    fi

    log "Sender addresses configured"
}

# =============================================================================
# MAIL SETTINGS
# =============================================================================

configure_mail_settings() {
    log "Configuring mail settings..."

    # E-Mail-Betreff-Prefix
    set_config "emailsubjectprefix" "${SMTP_SUBJECT_PREFIX:-[Moodle]}"

    # Erlaubte E-Mail-Domains (leer = alle)
    set_config "allowedemaildomains" "${SMTP_ALLOWED_DOMAINS:-}"

    # Divert-Modus (alle Mails an eine Adresse umleiten - fuer Testing)
    if [ -n "${SMTP_DIVERT_TO}" ]; then
        set_config "divertallemailsto" "${SMTP_DIVERT_TO}"
        log_warning "Email divert mode: All emails will be sent to ${SMTP_DIVERT_TO}"
    else
        set_config "divertallemailsto" ""
    fi

    # Divert-Exclude (Domains die nicht umgeleitet werden)
    if [ -n "${SMTP_DIVERT_EXCLUDE}" ]; then
        set_config "divertallemailsexcept" "${SMTP_DIVERT_EXCLUDE}"
    fi

    # HTML-Mails erlauben
    set_config "allowusermailcharset" "${SMTP_ALLOW_USER_CHARSET:-0}"

    # Mail-Charset
    set_config "sitemailcharset" "${SMTP_CHARSET:-UTF-8}"

    log "Mail settings configured"
}

# =============================================================================
# BOUNCE HANDLING
# =============================================================================

configure_bounce_handling() {
    log "Configuring bounce handling..."

    # Bounce-Handling aktivieren
    set_config "handlebounces" "${SMTP_HANDLE_BOUNCES:-0}"

    if [ "${SMTP_HANDLE_BOUNCES:-0}" = "1" ]; then
        # Bounce-Ratio (bei Ueberschreitung wird User gesperrt)
        set_config "bounceratio" "${SMTP_BOUNCE_RATIO:-0.2}"

        # Mailbox fuer Bounces
        if [ -n "${SMTP_BOUNCE_MAILBOX}" ]; then
            set_config "maaborunceaddress" "${SMTP_BOUNCE_MAILBOX}"
        fi

        log "Bounce handling enabled"
    else
        log "Bounce handling disabled"
    fi
}

# =============================================================================
# NOTIFICATION SETTINGS
# =============================================================================

configure_notifications() {
    log "Configuring notification settings..."

    # Message-Provider-Einstellungen
    # (Standard-Einstellungen werden verwendet)

    # Email-Digest aktivieren
    set_config "emaildefaultheader" "${SMTP_DEFAULT_HEADER:-}"
    set_config "emaildefaultfooter" "${SMTP_DEFAULT_FOOTER:-}"

    log "Notification settings configured"
}

# =============================================================================
# TEST MAIL
# =============================================================================

send_test_mail() {
    log "Sending test email..."

    local test_recipient="${TEST_EMAIL:-${ADMIN_EMAIL:-}}"

    if [ -z "$test_recipient" ]; then
        log_error "No test recipient defined (set TEST_EMAIL or ADMIN_EMAIL)"
        return 1
    fi

    cd "$MOODLE_PATH"

    # Test-Mail via Moodle CLI senden
    sudo -u www-data php admin/cli/adhoc_task.php --execute="\\core\\task\\send_email_task" 2>/dev/null || \
        log_warning "Could not execute email task"

    # Alternativ: PHP-Script fuer Testmail
    sudo -u www-data php -r "
        define('CLI_SCRIPT', true);
        require('config.php');
        require_once(\$CFG->libdir.'/moodlelib.php');

        \$testuser = new stdClass();
        \$testuser->email = '$test_recipient';
        \$testuser->firstname = 'Test';
        \$testuser->lastname = 'User';
        \$testuser->maildisplay = true;
        \$testuser->mailformat = 1;
        \$testuser->id = 1;

        \$result = email_to_user(
            \$testuser,
            get_admin(),
            'Moodle SMTP Test',
            'This is a test email from Moodle to verify SMTP configuration.',
            '<h1>SMTP Test</h1><p>This is a test email from Moodle to verify SMTP configuration.</p>'
        );

        echo \$result ? 'Email sent successfully' : 'Email sending failed';
        echo PHP_EOL;
    " 2>&1

    log "Test email sent to $test_recipient"
}

# =============================================================================
# VERIFICATION
# =============================================================================

verify_config() {
    log "Verifying mail configuration..."

    cd "$MOODLE_PATH"

    # SMTP-Host pruefen
    local smtp_host=$(moosh -n config-get core smtphosts 2>/dev/null || echo "")

    if [ -z "$smtp_host" ]; then
        log_warning "SMTP not configured, using PHP mail()"
    else
        log "SMTP Host: $smtp_host"

        # Verbindung testen
        local host=$(echo "$smtp_host" | cut -d':' -f1)
        local port=$(echo "$smtp_host" | cut -d':' -f2)

        if timeout 5 bash -c "echo > /dev/tcp/$host/$port" 2>/dev/null; then
            log "SMTP connection test: SUCCESS"
        else
            log_error "SMTP connection test: FAILED (cannot connect to $host:$port)"
        fi
    fi

    # No-Reply-Adresse pruefen
    local noreply=$(moosh -n config-get core noreplyaddress 2>/dev/null || echo "")
    log "No-Reply Address: $noreply"

    log "Mail configuration verification completed"
}

# =============================================================================
# MAIN
# =============================================================================

main() {
    log "========================================"
    log "Starting mail configuration"
    log "========================================"

    # In Moodle-Verzeichnis wechseln
    cd "$MOODLE_PATH"

    # SMTP konfigurieren
    configure_smtp

    # Absender konfigurieren
    configure_sender

    # Mail-Einstellungen
    configure_mail_settings

    # Bounce-Handling
    configure_bounce_handling

    # Benachrichtigungen
    configure_notifications

    # Cache leeren
    log "Purging caches..."
    sudo -u www-data php admin/cli/purge_caches.php 2>/dev/null || true

    # Konfiguration verifizieren
    verify_config

    # Test-Mail senden falls angefordert
    if [ "$TEST_MODE" = "1" ]; then
        send_test_mail
    fi

    log "========================================"
    log "Mail configuration completed"
    log "========================================"
}

# Script ausfuehren
main "$@"
