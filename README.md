# Edulution Moodle

[![Docker Build](https://github.com/edulution-io/edulution-moodle/actions/workflows/docker-build.yml/badge.svg)](https://github.com/edulution-io/edulution-moodle/actions/workflows/docker-build.yml)
[![Docker Image](https://img.shields.io/badge/docker-ghcr.io%2Fedulution--io%2Fedulution--moodle-blue)](https://github.com/edulution-io/edulution-moodle/pkgs/container/edulution-moodle)
[![Moodle Version](https://img.shields.io/badge/moodle-4.5-orange)](https://moodle.org)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Moodle Learning Management System mit Keycloak-Integration und automatischer User-Synchronisierung fuer edulution und Standalone-Betrieb.

## Features

- **Moodle 4.5** - Aktuelle LTS-Version
- **Keycloak SSO** - Single Sign-On Integration
- **Automatische Synchronisierung** - User und Kurse aus Keycloak (wie edulution-mail)
- **Rollen-Mapping** - role-teacher, role-student, role-schooladministrator
- **Soft-Delete** - User werden erst suspendiert, dann geloescht
- **Redis Cache** - Optimierte Performance
- **Moosh CLI** - Moodle-Administration per Kommandozeile
- **Multi-Arch** - AMD64 und ARM64 Support

---

## Quick Start

### 1. Repository klonen

```bash
git clone https://github.com/edulution-io/edulution-moodle.git
cd edulution-moodle
```

### 2. Konfiguration erstellen

```bash
cp .env.example .env
nano .env
```

Mindestens diese Werte setzen:
- `MOODLE_ADMIN_PASSWORD` - Admin-Passwort
- `DB_PASSWORD` - Datenbank-Passwort
- `DB_ROOT_PASSWORD` - Root-Passwort

### 3. Starten

```bash
# Standard (ohne SSL)
docker compose up -d

# Mit Traefik/SSL
docker compose --profile ssl up -d

# Logs beobachten
docker compose logs -f moodle
```

### 4. Zugriff

- **URL:** http://localhost:8080
- **Admin:** admin / (dein Passwort)

---

## Schnelltest

Fuer einen schnellen lokalen Test:

```bash
cp .env.test .env
docker compose -f docker-compose.test.yml up -d
```

Siehe [TESTING.md](TESTING.md) fuer Details.

---

## Konfiguration

### Umgebungsvariablen

#### Moodle

| Variable | Beschreibung | Standard |
|----------|--------------|----------|
| `MOODLE_WWWROOT` | Vollstaendige URL | `http://localhost:8080` |
| `MOODLE_SITE_NAME` | Name der Instanz | `Moodle` |
| `MOODLE_ADMIN_USER` | Admin-Benutzername | `admin` |
| `MOODLE_ADMIN_PASSWORD` | Admin-Passwort | **Pflicht** |
| `MOODLE_ADMIN_EMAIL` | Admin-E-Mail | `admin@example.com` |
| `MOODLE_DEFAULT_LANGUAGE` | Sprache | `de` |

#### Datenbank

| Variable | Beschreibung | Standard |
|----------|--------------|----------|
| `DB_NAME` | Datenbankname | `moodle` |
| `DB_USER` | DB-Benutzer | `moodle` |
| `DB_PASSWORD` | DB-Passwort | **Pflicht** |
| `DB_ROOT_PASSWORD` | Root-Passwort | **Pflicht** |

#### Keycloak

| Variable | Beschreibung | Standard |
|----------|--------------|----------|
| `KEYCLOAK_SERVER_URL` | Keycloak URL | - |
| `KEYCLOAK_REALM` | Realm Name | `edulution` |
| `KEYCLOAK_CLIENT_ID` | Client ID | `edu-moodle-sync` |
| `KEYCLOAK_SECRET_KEY` | Client Secret | **Pflicht fuer Sync** |
| `KEYCLOAK_VERIFY_SSL` | SSL pruefen | `1` |

#### Synchronisierung

| Variable | Beschreibung | Standard |
|----------|--------------|----------|
| `SYNC_ENABLED` | Sync aktivieren | `0` |
| `SYNC_INTERVAL` | Intervall (Sekunden) | `300` |
| `DRY_RUN` | Testlauf | `0` |
| `SYNC_USERS_IN_GROUPS` | Gruppen synchronisieren | `role-schooladministrator,role-teacher,role-student` |

#### Rollen-Mapping

| Variable | Keycloak-Gruppe | Moodle-Rolle |
|----------|-----------------|--------------|
| `ROLE_STUDENT_GROUPS` | `role-student` | `student` |
| `ROLE_TEACHER_GROUPS` | `role-teacher` | `editingteacher` |
| `ROLE_MANAGER_GROUPS` | `role-schooladministrator` | `manager` |

#### Soft-Delete

| Variable | Beschreibung | Standard |
|----------|--------------|----------|
| `SOFT_DELETE_ENABLED` | Erst suspendieren | `1` |
| `SOFT_DELETE_GRACE_PERIOD` | Wartezeit (Sekunden) | `2592000` (30 Tage) |
| `DELETE_ENABLED` | Endgueltig loeschen | `0` |

---

## Synchronisierung

Die Sync-Komponente arbeitet wie `edulution-mail`:

1. **Keycloak-Verbindung** mit python-keycloak
2. **Pagination** mit 50 Eintraegen pro Seite
3. **Retry-Logic** mit 6 Versuchen und exponential backoff
4. **Hash-basierte Aenderungserkennung**

### Manueller Sync

```bash
# Sync-Status pruefen
docker compose exec moodle supervisorctl status moodle-sync

# Sync-Logs anzeigen
docker compose exec moodle tail -f /var/log/moodle-sync/sync_stdout.log

# Manueller Sync (einmalig)
docker compose exec moodle /opt/sync-venv/bin/python /opt/edulution-moodle-sync/sync.py --once
```

### Sync mit Dry-Run testen

```bash
# In .env setzen:
SYNC_ENABLED=1
DRY_RUN=1

# Container neustarten
docker compose restart moodle

# Logs pruefen
docker compose exec moodle tail -f /var/log/moodle-sync/sync_stdout.log
```

---

## Verzeichnisstruktur

```
edulution-moodle/
├── build/
│   ├── Dockerfile                    # Container-Build
│   ├── entrypoint.sh                 # Startup-Script
│   ├── edulution-moodle-sync/        # Sync-Modul (Python)
│   │   ├── sync.py                   # Haupt-Sync-Logik
│   │   ├── modules/
│   │   │   ├── keycloak/keycloak.py  # Keycloak-Client
│   │   │   ├── moodle/moosh.py       # Moosh-Wrapper
│   │   │   ├── models/               # Datenmodelle
│   │   │   └── database/             # Deactivation-Tracker
│   │   └── requirements.txt
│   ├── admin-ui/                     # Admin-Oberflaeche
│   └── scripts/                      # Wartungs-Skripte
│
├── config/
│   └── plugins.json                  # Plugin-Konfiguration
│
├── deployment/                       # Fuer edulution-Integration
│   ├── traefik/
│   └── compose/
│
├── traefik/                          # Standalone Traefik-Config
│
├── docker-compose.yml                # Produktiv-Compose
├── docker-compose.test.yml           # Test-Compose
├── .env.example                      # Beispiel-Konfiguration
├── .env.test                         # Test-Konfiguration
└── TESTING.md                        # Test-Anleitung
```

---

## Container bauen

### Lokal

```bash
# Standard-Build
docker build -t edulution-moodle:local -f build/Dockerfile .

# Mit spezifischer Moodle-Version
docker build -t edulution-moodle:local \
  --build-arg MOODLE_VERSION=MOODLE_404_STABLE \
  -f build/Dockerfile .
```

### GitHub Actions

Der Workflow baut automatisch bei:
- Push auf `main` Branch
- Release erstellen
- Manuell via "Run workflow"

Images:
- `ghcr.io/edulution-io/edulution-moodle:latest`
- `ghcr.io/edulution-io/edulution-moodle:v1.0.0` (Releases)
- `ghcr.io/edulution-io/edulution-moodle:sha-abc123` (Commits)

---

## Troubleshooting

### Container startet nicht

```bash
docker compose logs moodle
docker compose ps
```

### Datenbank-Fehler

```bash
docker compose logs mariadb
docker compose exec moodle-db mysql -u root -p -e "SHOW DATABASES;"
```

### Sync funktioniert nicht

```bash
# Sync-Logs pruefen
docker compose exec moodle cat /var/log/moodle-sync/sync_stderr.log

# Keycloak-Verbindung testen
docker compose exec moodle /opt/sync-venv/bin/python -c "
from modules.keycloak.keycloak import KeycloakClient
kc = KeycloakClient()
print('Users:', len(kc.get_users(max_count=5)))
"
```

### Moosh-Befehle

```bash
# User auflisten
docker compose exec moodle moosh -n user-list

# Cache leeren
docker compose exec moodle moosh -n cache-clear

# Moodle-Version
docker compose exec moodle moosh -n info
```

---

## Lizenz

MIT License - siehe [LICENSE](LICENSE)

---

## Links

- **Dokumentation:** https://docs.edulution.io/docs/edulution-moodle
- **GitHub Issues:** https://github.com/edulution-io/edulution-moodle/issues
- **edulution.io:** https://edulution.io
