"""
Configuration Storage - Laedt und merged alle Konfigurationsquellen

Prioritaet:
1. Defaults (niedrigste)
2. Environment-Variablen
3. Override-Datei (hoechste)
"""

import os
import json
import logging
from typing import Any, Dict, List, Optional

logger = logging.getLogger(__name__)


class ConfigurationStorage:
    """
    Zentrale Konfigurationsverwaltung fuer den Moodle-Sync

    Laedt Konfiguration aus:
    - Interne Defaults
    - Environment-Variablen
    - Override-Datei (JSON)
    """

    def __init__(self, override_file: str = None):
        """
        Initialisiert die Konfiguration

        Args:
            override_file: Pfad zur Override-Datei (optional, default aus ENV)
        """
        self.config: Dict[str, Any] = {}
        self.override_file = override_file or os.getenv(
            "CONFIG_OVERRIDE_FILE",
            "/srv/data/moodle.override.config"
        )

        self._load_defaults()
        self._load_env()
        self._load_override_file()
        self._validate()

        logger.debug(f"Configuration loaded with {len(self.config)} settings")

    def _validate(self):
        """Validiert erforderliche Konfiguration"""
        # Pruefe Keycloak-Konfiguration
        if not self.config.get("KEYCLOAK_SECRET_KEY"):
            logger.warning("!!! WARNING !!!")
            logger.warning("KEYCLOAK_SECRET_KEY is not set! Sync will not work!")
            logger.warning("Please set KEYCLOAK_SECRET_KEY environment variable.")

        if not self.config.get("KEYCLOAK_SERVER_URL") or \
           self.config.get("KEYCLOAK_SERVER_URL") == "https://keycloak.example.com/auth/":
            logger.warning("KEYCLOAK_SERVER_URL not configured - using default example URL")

    def _load_defaults(self):
        """Laedt Default-Werte"""
        self.config = {
            # Sync General (wie edulution-mail)
            "SYNC_INTERVAL": 300,
            "RETRY_INTERVAL": 60,  # Wird automatisch berechnet wenn nicht gesetzt
            "DRY_RUN": False,
            "LOG_LEVEL": "INFO",
            "DISABLE_SYNC_FILE": "/srv/data/DISABLE_SYNC",

            # User Sync Options
            "SYNC_ALL_USERS": False,
            "SYNC_USERS_IN_GROUPS": ["role-teacher", "role-student", "role-schooladministrator"],
            "SYNC_USERS_WITH_ATTRIBUTE": "",
            "SYNC_USERS_ATTRIBUTE_VALUE": [],

            # Course Sync Options
            "SYNC_ALL_GROUPS_AS_COURSES": False,
            "COURSE_SYNC_ATTRIBUTE": "moodleCourse",
            "COURSE_SYNC_GROUP_PATTERN": "",
            "COURSE_SYNC_PARENT_GROUP": "",
            "COURSE_SHORTNAME_ATTRIBUTE": "courseShortname",
            "DEFAULT_COURSE_CATEGORY": 1,

            # Role Mappings
            "ROLE_MAPPING_MODE": "group",  # group, attribute, realm_role
            "ROLE_MAPPING_ATTRIBUTE": "sophomorixRole",
            "ROLE_STUDENT_GROUPS": ["role-student"],
            "ROLE_TEACHER_GROUPS": ["role-teacher"],
            "ROLE_ASSISTANT_GROUPS": [],
            "ROLE_MANAGER_GROUPS": ["role-schooladministrator"],
            "ROLE_STUDENT_MOODLE": "student",
            "ROLE_TEACHER_MOODLE": "editingteacher",
            "ROLE_ASSISTANT_MOODLE": "teacher",
            "ROLE_MANAGER_MOODLE": "manager",
            "ROLE_PRIORITY_STUDENT": 10,
            "ROLE_PRIORITY_ASSISTANT": 15,
            "ROLE_PRIORITY_TEACHER": 20,
            "ROLE_PRIORITY_MANAGER": 30,
            "ROLE_ATTRIBUTE_MAP_STUDENT": ["S", "student"],
            "ROLE_ATTRIBUTE_MAP_TEACHER": ["L", "teacher"],
            "ROLE_ATTRIBUTE_MAP_ADMIN": ["A", "admin", "schooladministrator"],
            "ROLE_MAPPINGS": {},
            "CUSTOM_ROLE_MAPPINGS": [],

            # Soft Delete
            "SOFT_DELETE_ENABLED": True,
            "SOFT_DELETE_MARK_COUNT": 10,
            "SOFT_DELETE_GRACE_PERIOD": 2592000,  # 30 Tage in Sekunden
            "DELETE_ENABLED": False,

            # User Settings
            "USER_AUTH_METHOD": "oauth2",
            "USER_MANAGED_MARKER": "edulution-sync",
            "COURSE_MANAGED_MARKER": "edulution-sync",
            "IGNORE_USERS": ["admin", "guest", "root"],
            "IGNORE_EMAIL_DOMAINS": ["test.local", "example.com"],
            "PROTECTED_USERS": ["admin", "guest"],
            "USERNAME_SOURCE": "username",
            "USERNAME_ATTRIBUTE": "",

            # Limits
            "MAX_USERS_PER_CYCLE": 0,  # 0 = unlimited
            "MAX_COURSES_PER_CYCLE": 0,

            # Behaviour
            "FORCE_MARKER_UPDATE": False,
            "SUSPEND_ON_LEAVE": True,

            # Notifications
            "ERROR_NOTIFY_ENABLED": False,
            "ERROR_NOTIFY_EMAIL": "",
            "WEBHOOK_URL": "",

            # Keycloak
            "KEYCLOAK_SERVER_URL": "https://keycloak.example.com/auth/",
            "KEYCLOAK_REALM": "edulution",
            "KEYCLOAK_CLIENT_ID": "edu-moodle-sync",
            "KEYCLOAK_SECRET_KEY": "",
            "KEYCLOAK_VERIFY_SSL": True,
            "KEYCLOAK_PAGE_SIZE": 50,

            # Moodle
            "MOODLE_PATH": "/var/www/html/moodle",

            # Performance
            "USE_HASH_DETECTION": True,
            "HASH_CACHE_FILE": "/srv/data/sync_hashes.json",
        }

    def _load_env(self):
        """Ueberschreibt mit Environment-Variablen"""
        for key in list(self.config.keys()):
            env_value = os.getenv(key)
            if env_value is not None:
                default_value = self.config[key]
                self.config[key] = self._parse_value(env_value, type(default_value))
                logger.debug(f"Loaded {key} from environment")

        # Zusaetzliche ENV-Variablen die nicht in Defaults sind
        additional_env_keys = [
            "KEYCLOAK_SERVER_URL", "KEYCLOAK_REALM",
            "KEYCLOAK_CLIENT_ID", "KEYCLOAK_SECRET_KEY",
            "MOODLE_PATH"
        ]
        for key in additional_env_keys:
            env_value = os.getenv(key)
            if env_value is not None:
                self.config[key] = env_value

        # Backward-Kompatibilitaet: KEYCLOAK_CLIENT_SECRET -> KEYCLOAK_SECRET_KEY
        if not self.config.get("KEYCLOAK_SECRET_KEY"):
            client_secret = os.getenv("KEYCLOAK_CLIENT_SECRET")
            if client_secret:
                self.config["KEYCLOAK_SECRET_KEY"] = client_secret
                logger.debug("Using KEYCLOAK_CLIENT_SECRET as KEYCLOAK_SECRET_KEY")

        # Berechne RETRY_INTERVAL automatisch wie in edulution-mail
        # wenn nicht explizit gesetzt
        if os.getenv("RETRY_INTERVAL") is None:
            sync_interval = self.config.get("SYNC_INTERVAL", 300)
            self.config["RETRY_INTERVAL"] = sync_interval // 5 if sync_interval >= 60 else 10
            logger.debug(f"Auto-calculated RETRY_INTERVAL: {self.config['RETRY_INTERVAL']}")

    def _load_override_file(self):
        """Laedt Override-Datei falls vorhanden"""
        if os.path.exists(self.override_file):
            try:
                with open(self.override_file, 'r') as f:
                    override = json.load(f)
                    self.config.update(override)
                    logger.info(f"Loaded override config from: {self.override_file}")
            except json.JSONDecodeError as e:
                logger.error(f"Error parsing override file: {e}")
            except Exception as e:
                logger.error(f"Error loading override file: {e}")

    def _parse_value(self, value: str, target_type: type) -> Any:
        """
        Parsed String-Wert in Zieltyp

        Args:
            value: String-Wert aus Environment
            target_type: Ziel-Datentyp

        Returns:
            Geparseter Wert im Zieltyp
        """
        if target_type == bool:
            return value.lower() in ('1', 'true', 'yes', 'on')
        elif target_type == int:
            try:
                return int(value)
            except ValueError:
                logger.warning(f"Could not parse '{value}' as int, returning 0")
                return 0
        elif target_type == float:
            try:
                return float(value)
            except ValueError:
                logger.warning(f"Could not parse '{value}' as float, returning 0.0")
                return 0.0
        elif target_type == list:
            return [v.strip() for v in value.split(',') if v.strip()]
        elif target_type == dict:
            try:
                return json.loads(value)
            except json.JSONDecodeError:
                logger.warning(f"Could not parse '{value}' as JSON dict")
                return {}
        return value

    def get(self, key: str, default: Any = None) -> Any:
        """
        Holt einen Konfigurationswert

        Args:
            key: Konfigurationsschluessel
            default: Default-Wert falls nicht gefunden

        Returns:
            Konfigurationswert oder Default
        """
        return self.config.get(key, default)

    def get_list(self, key: str) -> List[str]:
        """
        Holt einen Listenwert aus der Konfiguration

        Args:
            key: Konfigurationsschluessel

        Returns:
            Liste von Strings
        """
        value = self.get(key, [])
        if isinstance(value, str):
            return [v.strip() for v in value.split(',') if v.strip()]
        return value if isinstance(value, list) else []

    def get_bool(self, key: str) -> bool:
        """
        Holt einen Boolean-Wert aus der Konfiguration

        Args:
            key: Konfigurationsschluessel

        Returns:
            Boolean-Wert
        """
        value = self.get(key, False)
        if isinstance(value, bool):
            return value
        if isinstance(value, str):
            return value.lower() in ('1', 'true', 'yes', 'on')
        if isinstance(value, int):
            return value != 0
        return bool(value)

    def get_int(self, key: str, default: int = 0) -> int:
        """
        Holt einen Integer-Wert aus der Konfiguration

        Args:
            key: Konfigurationsschluessel
            default: Default-Wert

        Returns:
            Integer-Wert
        """
        value = self.get(key, default)
        if isinstance(value, int):
            return value
        try:
            return int(value)
        except (ValueError, TypeError):
            return default

    def set(self, key: str, value: Any):
        """
        Setzt einen Konfigurationswert (nur zur Laufzeit)

        Args:
            key: Konfigurationsschluessel
            value: Neuer Wert
        """
        self.config[key] = value

    def reload(self):
        """Laedt die Konfiguration neu"""
        self._load_defaults()
        self._load_env()
        self._load_override_file()
        logger.info("Configuration reloaded")

    def dump(self) -> Dict[str, Any]:
        """
        Gibt die komplette Konfiguration zurueck (ohne Secrets)

        Returns:
            Konfiguration als Dictionary
        """
        safe_config = {}
        secret_keys = ["KEYCLOAK_SECRET_KEY", "DB_PASSWORD", "MOODLE_ADMIN_PASSWORD"]

        for key, value in self.config.items():
            if any(secret in key.upper() for secret in secret_keys):
                safe_config[key] = "***REDACTED***"
            else:
                safe_config[key] = value

        return safe_config
