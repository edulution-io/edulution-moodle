#!/bin/bash
# =====================================================
# EDULUTION MOODLE - INSTALL SCRIPT
# =====================================================
# Verwendung: curl -sSL https://raw.githubusercontent.com/edulution-io/edulution-moodle/dev/deployment/edulution/install.sh | bash
# =====================================================

set -e

echo "========================================"
echo "  Edulution Moodle - Installation"
echo "========================================"
echo ""

# Konfiguration
INSTALL_DIR="/srv/docker/edulution-moodle"
TRAEFIK_DIR="/srv/docker/edulution-ui/data/traefik/config"
EDULUTION_ENV="/srv/docker/edulution-ui/apps/api/.env"
REPO_URL="https://raw.githubusercontent.com/edulution-io/edulution-moodle/dev"

# Farben
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# Root-Check
if [ "$EUID" -ne 0 ]; then
    log_error "Bitte als root ausfuehren: sudo bash install.sh"
    exit 1
fi

# Edulution-Check
if [ ! -f "$EDULUTION_ENV" ]; then
    log_error "Edulution nicht gefunden: $EDULUTION_ENV"
    log_error "Bitte zuerst edulution installieren!"
    exit 1
fi
log_info "Edulution gefunden"

# 1. Traefik Config
log_info "Installiere Traefik-Konfiguration..."
if [ -d "$TRAEFIK_DIR" ]; then
    curl -sSL "$REPO_URL/deployment/edulution/traefik/moodle.yml" -o "$TRAEFIK_DIR/moodle.yml"
    log_info "Traefik-Config installiert: $TRAEFIK_DIR/moodle.yml"
else
    log_warn "Traefik-Verzeichnis nicht gefunden: $TRAEFIK_DIR"
    log_warn "Bitte manuell kopieren!"
fi

# 2. Moodle-Verzeichnis erstellen
log_info "Erstelle Moodle-Verzeichnis..."
mkdir -p "$INSTALL_DIR"
mkdir -p "$INSTALL_DIR/secrets"
mkdir -p "$INSTALL_DIR/moodledata"
mkdir -p "$INSTALL_DIR/mariadb"
mkdir -p "$INSTALL_DIR/redis"
mkdir -p "$INSTALL_DIR/logs"
cd "$INSTALL_DIR"

# 3. Secrets generieren (falls nicht vorhanden)
log_info "Generiere DB-Secrets..."
if [ ! -f "$INSTALL_DIR/secrets/db_password" ]; then
    openssl rand -base64 32 | tr -d '\n' > "$INSTALL_DIR/secrets/db_password"
    chmod 600 "$INSTALL_DIR/secrets/db_password"
    log_info "DB-Passwort generiert"
else
    log_warn "DB-Passwort existiert bereits"
fi

if [ ! -f "$INSTALL_DIR/secrets/db_root_password" ]; then
    openssl rand -base64 32 | tr -d '\n' > "$INSTALL_DIR/secrets/db_root_password"
    chmod 600 "$INSTALL_DIR/secrets/db_root_password"
    log_info "DB-Root-Passwort generiert"
else
    log_warn "DB-Root-Passwort existiert bereits"
fi

# 4. docker-compose.yml herunterladen
log_info "Lade docker-compose.yml..."
curl -sSL "$REPO_URL/deployment/edulution/docker-compose.yml" -o docker-compose.yml

echo ""
echo "========================================"
echo "  Installation abgeschlossen!"
echo "========================================"
echo ""
echo "Secrets werden automatisch von edulution gelesen:"
echo "  - EDULUTION_HOSTNAME"
echo "  - KEYCLOAK_EDU_MAILCOW_SYNC_SECRET"
echo ""
echo "Starten mit:"
echo "  cd $INSTALL_DIR"
echo "  docker compose up -d"
echo ""
echo "Logs pruefen:"
echo "  docker compose logs -f edulution-moodle"
echo ""
