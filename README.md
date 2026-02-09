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

---

## Quick Install (edulution)

```bash
curl -sSL https://raw.githubusercontent.com/edulution-io/edulution-moodle/dev/deployment/edulution/install.sh | sudo bash
```

Dann:
1. Keycloak Client erstellen (siehe unten)
2. `.env` anpassen
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
mkdir -p /srv/docker/edulution-moodle
cd /srv/docker/edulution-moodle

# docker-compose.yml
curl -sSL https://raw.githubusercontent.com/edulution-io/edulution-moodle/dev/deployment/edulution/docker-compose.yml -o docker-compose.yml

# .env
curl -sSL https://raw.githubusercontent.com/edulution-io/edulution-moodle/dev/deployment/edulution/.env.example -o .env
chmod 600 .env

# Anpassen
nano .env
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
   - Secret kopieren → in `.env` eintragen

4. **Service Account Roles:**
   - Client Roles → `realm-management`
   - Hinzufuegen: `view-users`, `query-users`, `view-groups`, `query-groups`

### 4. Starten

```bash
docker compose up -d
docker compose logs -f edulution-moodle
```

### 5. Zugriff

`https://deine-domain.de/moodle`

---

## Konfiguration (.env)

| Variable | Beschreibung | Beispiel |
|----------|--------------|----------|
| `MOODLE_URL` | Vollstaendige URL | `https://ui.domain.de/moodle` |
| `MOODLE_ADMIN_PASSWORD` | Admin-Passwort | `Sicher123!` |
| `MOODLE_DB_PASSWORD` | DB-Passwort | `DBSicher456!` |
| `KEYCLOAK_URL` | Keycloak URL | `https://ui.domain.de/auth/` |
| `KEYCLOAK_SECRET` | Client Secret | (aus Keycloak) |
| `DRY_RUN` | Test-Modus | `1` (erst testen!) |

---

## Sync

Der Sync läuft automatisch alle 5 Minuten (konfigurierbar).

```bash
# Sync-Logs
docker compose exec edulution-moodle tail -f /var/log/moodle-sync/sync_stdout.log

# Manueller Sync
docker compose exec edulution-moodle /opt/sync-venv/bin/python /opt/edulution-moodle-sync/sync.py --once
```

**Wichtig:** Erst mit `DRY_RUN=1` testen, dann auf `DRY_RUN=0` umstellen.

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
