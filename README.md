# Edulution Moodle

Moodle Docker Image mit Keycloak-Synchronisation Plugin.

## Inhalt

- **Docker Image**: Moodle 5.1 mit vorinstalliertem Edulution Plugin
- **Plugin**: `local_edulution` - Keycloak User/Gruppen Synchronisation

## Schnellstart

### Docker

```bash
docker pull ghcr.io/edulution-io/edulution-moodle:latest
```

```yaml
# docker-compose.yml
services:
  moodle:
    image: ghcr.io/edulution-io/edulution-moodle:latest
    ports:
      - "8080:80"
    environment:
      - MOODLE_DATABASE_HOST=db
      - MOODLE_DATABASE_NAME=moodle
      - MOODLE_DATABASE_USER=moodle
      - MOODLE_DATABASE_PASSWORD=moodle
      - MOODLE_WWWROOT=http://localhost:8080
    volumes:
      - moodledata:/var/moodledata

  db:
    image: mariadb:10.11
    environment:
      - MYSQL_DATABASE=moodle
      - MYSQL_USER=moodle
      - MYSQL_PASSWORD=moodle
      - MYSQL_ROOT_PASSWORD=root

volumes:
  moodledata:
```

### Plugin (ohne Docker)

1. [Release herunterladen](https://github.com/edulution-io/edulution-moodle/releases)
2. Nach `moodle/local/edulution` entpacken
3. Moodle Upgrade ausführen

## Features

- Automatische User-Synchronisation von Keycloak
- Kurs-Erstellung aus Keycloak-Gruppen
- Automatische Einschreibungen
- Cookie Auth SSO für iFrame-Embedding
- Konfigurierbares Namensschema

## Konfiguration

Nach der Installation: **Site-Administration > Plugins > Edulution**

## Entwicklung

```bash
# Plugin als Volume mounten
docker-compose up -d
```

## Links

- [Dokumentation](https://docs.edulution.io/edulution-moodle)
- [Issues](https://github.com/edulution-io/edulution-moodle/issues)

## Lizenz

GPL v3
