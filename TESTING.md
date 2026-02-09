# Edulution Moodle - Test Guide

## Schnellstart (Lokal testen)

### 1. Konfiguration vorbereiten

```bash
# Test-Konfiguration kopieren
cp .env.test .env
```

### 2. Container starten

```bash
# Mit dem offiziellen Image (empfohlen)
docker compose -f docker-compose.test.yml up -d

# Oder lokal bauen und starten
docker compose -f docker-compose.test.yml up -d --build
```

### 3. Logs beobachten

```bash
docker compose -f docker-compose.test.yml logs -f moodle
```

Warte auf die Meldung:
```
Edulution Moodle - Ready!
URL: http://localhost:8080
```

### 4. Moodle oeffnen

Oeffne im Browser: http://localhost:8080

**Login:**
- Benutzer: `admin`
- Passwort: `Admin123!`

## Mit Keycloak testen

### 1. Keycloak-Daten eintragen

Bearbeite `.env`:

```bash
# Keycloak Server URL
KEYCLOAK_SERVER_URL=https://sso.deine-domain.de

# Realm Name
KEYCLOAK_REALM=edulution

# Client ID (Service Account)
KEYCLOAK_CLIENT_ID=edu-moodle-sync

# Client Secret (aus Keycloak kopieren)
KEYCLOAK_SECRET_KEY=dein-client-secret

# Fuer lokale Tests SSL deaktivieren
KEYCLOAK_VERIFY_SSL=0
```

### 2. Sync aktivieren (erst im Dry-Run!)

```bash
# Sync aktivieren
SYNC_ENABLED=1

# WICHTIG: Erst Dry-Run zum Testen!
DRY_RUN=1
```

### 3. Container neustarten

```bash
docker compose -f docker-compose.test.yml restart moodle
```

### 4. Sync-Logs pruefen

```bash
# Sync-Logs anzeigen
docker compose -f docker-compose.test.yml exec moodle cat /var/log/moodle-sync/sync_stdout.log

# Oder live verfolgen
docker compose -f docker-compose.test.yml exec moodle tail -f /var/log/moodle-sync/sync_stdout.log
```

## Container-Befehle

```bash
# Status pruefen
docker compose -f docker-compose.test.yml ps

# In Container einloggen
docker compose -f docker-compose.test.yml exec moodle bash

# Moodle CLI nutzen
docker compose -f docker-compose.test.yml exec moodle php /var/www/html/moodle/admin/cli/cron.php

# Moosh nutzen
docker compose -f docker-compose.test.yml exec moodle moosh -n user-list

# Sync manuell starten
docker compose -f docker-compose.test.yml exec moodle /opt/sync-venv/bin/python /opt/edulution-moodle-sync/sync.py --once

# Container stoppen und Daten loeschen
docker compose -f docker-compose.test.yml down -v
```

## Troubleshooting

### Database connection refused

```bash
# Datenbank-Logs pruefen
docker compose -f docker-compose.test.yml logs mariadb

# Datenbank-Container neustarten
docker compose -f docker-compose.test.yml restart mariadb
```

### Sync startet nicht

1. Pruefe ob SYNC_ENABLED=1 gesetzt ist
2. Pruefe ob KEYCLOAK_SECRET_KEY gesetzt ist
3. Pruefe Supervisor-Status:

```bash
docker compose -f docker-compose.test.yml exec moodle supervisorctl status
```

### Keycloak-Verbindung fehlgeschlagen

```bash
# Logs pruefen
docker compose -f docker-compose.test.yml exec moodle cat /var/log/moodle-sync/sync_stderr.log

# Manuellen Test ausfuehren
docker compose -f docker-compose.test.yml exec moodle /opt/sync-venv/bin/python -c "
from modules.keycloak.keycloak import KeycloakClient
import os
kc = KeycloakClient()
print('Connection successful!')
print(f'Found {len(kc.get_users(max_count=5))} users')
"
```

## Image lokal bauen

```bash
# Standard-Build
docker build -t edulution-moodle:local -f build/Dockerfile .

# Mit spezifischer Moodle-Version
docker build -t edulution-moodle:local \
  --build-arg MOODLE_VERSION=MOODLE_404_STABLE \
  -f build/Dockerfile .

# Multi-Arch Build (AMD64 + ARM64)
docker buildx build \
  --platform linux/amd64,linux/arm64 \
  -t edulution-moodle:local \
  -f build/Dockerfile .
```

## GitHub Actions

Der GitHub Workflow baut automatisch bei:
- Push auf `main` Branch
- Release erstellen
- Manuell via "Run workflow"

Images werden gepusht nach:
- `ghcr.io/edulution-io/edulution-moodle:latest`
- `ghcr.io/edulution-io/edulution-moodle:v1.0.0` (bei Releases)
- `ghcr.io/edulution-io/edulution-moodle:sha-abc123` (Commit-SHA)
