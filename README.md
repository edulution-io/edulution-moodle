# Edulution Moodle

[![Docker Build](https://github.com/edulution-io/edulution-moodle/actions/workflows/build-docker.yml/badge.svg)](https://github.com/edulution-io/edulution-moodle/actions/workflows/build-docker.yml)
[![Docker Image](https://img.shields.io/badge/docker-ghcr.io%2Fedulution--io%2Fedulution--moodle-blue)](https://github.com/edulution-io/edulution-moodle/pkgs/container/edulution-moodle)
[![Moodle Version](https://img.shields.io/badge/moodle-4.5-orange)](https://moodle.org)

Moodle LMS mit Keycloak-Integration und automatischer User-Synchronisierung fuer edulution.

## Features

- **Moodle 4.5** - Aktuelle LTS-Version
- **Keycloak SSO** - Single Sign-On Integration
- **Automatische Synchronisierung** - User aus Keycloak (wie edulution-mail)
- **Rollen-Mapping** - role-teacher, role-student, role-schooladministrator
- **Soft-Delete** - User werden erst suspendiert, dann geloescht
- **Auto-Secrets** - Passwoerter werden automatisch generiert

---

## Quick Install (edulution)

```bash
curl -sSL https://raw.githubusercontent.com/edulution-io/edulution-moodle/dev/deployment/edulution/install.sh | sudo bash
```

Das Script:
- Erstellt alle Verzeichnisse
- Generiert sichere Passwoerter automatisch
- Installiert Traefik-Konfiguration
- Laedt docker-compose.yml

Dann:
1. Keycloak Client erstellen (siehe unten)
2. `docker-compose.yml` anpassen (nur 2 Werte!)
3. `docker compose up -d`

---

## Manuelle Installation (edulution)

### 1. Traefik Config

```bash
curl -sSL https://raw.githubusercontent.com/edulution-io/edulution-moodle/dev/deployment/edulution/traefik/moodle.yml \
  -o /srv/docker/edulution-ui/data/traefik/config/moodle.yml
```

### 2. Moodle Setup

```bash
mkdir -p /srv/docker/edulution-moodle/{secrets,moodledata,mariadb,redis,logs}
cd /srv/docker/edulution-moodle

# Secrets generieren
openssl rand -base64 32 > secrets/db_password
openssl rand -base64 32 > secrets/db_root_password
openssl rand -base64 16 > secrets/admin_password
chmod 600 secrets/*

# docker-compose.yml
curl -sSL https://raw.githubusercontent.com/edulution-io/edulution-moodle/dev/deployment/edulution/docker-compose.yml -o docker-compose.yml

# Anpassen (nur 2 Werte!)
nano docker-compose.yml
```

### 3. Keycloak Client erstellen

In Keycloak Admin Console:

1. **Clients → Create:**
   - Client ID: `edu-moodle-sync`
   - Client Protocol: `openid-connect`

2. **Settings:**
   - Access Type: `confidential`
   - Service Accounts Enabled: `ON`

3. **Credentials Tab:**
   - Secret kopieren → in `docker-compose.yml` eintragen

4. **Service Account Roles:**
   - Client Roles → `realm-management`
   - Hinzufuegen: `view-users`, `query-users`, `view-groups`, `query-groups`

### 4. docker-compose.yml anpassen

Nur 2 Werte muessen geaendert werden:

```yaml
environment:
  - MOODLE_HOSTNAME=ui.DEINE-DOMAIN.de        # <-- Anpassen
  - KEYCLOAK_SECRET_KEY=DEIN_KEYCLOAK_SECRET  # <-- Anpassen
```

### 5. Starten

```bash
docker compose up -d
docker compose logs -f edulution-moodle
```

### 6. Zugriff

`https://deine-domain.de/moodle`

---

## Konfiguration

Die Konfiguration ist minimal - nur 2 Werte in `docker-compose.yml`:

| Variable | Beschreibung | Beispiel |
|----------|--------------|----------|
| `MOODLE_HOSTNAME` | Domain ohne https:// | `ui.73.dev.multi.schule` |
| `KEYCLOAK_SECRET_KEY` | Client Secret | (aus Keycloak) |

Passwoerter werden automatisch generiert in `/srv/docker/edulution-moodle/secrets/`

---

## Sync

Der Sync läuft automatisch alle 5 Minuten.

```bash
# Sync-Logs
docker compose logs -f edulution-moodle | grep sync

# Manueller Sync
docker compose exec edulution-moodle /opt/sync-venv/bin/python /opt/edulution-moodle-sync/sync.py --once
```

---

## Troubleshooting

```bash
# Container-Status
docker compose ps

# Logs
docker compose logs -f edulution-moodle

# In Container
docker compose exec edulution-moodle bash

# Moosh-Befehle
docker compose exec edulution-moodle moosh -n user-list
```

---

## Links

- **Dokumentation:** https://docs.edulution.io/docs/edulution-moodle
- **GitHub Issues:** https://github.com/edulution-io/edulution-moodle/issues
