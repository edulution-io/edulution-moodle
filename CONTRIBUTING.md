# Contributing to Edulution Moodle

Thank you for your interest in contributing to Edulution Moodle! This document provides guidelines for contributing.

## How to Contribute

### Reporting Issues

Before creating an issue, please:

1. Check existing issues to avoid duplicates
2. Use the issue templates when available
3. Provide as much detail as possible:
   - Steps to reproduce
   - Expected vs actual behavior
   - Environment details (Docker version, OS, etc.)
   - Relevant logs

### Pull Requests

1. Fork the repository
2. Create a feature branch from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. Make your changes
4. Test your changes locally:
   ```bash
   docker compose -f docker-compose.local.yml up -d
   ```
5. Commit with clear messages:
   ```bash
   git commit -m "Add: description of what you added"
   ```
6. Push and create a pull request

### Commit Message Format

Use these prefixes:
- `Add:` New features
- `Fix:` Bug fixes
- `Update:` Updates to existing features
- `Remove:` Removed features
- `Docs:` Documentation changes
- `Refactor:` Code refactoring

## Development Setup

### Prerequisites

- Docker and Docker Compose
- Git

### Local Development

```bash
# Clone the repository
git clone https://github.com/edulution-io/edulution-moodle.git
cd edulution-moodle

# Start local environment
docker compose -f docker-compose.local.yml up -d

# View logs
docker compose -f docker-compose.local.yml logs -f moodle
```

### Testing Changes

1. Access Moodle at http://localhost:8080/moodle-app/
2. Access Keycloak at http://localhost:8081/
3. Test SSO with provided test users

## Code Style

### PHP

- Follow PSR-12 coding standards
- Add PHPDoc comments to functions
- Use meaningful variable names
- Handle errors appropriately

### Shell Scripts

- Use `set -euo pipefail` at the start
- Quote variables: `"${VAR}"`
- Add comments for complex logic

### Docker

- Keep images small
- Use multi-stage builds when appropriate
- Document environment variables

## Security

- Never commit secrets or credentials
- Use environment variables for configuration
- Report security issues privately (see SECURITY.md)

## Questions?

Open a discussion or issue on GitHub.
