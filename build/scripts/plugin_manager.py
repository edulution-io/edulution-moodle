#!/usr/bin/env python3
"""
plugin_manager.py - Plugin-Verwaltung fuer Edulution Moodle

Dieses Modul verwaltet Moodle-Plugins basierend auf einer Konfigurationsdatei
(plugins.json oder plugins.csv). Es ermoeglicht:
- Automatische Installation fehlender Plugins
- Aktualisierung bei Moodle-Version-Aenderungen
- Entfernung nicht mehr benoetigter Plugins
- State-Management zur Nachverfolgung von Aenderungen

Autor: Edulution Team
Lizenz: MIT
"""

import subprocess
import json
import csv
import os
import sys
import logging
import time
import re
import hashlib
from typing import List, Dict, Optional, Set, Any, Tuple
from dataclasses import dataclass
from datetime import datetime
from enum import Enum

# Logging konfigurieren
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
    handlers=[
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)


# =============================================================================
# DATENKLASSEN
# =============================================================================

class PluginStatus(Enum):
    """Status eines Plugins nach Sync-Operation"""
    INSTALLED = "installed"
    UPDATED = "updated"
    REMOVED = "removed"
    UNCHANGED = "unchanged"
    FAILED = "failed"
    SKIPPED = "skipped"


@dataclass
class PluginConfig:
    """Konfiguration eines gewuenschten Plugins aus der Config-Datei"""
    component: str
    name: str
    required: bool = True
    description: str = ""
    dev_only: bool = False
    version: Optional[str] = None  # Optional: spezifische Version pinnen
    source_url: Optional[str] = None  # Optional: Alternative Download-URL

    def to_dict(self) -> Dict[str, Any]:
        """Konvertiert zu Dictionary fuer JSON-Serialisierung"""
        return {
            "component": self.component,
            "name": self.name,
            "required": self.required,
            "description": self.description,
            "dev_only": self.dev_only,
            "version": self.version,
            "source_url": self.source_url
        }


@dataclass
class InstalledPlugin:
    """Informationen ueber ein installiertes Plugin"""
    component: str
    version: str
    enabled: bool
    path: Optional[str] = None

    def to_dict(self) -> Dict[str, Any]:
        """Konvertiert zu Dictionary fuer JSON-Serialisierung"""
        return {
            "component": self.component,
            "version": self.version,
            "enabled": self.enabled,
            "path": self.path
        }


@dataclass
class SyncResult:
    """Ergebnis einer Plugin-Sync-Operation"""
    component: str
    status: PluginStatus
    message: str = ""
    old_version: Optional[str] = None
    new_version: Optional[str] = None

    def to_dict(self) -> Dict[str, Any]:
        """Konvertiert zu Dictionary fuer JSON-Serialisierung"""
        return {
            "component": self.component,
            "status": self.status.value,
            "message": self.message,
            "old_version": self.old_version,
            "new_version": self.new_version
        }


# =============================================================================
# RATE LIMITER
# =============================================================================

class RateLimiter:
    """
    Rate-Limiter fuer Moodle.org API-Anfragen.

    Moodle.org hat ein Rate-Limit von ca. 10 Anfragen pro Minute.
    Dieser Limiter verwaltet die Anfragen und wartet bei Bedarf.
    """

    def __init__(self, requests_per_minute: int = 8, backoff_seconds: int = 30):
        self.requests_per_minute = requests_per_minute
        self.backoff_seconds = backoff_seconds
        self.request_times: List[float] = []
        self.consecutive_failures = 0
        self.max_consecutive_failures = 3

    def wait_if_needed(self):
        """Wartet falls Rate-Limit erreicht"""
        now = time.time()

        # Entferne alte Requests (aelter als 1 Minute)
        self.request_times = [t for t in self.request_times if now - t < 60]

        # Wenn zu viele Requests in der letzten Minute, warte
        if len(self.request_times) >= self.requests_per_minute:
            wait_time = 60 - (now - self.request_times[0])
            if wait_time > 0:
                logger.info(f"Rate limit reached, waiting {wait_time:.1f}s...")
                time.sleep(wait_time)

        self.request_times.append(time.time())

    def handle_rate_limit_error(self) -> bool:
        """
        Behandelt einen Rate-Limit-Fehler (HTTP 429).

        Returns:
            True wenn Retry erlaubt, False wenn max. Fehler erreicht
        """
        self.consecutive_failures += 1

        if self.consecutive_failures > self.max_consecutive_failures:
            logger.error("Max consecutive rate limit failures reached")
            return False

        # Exponentielles Backoff
        wait_time = self.backoff_seconds * (2 ** (self.consecutive_failures - 1))
        logger.warning(f"Rate limited (429), waiting {wait_time}s before retry...")
        time.sleep(wait_time)
        return True

    def reset_failures(self):
        """Setzt Fehler-Zaehler zurueck nach erfolgreichem Request"""
        self.consecutive_failures = 0


# =============================================================================
# PLUGIN MANAGER HAUPTKLASSE
# =============================================================================

class PluginManager:
    """
    Verwaltet Moodle-Plugins basierend auf Konfigurationsdatei.

    Workflow:
    1. Lese gewuenschte Plugins aus plugins.json/csv
    2. Vergleiche mit installierten Plugins (via moosh)
    3. Installiere fehlende, entferne nicht mehr benoetigte
    4. Bei Moodle-Version-Aenderung: Alle Plugins neu installieren

    Features:
    - Unterstuetzt JSON und CSV Konfiguration
    - State-Management fuer Nachverfolgung
    - Rate-Limiting fuer Moodle.org API
    - Wartungsmodus-Management
    - Fehlerbenachrichtigung via Webhook

    Environment Variables:
    - MOODLE_PATH: Pfad zur Moodle-Installation (default: /var/www/html/moodle)
    - PLUGIN_CONFIG: Pfad zur Plugin-Konfiguration (default: /srv/config/plugins.json)
    - PLUGIN_STATE_FILE: Pfad zur State-Datei (default: /srv/data/plugin_state.json)
    - PLUGIN_INSTALL_DELAY: Verzoegerung zwischen Installationen in Sekunden (default: 3)
    - ENVIRONMENT: development oder production (default: production)
    - WEBHOOK_URL: Optional - URL fuer Fehlerbenachrichtigungen
    """

    def __init__(
        self,
        moodle_path: Optional[str] = None,
        config_path: Optional[str] = None,
        state_file: Optional[str] = None
    ):
        """
        Initialisiert den PluginManager.

        Args:
            moodle_path: Pfad zur Moodle-Installation
            config_path: Pfad zur Plugin-Konfigurationsdatei
            state_file: Pfad zur State-Datei
        """
        self.moodle_path = moodle_path or os.getenv(
            "MOODLE_PATH", "/var/www/html/moodle"
        )
        self.config_path = config_path or os.getenv(
            "PLUGIN_CONFIG", "/srv/config/plugins.json"
        )
        self.state_file = state_file or os.getenv(
            "PLUGIN_STATE_FILE", "/srv/data/plugin_state.json"
        )
        self.update_delay = int(os.getenv("PLUGIN_INSTALL_DELAY", "3"))
        self.is_dev = os.getenv("ENVIRONMENT", "production").lower() == "development"
        self.webhook_url = os.getenv("WEBHOOK_URL")

        # Rate-Limiter initialisieren
        self.rate_limiter = RateLimiter(requests_per_minute=8, backoff_seconds=30)

        # Validierung
        self._validate_paths()

    def _validate_paths(self):
        """Validiert dass notwendige Pfade existieren"""
        if not os.path.isdir(self.moodle_path):
            logger.warning(f"Moodle path does not exist: {self.moodle_path}")

        if not os.path.isfile(self.config_path):
            logger.warning(f"Plugin config does not exist: {self.config_path}")

    # =========================================================================
    # KONFIGURATION LADEN
    # =========================================================================

    def load_plugin_config(self) -> List[PluginConfig]:
        """
        Laedt Plugin-Liste aus Konfigurationsdatei.

        Unterstuetzt:
        - JSON-Format (.json)
        - CSV-Format (.csv)

        Returns:
            Liste von PluginConfig-Objekten

        Raises:
            FileNotFoundError: Wenn Config-Datei nicht existiert
            ValueError: Bei unbekanntem Format
        """
        if not os.path.exists(self.config_path):
            raise FileNotFoundError(f"Config file not found: {self.config_path}")

        if self.config_path.endswith('.json'):
            return self._load_json_config()
        elif self.config_path.endswith('.csv'):
            return self._load_csv_config()
        else:
            raise ValueError(f"Unknown config format: {self.config_path}")

    def _load_json_config(self) -> List[PluginConfig]:
        """Laedt plugins.json"""
        with open(self.config_path, 'r', encoding='utf-8') as f:
            data = json.load(f)

        plugins = []
        for p in data.get('plugins', []):
            # Skip dev-only Plugins in Produktion
            if p.get('dev_only', False) and not self.is_dev:
                logger.debug(f"Skipping dev-only plugin: {p.get('component')}")
                continue

            plugins.append(PluginConfig(
                component=p['component'],
                name=p.get('name', p['component']),
                required=p.get('required', True),
                description=p.get('description', ''),
                dev_only=p.get('dev_only', False),
                version=p.get('version'),
                source_url=p.get('source_url')
            ))

        logger.info(f"Loaded {len(plugins)} plugins from JSON config")
        return plugins

    def _load_csv_config(self) -> List[PluginConfig]:
        """
        Laedt plugins.csv

        Erwartetes Format:
        component,name,required,description,dev_only,version
        """
        plugins = []

        with open(self.config_path, 'r', encoding='utf-8') as f:
            # Lies alle Zeilen und filtere Kommentare
            lines = [
                line for line in f.readlines()
                if line.strip() and not line.strip().startswith('#')
            ]

        if not lines:
            logger.warning("CSV config is empty")
            return plugins

        # Parse CSV
        reader = csv.DictReader(lines)
        for row in reader:
            component = row.get('component', '').strip()
            if not component:
                continue

            required = row.get('required', 'true').lower() in ('true', 'yes', '1')
            dev_only = row.get('dev_only', 'false').lower() in ('true', 'yes', '1')

            # Skip dev-only Plugins in Produktion
            if dev_only and not self.is_dev:
                logger.debug(f"Skipping dev-only plugin: {component}")
                continue

            plugins.append(PluginConfig(
                component=component,
                name=row.get('name', component).strip(),
                required=required,
                description=row.get('description', '').strip(),
                dev_only=dev_only,
                version=row.get('version', '').strip() or None
            ))

        logger.info(f"Loaded {len(plugins)} plugins from CSV config")
        return plugins

    # =========================================================================
    # INSTALLIERTE PLUGINS ERMITTELN
    # =========================================================================

    def get_installed_plugins(self) -> Dict[str, InstalledPlugin]:
        """
        Ermittelt aktuell installierte Plugins via moosh.

        Verwendet 'moosh plugin-list' um alle installierten Plugins
        mit ihren Versionen zu ermitteln.

        Returns:
            Dictionary mit component als Key und InstalledPlugin als Value
        """
        try:
            result = subprocess.run(
                ["moosh", "-n", "plugin-list"],
                cwd=self.moodle_path,
                capture_output=True,
                text=True,
                timeout=60
            )

            if result.returncode != 0:
                logger.error(f"moosh plugin-list failed: {result.stderr}")
                # Fallback: Versuche aus Dateisystem zu lesen
                return self._get_plugins_from_filesystem()

            installed = {}
            for line in result.stdout.strip().split('\n'):
                if not line or line.startswith('#'):
                    continue

                parts = line.split(',')
                if len(parts) >= 1:
                    component = parts[0].strip()
                    version = parts[1].strip() if len(parts) > 1 else "unknown"
                    enabled_str = parts[2].strip() if len(parts) > 2 else "1"

                    installed[component] = InstalledPlugin(
                        component=component,
                        version=version,
                        enabled=enabled_str in ('1', 'true', 'enabled')
                    )

            logger.info(f"Found {len(installed)} installed plugins via moosh")
            return installed

        except subprocess.TimeoutExpired:
            logger.error("moosh plugin-list timed out")
            return self._get_plugins_from_filesystem()
        except FileNotFoundError:
            logger.error("moosh command not found")
            return self._get_plugins_from_filesystem()

    def _get_plugins_from_filesystem(self) -> Dict[str, InstalledPlugin]:
        """
        Fallback: Ermittelt installierte Plugins aus Dateisystem.

        Durchsucht die Moodle-Plugin-Verzeichnisse nach installierten Plugins.
        """
        installed = {}
        plugin_types = {
            'mod': 'mod',
            'block': 'blocks',
            'theme': 'theme',
            'auth': 'auth',
            'enrol': 'enrol',
            'local': 'local',
            'report': 'report',
            'tool': 'admin/tool',
            'format': 'course/format',
            'qtype': 'question/type',
            'qbehaviour': 'question/behaviour',
            'filter': 'filter',
            'editor': 'lib/editor'
        }

        for plugin_type, directory in plugin_types.items():
            plugin_dir = os.path.join(self.moodle_path, directory)
            if not os.path.isdir(plugin_dir):
                continue

            for name in os.listdir(plugin_dir):
                version_file = os.path.join(plugin_dir, name, 'version.php')
                if os.path.isfile(version_file):
                    component = f"{plugin_type}_{name}"
                    version = self._extract_version_from_file(version_file)
                    installed[component] = InstalledPlugin(
                        component=component,
                        version=version,
                        enabled=True,
                        path=os.path.join(plugin_dir, name)
                    )

        logger.info(f"Found {len(installed)} installed plugins from filesystem")
        return installed

    def _extract_version_from_file(self, version_file: str) -> str:
        """Extrahiert Version aus version.php Datei"""
        try:
            with open(version_file, 'r', encoding='utf-8') as f:
                content = f.read()

            # Suche nach $plugin->version = YYYYMMDDXX;
            match = re.search(r"\$plugin->version\s*=\s*(\d+)", content)
            if match:
                return match.group(1)

            # Alternative: $module->version
            match = re.search(r"\$module->version\s*=\s*(\d+)", content)
            if match:
                return match.group(1)

            return "unknown"
        except Exception as e:
            logger.debug(f"Could not extract version from {version_file}: {e}")
            return "unknown"

    def get_moodle_version(self) -> str:
        """
        Liest aktuelle Moodle-Version aus version.php.

        Returns:
            Moodle-Version als String (z.B. "2024042200" oder "4.5")
        """
        version_file = os.path.join(self.moodle_path, "version.php")

        if not os.path.isfile(version_file):
            logger.error(f"Moodle version.php not found: {version_file}")
            return "unknown"

        try:
            with open(version_file, 'r', encoding='utf-8') as f:
                content = f.read()

            # $version = 2024042200.00;
            match = re.search(r"\$version\s*=\s*(\d+)", content)
            if match:
                return match.group(1)

            # $release = '4.5 (Build: 20240422)';
            match = re.search(r"\$release\s*=\s*'([^']+)'", content)
            if match:
                return match.group(1)

            return "unknown"
        except Exception as e:
            logger.error(f"Could not read Moodle version: {e}")
            return "unknown"

    def get_moodle_release(self) -> str:
        """
        Liest Moodle-Release String (z.B. "4.5").

        Returns:
            Moodle-Release als String
        """
        version_file = os.path.join(self.moodle_path, "version.php")

        if not os.path.isfile(version_file):
            return "unknown"

        try:
            with open(version_file, 'r', encoding='utf-8') as f:
                content = f.read()

            match = re.search(r"\$release\s*=\s*'([^']+)'", content)
            if match:
                # Extrahiere nur die Versionsnummer (z.B. "4.5" aus "4.5 (Build: ...)")
                release = match.group(1)
                version_match = re.match(r"(\d+\.\d+)", release)
                if version_match:
                    return version_match.group(1)
                return release

            return "unknown"
        except Exception as e:
            logger.error(f"Could not read Moodle release: {e}")
            return "unknown"

    # =========================================================================
    # STATE MANAGEMENT
    # =========================================================================

    def load_state(self) -> Dict[str, Any]:
        """
        Laedt gespeicherten State (letzte Moodle-Version, installierte Plugins).

        Returns:
            State-Dictionary mit Keys:
            - moodle_version: Zuletzt bekannte Moodle-Version
            - moodle_release: Zuletzt bekanntes Moodle-Release
            - installed_plugins: Liste verwalteter Plugin-Komponenten
            - last_sync: Zeitpunkt der letzten Synchronisation
            - config_hash: Hash der Config-Datei bei letztem Sync
        """
        if os.path.exists(self.state_file):
            try:
                with open(self.state_file, 'r', encoding='utf-8') as f:
                    state = json.load(f)
                logger.debug(f"Loaded state from {self.state_file}")
                return state
            except (json.JSONDecodeError, IOError) as e:
                logger.warning(f"Could not load state file: {e}")

        return {
            "moodle_version": None,
            "moodle_release": None,
            "installed_plugins": [],
            "last_sync": None,
            "config_hash": None
        }

    def save_state(self, state: Dict[str, Any]):
        """
        Speichert aktuellen State.

        Args:
            state: State-Dictionary zum Speichern
        """
        # Erstelle Verzeichnis falls nicht vorhanden
        state_dir = os.path.dirname(self.state_file)
        if state_dir:
            os.makedirs(state_dir, exist_ok=True)

        try:
            with open(self.state_file, 'w', encoding='utf-8') as f:
                json.dump(state, f, indent=2, ensure_ascii=False)
            logger.debug(f"Saved state to {self.state_file}")
        except IOError as e:
            logger.error(f"Could not save state file: {e}")

    def _get_config_hash(self) -> str:
        """Berechnet Hash der Config-Datei zur Aenderungserkennung"""
        if not os.path.exists(self.config_path):
            return ""

        try:
            with open(self.config_path, 'rb') as f:
                return hashlib.md5(f.read()).hexdigest()
        except IOError:
            return ""

    def moodle_version_changed(self) -> bool:
        """
        Prueft ob Moodle-Version sich geaendert hat.

        Returns:
            True wenn Version geaendert oder erster Lauf
        """
        state = self.load_state()
        current_version = self.get_moodle_version()
        last_version = state.get("moodle_version")

        if last_version is None:
            logger.info("First run - no previous version recorded")
            return True

        if current_version != last_version:
            logger.info(f"Moodle version changed: {last_version} -> {current_version}")
            return True

        return False

    def config_changed(self) -> bool:
        """
        Prueft ob Plugin-Konfiguration sich geaendert hat.

        Returns:
            True wenn Config geaendert
        """
        state = self.load_state()
        current_hash = self._get_config_hash()
        last_hash = state.get("config_hash")

        if last_hash is None:
            return True

        return current_hash != last_hash

    # =========================================================================
    # SYNC LOGIK
    # =========================================================================

    def sync_plugins(
        self,
        force_reinstall: bool = False,
        dry_run: bool = False
    ) -> Dict[str, Any]:
        """
        Synchronisiert Plugins mit Konfiguration.

        Args:
            force_reinstall: Alle Plugins neu installieren (z.B. nach Moodle-Update)
            dry_run: Nur simulieren, keine Aenderungen durchfuehren

        Returns:
            Dictionary mit Sync-Ergebnissen:
            {
                "success": bool,
                "moodle_version": str,
                "results": {component: SyncResult},
                "summary": {
                    "installed": int,
                    "updated": int,
                    "removed": int,
                    "failed": int,
                    "unchanged": int
                }
            }
        """
        logger.info("Starting plugin synchronization...")

        results: Dict[str, SyncResult] = {}

        try:
            # Konfiguration und Status laden
            config_plugins = {p.component: p for p in self.load_plugin_config()}
            installed_plugins = self.get_installed_plugins()
            state = self.load_state()
            current_version = self.get_moodle_version()

            # Pruefen ob Moodle-Version sich geaendert hat
            version_changed = self.moodle_version_changed()
            if version_changed:
                logger.warning("Moodle version changed - will reinstall all plugins")
                force_reinstall = True

            # Sets fuer Vergleich
            config_set = set(config_plugins.keys())
            installed_set = set(installed_plugins.keys())

            # Zu installierende Plugins (in Config aber nicht installiert)
            to_install = config_set - installed_set

            # Zu entfernende Plugins (waren von uns installiert, jetzt nicht mehr in Config)
            managed_plugins = set(state.get("installed_plugins", []))
            to_remove = (installed_set & managed_plugins) - config_set

            # Zu aktualisierende Plugins (bei Version-Aenderung oder Force)
            to_update = config_set & installed_set if force_reinstall else set()

            # Unveraenderte Plugins
            unchanged = config_set & installed_set - to_update

            logger.info(
                f"Plugin sync plan: {len(to_install)} to install, "
                f"{len(to_update)} to update, {len(to_remove)} to remove, "
                f"{len(unchanged)} unchanged"
            )

            if dry_run:
                logger.info("DRY RUN - no changes will be made")
                return self._create_dry_run_results(
                    to_install, to_update, to_remove, unchanged,
                    config_plugins, installed_plugins, current_version
                )

            # Wartungsmodus wenn Aenderungen noetig
            changes_needed = to_install or to_update or to_remove
            if changes_needed:
                self._maintenance_mode(True)

            try:
                # 1. Plugins entfernen
                for component in to_remove:
                    old_version = installed_plugins.get(component)
                    success, message = self._uninstall_plugin(component)
                    results[component] = SyncResult(
                        component=component,
                        status=PluginStatus.REMOVED if success else PluginStatus.FAILED,
                        message=message,
                        old_version=old_version.version if old_version else None
                    )

                # 2. Plugins installieren
                for component in to_install:
                    plugin_config = config_plugins[component]
                    self.rate_limiter.wait_if_needed()

                    success, message, version = self._install_plugin(
                        component, plugin_config.version, plugin_config.source_url
                    )

                    results[component] = SyncResult(
                        component=component,
                        status=PluginStatus.INSTALLED if success else PluginStatus.FAILED,
                        message=message,
                        new_version=version
                    )

                    if success:
                        self.rate_limiter.reset_failures()

                    time.sleep(self.update_delay)

                # 3. Plugins aktualisieren (bei Moodle-Version-Aenderung)
                for component in to_update:
                    plugin_config = config_plugins[component]
                    old_plugin = installed_plugins.get(component)
                    self.rate_limiter.wait_if_needed()

                    success, message, version = self._reinstall_plugin(
                        component, plugin_config.version
                    )

                    results[component] = SyncResult(
                        component=component,
                        status=PluginStatus.UPDATED if success else PluginStatus.FAILED,
                        message=message,
                        old_version=old_plugin.version if old_plugin else None,
                        new_version=version
                    )

                    if success:
                        self.rate_limiter.reset_failures()

                    time.sleep(self.update_delay)

                # 4. Unveraenderte markieren
                for component in unchanged:
                    installed = installed_plugins.get(component)
                    results[component] = SyncResult(
                        component=component,
                        status=PluginStatus.UNCHANGED,
                        old_version=installed.version if installed else None,
                        new_version=installed.version if installed else None
                    )

                # 5. Datenbank-Upgrade wenn Aenderungen
                if changes_needed:
                    logger.info("Running database upgrade...")
                    self._run_upgrade()
                    self._purge_caches()

            finally:
                if changes_needed:
                    self._maintenance_mode(False)

            # State aktualisieren
            new_state = {
                "moodle_version": current_version,
                "moodle_release": self.get_moodle_release(),
                "installed_plugins": list(config_plugins.keys()),
                "last_sync": datetime.now().isoformat(),
                "config_hash": self._get_config_hash()
            }
            self.save_state(new_state)

            # Ergebnis-Zusammenfassung
            summary = self._create_summary(results)

            # Bei Fehlern benachrichtigen
            failed = [c for c, r in results.items() if r.status == PluginStatus.FAILED]
            if failed:
                logger.error(f"Failed plugins: {failed}")
                self._notify_failures(failed)

            return {
                "success": len(failed) == 0,
                "moodle_version": current_version,
                "results": {c: r.to_dict() for c, r in results.items()},
                "summary": summary
            }

        except Exception as e:
            logger.exception(f"Plugin sync failed: {e}")
            return {
                "success": False,
                "error": str(e),
                "moodle_version": self.get_moodle_version(),
                "results": {},
                "summary": {}
            }

    def _create_dry_run_results(
        self,
        to_install: Set[str],
        to_update: Set[str],
        to_remove: Set[str],
        unchanged: Set[str],
        config_plugins: Dict[str, PluginConfig],
        installed_plugins: Dict[str, InstalledPlugin],
        current_version: str
    ) -> Dict[str, Any]:
        """Erstellt Ergebnisse fuer Dry-Run"""
        results = {}

        for component in to_install:
            results[component] = {
                "component": component,
                "status": "would_install",
                "message": "Would be installed"
            }

        for component in to_update:
            installed = installed_plugins.get(component)
            results[component] = {
                "component": component,
                "status": "would_update",
                "message": "Would be updated",
                "old_version": installed.version if installed else None
            }

        for component in to_remove:
            installed = installed_plugins.get(component)
            results[component] = {
                "component": component,
                "status": "would_remove",
                "message": "Would be removed",
                "old_version": installed.version if installed else None
            }

        for component in unchanged:
            installed = installed_plugins.get(component)
            results[component] = {
                "component": component,
                "status": "unchanged",
                "old_version": installed.version if installed else None
            }

        return {
            "success": True,
            "dry_run": True,
            "moodle_version": current_version,
            "results": results,
            "summary": {
                "would_install": len(to_install),
                "would_update": len(to_update),
                "would_remove": len(to_remove),
                "unchanged": len(unchanged)
            }
        }

    def _create_summary(self, results: Dict[str, SyncResult]) -> Dict[str, int]:
        """Erstellt Zusammenfassung der Sync-Ergebnisse"""
        summary = {
            "installed": 0,
            "updated": 0,
            "removed": 0,
            "failed": 0,
            "unchanged": 0,
            "skipped": 0
        }

        for result in results.values():
            if result.status == PluginStatus.INSTALLED:
                summary["installed"] += 1
            elif result.status == PluginStatus.UPDATED:
                summary["updated"] += 1
            elif result.status == PluginStatus.REMOVED:
                summary["removed"] += 1
            elif result.status == PluginStatus.FAILED:
                summary["failed"] += 1
            elif result.status == PluginStatus.UNCHANGED:
                summary["unchanged"] += 1
            elif result.status == PluginStatus.SKIPPED:
                summary["skipped"] += 1

        return summary

    # =========================================================================
    # PLUGIN OPERATIONEN
    # =========================================================================

    def _install_plugin(
        self,
        component: str,
        version: Optional[str] = None,
        source_url: Optional[str] = None,
        retry_count: int = 0
    ) -> Tuple[bool, str, Optional[str]]:
        """
        Installiert ein Plugin via moosh.

        Args:
            component: Plugin-Komponente (z.B. "mod_attendance")
            version: Optionale spezifische Version
            source_url: Optionale alternative Download-URL
            retry_count: Interner Retry-Zaehler

        Returns:
            Tuple (success: bool, message: str, installed_version: str|None)
        """
        logger.info(f"Installing plugin: {component}")

        cmd = ["moosh", "-n", "plugin-install"]
        if version:
            cmd.extend(["--release", version])
        cmd.append(component)

        try:
            result = subprocess.run(
                cmd,
                cwd=self.moodle_path,
                capture_output=True,
                text=True,
                timeout=300
            )

            if result.returncode == 0:
                logger.info(f"Successfully installed: {component}")
                # Versuche installierte Version zu ermitteln
                installed_version = self._get_installed_version(component)
                return True, "Installed successfully", installed_version

            stderr = result.stderr.lower()

            # Rate-Limit Handling
            if "429" in result.stderr or "rate limit" in stderr:
                if self.rate_limiter.handle_rate_limit_error():
                    return self._install_plugin(component, version, source_url, retry_count + 1)
                return False, "Rate limit exceeded, max retries reached", None

            # Plugin nicht gefunden
            if "not found" in stderr or "does not exist" in stderr:
                logger.error(f"Plugin not found: {component}")
                return False, f"Plugin not found in Moodle plugin directory", None

            # Inkompatible Version
            if "incompatible" in stderr or "requires" in stderr:
                logger.error(f"Plugin incompatible: {component}: {result.stderr}")
                return False, f"Plugin incompatible with this Moodle version", None

            logger.error(f"Failed to install {component}: {result.stderr}")
            return False, result.stderr.strip() or "Installation failed", None

        except subprocess.TimeoutExpired:
            logger.error(f"Plugin installation timed out: {component}")
            return False, "Installation timed out", None
        except Exception as e:
            logger.exception(f"Plugin installation error: {component}")
            return False, str(e), None

    def _reinstall_plugin(
        self,
        component: str,
        version: Optional[str] = None
    ) -> Tuple[bool, str, Optional[str]]:
        """
        Reinstalliert ein Plugin (fuer Updates nach Moodle-Version-Aenderung).

        Args:
            component: Plugin-Komponente
            version: Optionale spezifische Version

        Returns:
            Tuple (success: bool, message: str, installed_version: str|None)
        """
        logger.info(f"Reinstalling plugin: {component}")

        # --delete entfernt alte Version und installiert neu
        cmd = ["moosh", "-n", "plugin-install", "--delete"]
        if version:
            cmd.extend(["--release", version])
        cmd.append(component)

        try:
            result = subprocess.run(
                cmd,
                cwd=self.moodle_path,
                capture_output=True,
                text=True,
                timeout=300
            )

            if result.returncode == 0:
                logger.info(f"Successfully reinstalled: {component}")
                installed_version = self._get_installed_version(component)
                return True, "Reinstalled successfully", installed_version

            # Rate-Limit Handling
            if "429" in result.stderr or "rate limit" in result.stderr.lower():
                if self.rate_limiter.handle_rate_limit_error():
                    return self._reinstall_plugin(component, version)
                return False, "Rate limit exceeded", None

            logger.error(f"Failed to reinstall {component}: {result.stderr}")
            return False, result.stderr.strip() or "Reinstallation failed", None

        except subprocess.TimeoutExpired:
            logger.error(f"Plugin reinstallation timed out: {component}")
            return False, "Reinstallation timed out", None
        except Exception as e:
            logger.exception(f"Plugin reinstallation error: {component}")
            return False, str(e), None

    def _uninstall_plugin(self, component: str) -> Tuple[bool, str]:
        """
        Deinstalliert ein Plugin.

        Args:
            component: Plugin-Komponente

        Returns:
            Tuple (success: bool, message: str)
        """
        logger.info(f"Uninstalling plugin: {component}")

        try:
            result = subprocess.run(
                ["moosh", "-n", "plugin-uninstall", component],
                cwd=self.moodle_path,
                capture_output=True,
                text=True,
                timeout=120
            )

            if result.returncode == 0:
                logger.info(f"Successfully uninstalled: {component}")
                return True, "Uninstalled successfully"

            logger.error(f"Failed to uninstall {component}: {result.stderr}")
            return False, result.stderr.strip() or "Uninstallation failed"

        except subprocess.TimeoutExpired:
            logger.error(f"Plugin uninstallation timed out: {component}")
            return False, "Uninstallation timed out"
        except Exception as e:
            logger.exception(f"Plugin uninstallation error: {component}")
            return False, str(e)

    def _get_installed_version(self, component: str) -> Optional[str]:
        """Ermittelt die installierte Version eines Plugins"""
        plugins = self.get_installed_plugins()
        plugin = plugins.get(component)
        return plugin.version if plugin else None

    # =========================================================================
    # MOODLE OPERATIONEN
    # =========================================================================

    def _maintenance_mode(self, enable: bool):
        """Aktiviert/Deaktiviert Wartungsmodus"""
        cmd = "maintenance-on" if enable else "maintenance-off"

        try:
            subprocess.run(
                ["moosh", "-n", cmd],
                cwd=self.moodle_path,
                capture_output=True,
                timeout=30
            )
            logger.info(f"Maintenance mode: {'ON' if enable else 'OFF'}")
        except Exception as e:
            logger.warning(f"Could not set maintenance mode: {e}")

    def _run_upgrade(self):
        """Fuehrt Moodle-Datenbank-Upgrade durch"""
        try:
            result = subprocess.run(
                ["php", "admin/cli/upgrade.php", "--non-interactive"],
                cwd=self.moodle_path,
                capture_output=True,
                text=True,
                timeout=600
            )

            if result.returncode == 0:
                logger.info("Database upgrade completed successfully")
            else:
                logger.warning(f"Database upgrade returned: {result.stderr}")
        except subprocess.TimeoutExpired:
            logger.error("Database upgrade timed out")
        except Exception as e:
            logger.error(f"Database upgrade failed: {e}")

    def _purge_caches(self):
        """Leert Moodle-Caches"""
        try:
            subprocess.run(
                ["php", "admin/cli/purge_caches.php"],
                cwd=self.moodle_path,
                capture_output=True,
                timeout=60
            )
            logger.info("Caches purged")
        except Exception as e:
            logger.warning(f"Could not purge caches: {e}")

    # =========================================================================
    # BENACHRICHTIGUNGEN
    # =========================================================================

    def _notify_failures(self, failed_plugins: List[str]):
        """Benachrichtigt ueber fehlgeschlagene Installationen via Webhook"""
        if not self.webhook_url:
            return

        try:
            import urllib.request

            data = json.dumps({
                "text": f"Moodle Plugin-Installation fehlgeschlagen: {', '.join(failed_plugins)}",
                "username": "Moodle Plugin Manager",
                "icon_emoji": ":warning:"
            }).encode('utf-8')

            req = urllib.request.Request(
                self.webhook_url,
                data=data,
                headers={"Content-Type": "application/json"}
            )

            urllib.request.urlopen(req, timeout=10)
            logger.info("Failure notification sent")
        except Exception as e:
            logger.warning(f"Could not send failure notification: {e}")

    # =========================================================================
    # REPORT GENERATION
    # =========================================================================

    def generate_report(self, format: str = "markdown") -> str:
        """
        Generiert einen Plugin-Status-Report.

        Args:
            format: Ausgabeformat ("markdown", "json", "text")

        Returns:
            Formatierter Report-String
        """
        try:
            config_plugins = {p.component: p for p in self.load_plugin_config()}
        except FileNotFoundError:
            config_plugins = {}

        installed_plugins = self.get_installed_plugins()
        state = self.load_state()

        if format == "json":
            return self._generate_json_report(config_plugins, installed_plugins, state)
        elif format == "text":
            return self._generate_text_report(config_plugins, installed_plugins, state)
        else:
            return self._generate_markdown_report(config_plugins, installed_plugins, state)

    def _generate_markdown_report(
        self,
        config_plugins: Dict[str, PluginConfig],
        installed_plugins: Dict[str, InstalledPlugin],
        state: Dict[str, Any]
    ) -> str:
        """Generiert Markdown-Report"""
        report = []
        report.append("# Moodle Plugin Status Report")
        report.append("")
        report.append(f"**Moodle Version:** {self.get_moodle_version()}")
        report.append(f"**Moodle Release:** {self.get_moodle_release()}")
        report.append(f"**Generated:** {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        report.append(f"**Last Sync:** {state.get('last_sync', 'Never')}")
        report.append(f"**Environment:** {'Development' if self.is_dev else 'Production'}")
        report.append("")

        # Konfigurierte Plugins
        report.append("## Configured Plugins")
        report.append("")
        report.append("| Plugin | Status | Version | Required |")
        report.append("|--------|--------|---------|----------|")

        for component, config in sorted(config_plugins.items()):
            installed = installed_plugins.get(component)
            if installed:
                status = "Installed"
                version = installed.version
            else:
                status = "Missing"
                version = "-"

            required = "Yes" if config.required else "No"
            dev_marker = " (dev)" if config.dev_only else ""
            report.append(f"| {config.name}{dev_marker} | {status} | {version} | {required} |")

        # Zusaetzlich installierte Plugins
        extra = set(installed_plugins.keys()) - set(config_plugins.keys())
        # Filtere Core-Plugins
        extra = {c for c in extra if not self._is_core_plugin(c)}

        if extra:
            report.append("")
            report.append("## Additional Installed Plugins (not in config)")
            report.append("")
            for component in sorted(extra):
                installed = installed_plugins[component]
                report.append(f"- **{component}** (v{installed.version})")

        # Zusammenfassung
        report.append("")
        report.append("## Summary")
        report.append("")
        configured = len(config_plugins)
        installed_configured = len(set(config_plugins.keys()) & set(installed_plugins.keys()))
        missing = configured - installed_configured

        report.append(f"- Configured plugins: {configured}")
        report.append(f"- Installed (configured): {installed_configured}")
        report.append(f"- Missing: {missing}")
        report.append(f"- Additional (not in config): {len(extra)}")

        return "\n".join(report)

    def _generate_json_report(
        self,
        config_plugins: Dict[str, PluginConfig],
        installed_plugins: Dict[str, InstalledPlugin],
        state: Dict[str, Any]
    ) -> str:
        """Generiert JSON-Report"""
        report = {
            "generated": datetime.now().isoformat(),
            "moodle_version": self.get_moodle_version(),
            "moodle_release": self.get_moodle_release(),
            "last_sync": state.get("last_sync"),
            "environment": "development" if self.is_dev else "production",
            "configured_plugins": {},
            "additional_plugins": [],
            "summary": {}
        }

        for component, config in config_plugins.items():
            installed = installed_plugins.get(component)
            report["configured_plugins"][component] = {
                "name": config.name,
                "required": config.required,
                "dev_only": config.dev_only,
                "installed": installed is not None,
                "version": installed.version if installed else None
            }

        extra = set(installed_plugins.keys()) - set(config_plugins.keys())
        extra = {c for c in extra if not self._is_core_plugin(c)}
        for component in sorted(extra):
            installed = installed_plugins[component]
            report["additional_plugins"].append({
                "component": component,
                "version": installed.version
            })

        configured = len(config_plugins)
        installed_configured = len(set(config_plugins.keys()) & set(installed_plugins.keys()))
        report["summary"] = {
            "configured": configured,
            "installed_configured": installed_configured,
            "missing": configured - installed_configured,
            "additional": len(extra)
        }

        return json.dumps(report, indent=2, ensure_ascii=False)

    def _generate_text_report(
        self,
        config_plugins: Dict[str, PluginConfig],
        installed_plugins: Dict[str, InstalledPlugin],
        state: Dict[str, Any]
    ) -> str:
        """Generiert Plain-Text-Report"""
        lines = []
        lines.append("MOODLE PLUGIN STATUS REPORT")
        lines.append("=" * 50)
        lines.append(f"Moodle Version: {self.get_moodle_version()}")
        lines.append(f"Moodle Release: {self.get_moodle_release()}")
        lines.append(f"Generated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
        lines.append(f"Last Sync: {state.get('last_sync', 'Never')}")
        lines.append("")
        lines.append("CONFIGURED PLUGINS:")
        lines.append("-" * 50)

        for component, config in sorted(config_plugins.items()):
            installed = installed_plugins.get(component)
            status = "[OK]" if installed else "[MISSING]"
            version = f"v{installed.version}" if installed else ""
            required = "(required)" if config.required else "(optional)"
            lines.append(f"  {status} {config.name} {version} {required}")

        return "\n".join(lines)

    def _is_core_plugin(self, component: str) -> bool:
        """Prueft ob ein Plugin ein Moodle-Core-Plugin ist"""
        # Core-Plugin-Typen die wir ignorieren
        core_prefixes = [
            'mod_assign', 'mod_book', 'mod_chat', 'mod_choice', 'mod_data',
            'mod_feedback', 'mod_folder', 'mod_forum', 'mod_glossary',
            'mod_imscp', 'mod_label', 'mod_lesson', 'mod_lti', 'mod_page',
            'mod_quiz', 'mod_resource', 'mod_scorm', 'mod_survey', 'mod_url',
            'mod_wiki', 'mod_workshop', 'mod_bigbluebuttonbn', 'mod_h5pactivity',
            'block_activity_modules', 'block_activity_results', 'block_admin_bookmarks',
            'block_badges', 'block_blog_menu', 'block_blog_recent', 'block_blog_tags',
            'block_calendar_month', 'block_calendar_upcoming', 'block_comments',
            'block_completionstatus', 'block_course_list', 'block_course_summary',
            'block_feedback', 'block_globalsearch', 'block_glossary_random',
            'block_html', 'block_login', 'block_lp', 'block_mentees', 'block_mnet_hosts',
            'block_myoverview', 'block_myprofile', 'block_navigation', 'block_news_items',
            'block_online_users', 'block_participants', 'block_private_files',
            'block_recent_activity', 'block_recentlyaccessedcourses',
            'block_recentlyaccesseditems', 'block_rss_client', 'block_search_forums',
            'block_section_links', 'block_selfcompletion', 'block_settings',
            'block_site_main_menu', 'block_social_activities', 'block_starredcourses',
            'block_tag_flickr', 'block_tag_youtube', 'block_tags', 'block_timeline',
            'theme_boost', 'theme_classic',
            'auth_db', 'auth_email', 'auth_ldap', 'auth_lti', 'auth_manual',
            'auth_mnet', 'auth_nologin', 'auth_none', 'auth_oauth2', 'auth_shibboleth',
            'auth_webservice',
            'enrol_category', 'enrol_cohort', 'enrol_database', 'enrol_fee',
            'enrol_flatfile', 'enrol_guest', 'enrol_imsenterprise', 'enrol_ldap',
            'enrol_lti', 'enrol_manual', 'enrol_meta', 'enrol_mnet', 'enrol_paypal',
            'enrol_self'
        ]

        return component in core_prefixes

    # =========================================================================
    # LIST PLUGINS
    # =========================================================================

    def list_plugins(self, show_all: bool = False) -> List[Dict[str, Any]]:
        """
        Listet konfigurierte Plugins.

        Args:
            show_all: Wenn True, auch dev-only Plugins in Production zeigen

        Returns:
            Liste von Plugin-Informationen
        """
        plugins = []

        try:
            # Temporaer is_dev ueberschreiben wenn show_all
            original_is_dev = self.is_dev
            if show_all:
                self.is_dev = True
            config_plugins = self.load_plugin_config()
            self.is_dev = original_is_dev
        except FileNotFoundError:
            logger.warning("No plugin config found")
            return plugins

        installed_plugins = self.get_installed_plugins()

        for config in config_plugins:
            installed = installed_plugins.get(config.component)
            plugins.append({
                "component": config.component,
                "name": config.name,
                "required": config.required,
                "dev_only": config.dev_only,
                "description": config.description,
                "pinned_version": config.version,
                "installed": installed is not None,
                "installed_version": installed.version if installed else None
            })

        return plugins


# =============================================================================
# CLI INTERFACE
# =============================================================================

def main():
    """Hauptfunktion fuer CLI-Interface"""
    import argparse

    parser = argparse.ArgumentParser(
        description="Moodle Plugin Manager - Verwaltet Plugins basierend auf Konfigurationsdatei",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Beispiele:
  %(prog)s --sync              Synchronisiert Plugins mit Konfiguration
  %(prog)s --sync --force      Erzwingt Neuinstallation aller Plugins
  %(prog)s --sync --dry-run    Zeigt was passieren wuerde ohne Aenderungen
  %(prog)s --report            Generiert Status-Report (Markdown)
  %(prog)s --report --json     Generiert Status-Report (JSON)
  %(prog)s --list              Listet konfigurierte Plugins

Environment Variables:
  MOODLE_PATH          Pfad zur Moodle-Installation
  PLUGIN_CONFIG        Pfad zur Plugin-Konfigurationsdatei
  PLUGIN_STATE_FILE    Pfad zur State-Datei
  ENVIRONMENT          development oder production
  WEBHOOK_URL          URL fuer Fehlerbenachrichtigungen
"""
    )

    # Haupt-Aktionen (mutually exclusive)
    actions = parser.add_mutually_exclusive_group(required=True)
    actions.add_argument(
        "--sync",
        action="store_true",
        help="Synchronisiert Plugins mit Konfiguration"
    )
    actions.add_argument(
        "--report",
        action="store_true",
        help="Generiert Status-Report"
    )
    actions.add_argument(
        "--list",
        action="store_true",
        help="Listet konfigurierte Plugins"
    )
    actions.add_argument(
        "--version",
        action="store_true",
        help="Zeigt Moodle-Version"
    )
    actions.add_argument(
        "--check",
        action="store_true",
        help="Prueft ob Sync notwendig ist"
    )

    # Optionen
    parser.add_argument(
        "--force",
        action="store_true",
        help="Erzwingt Neuinstallation aller Plugins"
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Simuliert nur, keine Aenderungen"
    )
    parser.add_argument(
        "--json",
        action="store_true",
        help="Ausgabe im JSON-Format"
    )
    parser.add_argument(
        "--config",
        type=str,
        help="Pfad zur Plugin-Konfigurationsdatei"
    )
    parser.add_argument(
        "--moodle-path",
        type=str,
        help="Pfad zur Moodle-Installation"
    )
    parser.add_argument(
        "-v", "--verbose",
        action="store_true",
        help="Ausfuehrliche Ausgabe"
    )
    parser.add_argument(
        "-q", "--quiet",
        action="store_true",
        help="Nur Fehler ausgeben"
    )

    args = parser.parse_args()

    # Logging-Level setzen
    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)
    elif args.quiet:
        logging.getLogger().setLevel(logging.ERROR)

    # PluginManager initialisieren
    manager = PluginManager(
        moodle_path=args.moodle_path,
        config_path=args.config
    )

    # Aktionen ausfuehren
    try:
        if args.sync:
            results = manager.sync_plugins(
                force_reinstall=args.force,
                dry_run=args.dry_run
            )

            if args.json:
                print(json.dumps(results, indent=2, ensure_ascii=False))
            else:
                _print_sync_results(results)

            # Exit-Code basierend auf Erfolg
            sys.exit(0 if results.get("success", False) else 1)

        elif args.report:
            format_type = "json" if args.json else "markdown"
            print(manager.generate_report(format=format_type))

        elif args.list:
            plugins = manager.list_plugins()

            if args.json:
                print(json.dumps(plugins, indent=2, ensure_ascii=False))
            else:
                for plugin in plugins:
                    status = "[installed]" if plugin["installed"] else "[missing]"
                    req = "required" if plugin["required"] else "optional"
                    dev = " (dev-only)" if plugin["dev_only"] else ""
                    print(f"{plugin['component']}: {plugin['name']} ({req}){dev} {status}")

        elif args.version:
            version = manager.get_moodle_version()
            release = manager.get_moodle_release()

            if args.json:
                print(json.dumps({
                    "version": version,
                    "release": release
                }, indent=2))
            else:
                print(f"Moodle Version: {version}")
                print(f"Moodle Release: {release}")

        elif args.check:
            version_changed = manager.moodle_version_changed()
            config_changed = manager.config_changed()

            needs_sync = version_changed or config_changed

            if args.json:
                print(json.dumps({
                    "needs_sync": needs_sync,
                    "version_changed": version_changed,
                    "config_changed": config_changed
                }, indent=2))
            else:
                if needs_sync:
                    reasons = []
                    if version_changed:
                        reasons.append("Moodle version changed")
                    if config_changed:
                        reasons.append("Plugin config changed")
                    print(f"Sync needed: {', '.join(reasons)}")
                    sys.exit(1)
                else:
                    print("No sync needed")
                    sys.exit(0)

    except FileNotFoundError as e:
        logger.error(f"File not found: {e}")
        sys.exit(2)
    except Exception as e:
        logger.exception(f"Error: {e}")
        sys.exit(1)


def _print_sync_results(results: Dict[str, Any]):
    """Formatierte Ausgabe der Sync-Ergebnisse"""
    print("\n" + "=" * 60)
    print("PLUGIN SYNC RESULTS")
    print("=" * 60)

    if results.get("dry_run"):
        print("[DRY RUN - No changes made]")

    print(f"\nMoodle Version: {results.get('moodle_version', 'unknown')}")

    # Zusammenfassung
    summary = results.get("summary", {})
    if summary:
        print("\nSummary:")
        for key, value in summary.items():
            if value > 0:
                print(f"  {key.replace('_', ' ').title()}: {value}")

    # Details
    plugin_results = results.get("results", {})
    if plugin_results:
        print("\nDetails:")
        for component, result in sorted(plugin_results.items()):
            status = result.get("status", "unknown")
            message = result.get("message", "")

            # Status-Symbol
            symbols = {
                "installed": "+",
                "would_install": "?",
                "updated": "^",
                "would_update": "?",
                "removed": "-",
                "would_remove": "?",
                "unchanged": "=",
                "failed": "X",
                "skipped": "-"
            }
            symbol = symbols.get(status, "?")

            print(f"  [{symbol}] {component}: {status}")
            if message:
                print(f"      {message}")

    # Erfolg/Fehler
    print("")
    if results.get("success"):
        print("Sync completed successfully!")
    else:
        error = results.get("error", "")
        if error:
            print(f"Sync failed: {error}")
        else:
            print("Sync completed with errors (see failed plugins above)")


if __name__ == "__main__":
    main()
