#!/bin/bash
# =====================================================
# EDULUTION MOODLE - INSTALL SCRIPT
# =====================================================
# Verwendung: curl -sSL "https://raw.githubusercontent.com/edulution-io/edulution-moodle/main/deployment/edulution/install.sh" | sudo bash
# =====================================================

set -e

echo ""
echo "========================================"
echo "  Edulution Moodle - Installation"
echo "========================================"
echo ""

# Konfiguration
INSTALL_DIR="/srv/docker/edulution-moodle"
TRAEFIK_DIR="/srv/docker/edulution-ui/data/traefik/config"
EDULUTION_ENV="/srv/docker/edulution-ui/apps/api/.env"
REPO_URL="https://raw.githubusercontent.com/edulution-io/edulution-moodle/main"

# Farben
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }
log_step() { echo -e "${BLUE}[STEP]${NC} $1"; }

# Root-Check
if [ "$EUID" -ne 0 ]; then
    log_error "Bitte als root ausfuehren: sudo bash"
    exit 1
fi

# Edulution-Check
log_step "Pruefe Edulution-Installation..."
if [ ! -f "$EDULUTION_ENV" ]; then
    log_error "Edulution nicht gefunden: $EDULUTION_ENV"
    log_error "Bitte zuerst edulution installieren!"
    exit 1
fi
log_info "Edulution gefunden"

# 1. Moodle-Verzeichnis erstellen
log_step "Erstelle Moodle-Verzeichnis..."
mkdir -p "$INSTALL_DIR"
mkdir -p "$INSTALL_DIR/secrets"
mkdir -p "$INSTALL_DIR/moodledata"
mkdir -p "$INSTALL_DIR/mariadb"
mkdir -p "$INSTALL_DIR/redis"
mkdir -p "$INSTALL_DIR/logs"
cd "$INSTALL_DIR"

# 2. Secrets generieren (falls nicht vorhanden)
log_step "Generiere Secrets..."
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

if [ ! -f "$INSTALL_DIR/secrets/admin_password" ]; then
    openssl rand -base64 16 | tr -d '\n' > "$INSTALL_DIR/secrets/admin_password"
    chmod 600 "$INSTALL_DIR/secrets/admin_password"
    log_info "Admin-Passwort generiert"
else
    log_warn "Admin-Passwort existiert bereits"
fi

# 3. docker-compose.yml herunterladen
log_step "Lade docker-compose.yml..."
curl -sSL "$REPO_URL/deployment/edulution/docker-compose.yml" -o docker-compose.yml
log_info "docker-compose.yml heruntergeladen"

# 4. Traefik Config
log_step "Installiere Traefik-Konfiguration..."
if [ -d "$TRAEFIK_DIR" ]; then
    curl -sSL "$REPO_URL/deployment/edulution/traefik/moodle.yml" -o "$TRAEFIK_DIR/moodle.yml"
    log_info "Traefik-Config installiert: $TRAEFIK_DIR/moodle.yml"
else
    log_warn "Traefik-Verzeichnis nicht gefunden: $TRAEFIK_DIR"
    log_warn "Versuche Docker-Variante..."
    curl -sSL "$REPO_URL/deployment/edulution/traefik/moodle.yml" | docker exec -i edulution-traefik tee /etc/traefik/dynamic/moodle.yml > /dev/null 2>&1 && \
        log_info "Traefik-Config via Docker installiert" || \
        log_warn "Bitte Traefik-Config manuell kopieren!"
fi

# 5. Docker Image pullen
log_step "Lade Docker Image..."
docker compose pull

echo ""
echo "========================================"
log_info "Installation abgeschlossen!"
echo "========================================"
echo ""
echo "Konfiguration:"
echo "  - Install-Dir: $INSTALL_DIR"
echo "  - Moodle-Pfad: /moodle-app"
echo "  - DB-Passwort: $INSTALL_DIR/secrets/db_password"
echo "  - Admin-Passwort: $INSTALL_DIR/secrets/admin_password"
echo ""
echo "Starten mit:"
echo "  cd $INSTALL_DIR"
echo "  docker compose up -d"
echo ""
echo "Logs pruefen:"
echo "  docker compose logs -f edulution-moodle"
echo ""
echo "URL: https://\$(grep EDULUTION_HOSTNAME $EDULUTION_ENV | cut -d= -f2)/moodle-app"
echo ""
