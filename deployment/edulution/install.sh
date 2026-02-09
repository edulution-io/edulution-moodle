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
cd "$INSTALL_DIR"

# 3. Dateien herunterladen
log_info "Lade docker-compose.yml..."
curl -sSL "$REPO_URL/deployment/edulution/docker-compose.yml" -o docker-compose.yml

log_info "Lade .env.example..."
curl -sSL "$REPO_URL/deployment/edulution/.env.example" -o .env.example

# 4. .env erstellen falls nicht vorhanden
if [ ! -f ".env" ]; then
    cp .env.example .env
    chmod 600 .env
    log_info ".env erstellt aus .env.example"
else
    log_warn ".env existiert bereits, wird nicht ueberschrieben"
fi

echo ""
echo "========================================"
echo "  Installation abgeschlossen!"
echo "========================================"
echo ""
echo "Naechste Schritte:"
echo ""
echo "1. Keycloak Client erstellen:"
echo "   - Client ID: edu-moodle-sync"
echo "   - Access Type: confidential"
echo "   - Service Accounts Enabled: ON"
echo "   - Service Account Roles: view-users, query-users, view-groups, query-groups"
echo ""
echo "2. Konfiguration anpassen:"
echo "   cd $INSTALL_DIR"
echo "   nano .env"
echo ""
echo "3. Starten:"
echo "   docker compose up -d"
echo ""
echo "4. Logs pruefen:"
echo "   docker compose logs -f edulution-moodle"
echo ""
