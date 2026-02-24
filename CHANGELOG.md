# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial release of Edulution Moodle
- Docker-based deployment with Moodle 5.0.2
- Keycloak SSO integration via OAuth2
- Automatic user sync from Keycloak to Moodle
- Group-to-course mapping (Klasse-*, Kurs-*, Fach-* patterns)
- Role mapping (teacher, student, admin)
- Reverse proxy support (Traefik)
- iframe embedding support for edulution.io integration
- Redis session support (optional)
- German language pack auto-installation
- Comprehensive environment variable configuration
- Local development setup with docker-compose.local.yml
- Test users and groups for local development

### Security
- Removed hardcoded credentials from PHP scripts
- Environment variable based configuration
- Docker secrets support for sensitive data
- Production-ready Keycloak realm template

## [1.0.0] - TBD

### Added
- First stable release

---

## Version History

- **Unreleased**: Current development version
- **1.0.0**: First stable release (planned)
