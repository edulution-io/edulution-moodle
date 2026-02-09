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
- **Zero-Config** - Liest Secrets automatisch von edulution

---

## Quick Install (edulution)

```bash
curl -sSL https://raw.githubusercontent.com/edulution-io/edulution-moodle/dev/deployment/edulution/install.sh | sudo bash
cd /srv/docker/edulution-moodle
docker compose up -d
```

Fertig! Keine manuelle Konfiguration noetig - Secrets werden automatisch von edulution gelesen.

---

## Was passiert automatisch?

- **Hostname** - Wird aus `EDULUTION_HOSTNAME` gelesen
- **Keycloak Secret** - Wird aus `KEYCLOAK_EDU_MAILCOW_SYNC_SECRET` gelesen (gleicher Client wie edulution-mail)
- **DB Passwoerter** - Werden automatisch generiert
- **Traefik Config** - Wird automatisch installiert

---

## Zugriff

`https://deine-domain.de/moodle`

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
