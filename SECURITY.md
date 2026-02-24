# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x.x   | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

We take security seriously. If you discover a security vulnerability, please report it responsibly.

### How to Report

1. **Do NOT** create a public GitHub issue for security vulnerabilities
2. Email security concerns to: security@edulution.io
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Any suggested fixes

### What to Expect

- Acknowledgment within 48 hours
- Regular updates on progress
- Credit in security advisories (if desired)

### Scope

This security policy covers:

- The edulution-moodle Docker image
- Configuration scripts
- Sync scripts (keycloak-sync.php, etc.)
- Docker Compose configurations

Out of scope:
- Moodle core vulnerabilities (report to Moodle)
- Keycloak vulnerabilities (report to Keycloak)

## Security Best Practices

When deploying Edulution Moodle:

### Configuration

- Change all default passwords
- Use strong, unique secrets
- Enable SSL/TLS (sslRequired: "external" in Keycloak)
- Review environment variables before deployment

### Network

- Run behind a reverse proxy (Traefik, nginx)
- Use HTTPS for all connections
- Restrict database access to internal network
- Configure proper CORS and CSP headers

### Updates

- Keep Docker images updated
- Monitor Moodle security announcements
- Monitor Keycloak security announcements
- Apply security patches promptly

### Monitoring

- Enable logging
- Monitor sync logs for anomalies
- Set up alerts for failed authentication attempts
