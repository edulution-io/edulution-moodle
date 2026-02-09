#!/usr/bin/env python3
"""
Edulution Moodle Sync - Hauptsynchronisierungsmodul

Synchronisiert Benutzer, Kurse und Gruppen von Keycloak nach Moodle.
Alle Optionen sind ueber Environment-Variablen konfigurierbar.

Usage:
    python sync.py                 # Startet den Sync-Loop
    python sync.py --single-run    # Fuehrt einen einzelnen Sync durch
    python sync.py --full          # Vollstaendiger Sync ohne Hash-Cache
    python sync.py --dry-run       # Zeigt Aenderungen ohne Ausfuehrung

Environment Variables:
    SYNC_INTERVAL          - Intervall zwischen Syncs in Sekunden (default: 300)
    DRY_RUN               - Bei '1' werden keine Aenderungen gemacht
    LOG_LEVEL             - Logging Level (DEBUG, INFO, WARNING, ERROR)
    KEYCLOAK_SERVER_URL   - Keycloak Server URL
    KEYCLOAK_REALM        - Keycloak Realm
    KEYCLOAK_CLIENT_ID    - Keycloak Client ID
    KEYCLOAK_SECRET_KEY   - Keycloak Client Secret
    ... (siehe ConfigurationStorage fuer alle Optionen)
"""

import os
import re
import sys
import time
import argparse
import logging
import hashlib
import json
from typing import Dict, List, Set, Optional, Any, Tuple

from modules.keycloak.keycloak import KeycloakClient
from modules.moodle.moosh import MooshWrapper
from modules.database.DeactivationTracker import DeactivationTracker
from modules.models.ConfigurationStorage import ConfigurationStorage
from modules.models.UserListStorage import UserListStorage
from modules.models.CourseListStorage import CourseListStorage
from modules.models.GroupListStorage import GroupListStorage

# Logging Setup
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(name)s - %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)
logger = logging.getLogger(__name__)


class ChangeDetector:
    """
    Schnelle Aenderungserkennung via Hash-Vergleich
    Speichert Hashes im lokalen Cache fuer Performance-Optimierung
    """

    def __init__(self, cache_file: str = "/srv/data/sync_hashes.json"):
        """
        Initialisiert den Change Detector

        Args:
            cache_file: Pfad zur Cache-Datei
        """
        self.cache_file = cache_file
        self.hashes: Dict[str, str] = {}
        self._load_hashes()

    def _load_hashes(self):
        """Laedt gespeicherte Hashes"""
        if os.path.exists(self.cache_file):
            try:
                with open(self.cache_file, 'r') as f:
                    self.hashes = json.load(f)
            except (json.JSONDecodeError, IOError):
                self.hashes = {}

    def _save_hashes(self):
        """Speichert Hashes"""
        try:
            os.makedirs(os.path.dirname(self.cache_file), exist_ok=True)
            with open(self.cache_file, 'w') as f:
                json.dump(self.hashes, f)
        except IOError as e:
            logger.error(f"Could not save hash cache: {e}")

    def compute_hash(self, obj: Dict, fields: List[str]) -> str:
        """Berechnet Hash ueber relevante Felder"""
        data = {f: obj.get(f) for f in sorted(fields) if obj.get(f) is not None}
        json_str = json.dumps(data, sort_keys=True)
        return hashlib.md5(json_str.encode()).hexdigest()

    def has_changed(self, obj_type: str, obj_id: str, obj: Dict, fields: List[str]) -> bool:
        """
        Prueft ob Objekt sich geaendert hat

        Returns:
            True wenn geaendert oder neu, False wenn unveraendert
        """
        cache_key = f"{obj_type}:{obj_id}"
        new_hash = self.compute_hash(obj, fields)
        old_hash = self.hashes.get(cache_key)

        if old_hash != new_hash:
            self.hashes[cache_key] = new_hash
            return True
        return False

    def mark_deleted(self, obj_type: str, obj_id: str):
        """Entfernt Hash fuer geloeschtes Objekt"""
        cache_key = f"{obj_type}:{obj_id}"
        self.hashes.pop(cache_key, None)

    def commit(self):
        """Speichert alle Aenderungen"""
        self._save_hashes()

    def clear(self):
        """Loescht den gesamten Cache"""
        self.hashes = {}
        self._save_hashes()


class MoodleSync:
    """
    Hauptklasse fuer die Keycloak zu Moodle Synchronisierung

    Implementiert den kompletten Sync-Zyklus:
    1. Daten aus Keycloak und Moodle laden
    2. Deltas berechnen
    3. Aenderungen synchronisieren
    """

    def __init__(self, config: ConfigurationStorage = None):
        """
        Initialisiert den Sync

        Args:
            config: Optionale Konfiguration (sonst aus ENV geladen)
        """
        # Konfiguration laden
        self.config = config or ConfigurationStorage()

        # Logging-Level aus Config
        log_level = self.config.get("LOG_LEVEL", "INFO")
        logging.getLogger().setLevel(getattr(logging, log_level.upper(), logging.INFO))

        # Clients initialisieren
        self.keycloak = KeycloakClient(
            server_url=self.config.get("KEYCLOAK_SERVER_URL"),
            realm=self.config.get("KEYCLOAK_REALM"),
            client_id=self.config.get("KEYCLOAK_CLIENT_ID"),
            client_secret=self.config.get("KEYCLOAK_SECRET_KEY"),
            verify_ssl=self.config.get_bool("KEYCLOAK_VERIFY_SSL"),
            page_size=self.config.get_int("KEYCLOAK_PAGE_SIZE", 50)
        )

        self.moosh = MooshWrapper(
            moodle_path=self.config.get("MOODLE_PATH"),
            dry_run=self.config.get_bool("DRY_RUN")
        )

        self.deactivation_tracker = DeactivationTracker(
            mark_count_threshold=self.config.get_int("SOFT_DELETE_MARK_COUNT", 10),
            grace_period=self.config.get_int("SOFT_DELETE_GRACE_PERIOD", 2592000),
            soft_delete_enabled=self.config.get_bool("SOFT_DELETE_ENABLED"),
            delete_enabled=self.config.get_bool("DELETE_ENABLED")
        )

        # Change Detector fuer Hash-basierte Erkennung
        if self.config.get_bool("USE_HASH_DETECTION"):
            self.change_detector = ChangeDetector(
                cache_file=self.config.get("HASH_CACHE_FILE", "/srv/data/sync_hashes.json")
            )
        else:
            self.change_detector = None

        # Storage-Objekte
        self.user_storage = UserListStorage()
        self.course_storage = CourseListStorage()
        self.group_storage = GroupListStorage()

        # Role-Mappings aus Config laden
        self.role_mappings = self._build_role_mappings()

        # Default-Kategorie
        self.default_course_category = self.config.get_int("DEFAULT_COURSE_CATEGORY", 1)

        # Managed Marker
        self.user_managed_marker = self.config.get("USER_MANAGED_MARKER", "edulution-sync")
        self.course_managed_marker = self.config.get("COURSE_MANAGED_MARKER", "edulution-sync")

    def _build_role_mappings(self) -> Dict[str, Dict]:
        """Baut Role-Mappings aus der Konfiguration"""
        mappings = {}

        # Student-Rollen
        for group in self.config.get_list("ROLE_STUDENT_GROUPS"):
            mappings[group] = {
                "moodle_role": self.config.get("ROLE_STUDENT_MOODLE", "student"),
                "context": "course",
                "priority": self.config.get_int("ROLE_PRIORITY_STUDENT", 10)
            }

        # Teacher-Rollen
        for group in self.config.get_list("ROLE_TEACHER_GROUPS"):
            mappings[group] = {
                "moodle_role": self.config.get("ROLE_TEACHER_MOODLE", "editingteacher"),
                "context": "course",
                "priority": self.config.get_int("ROLE_PRIORITY_TEACHER", 20)
            }

        # Assistant-Rollen
        for group in self.config.get_list("ROLE_ASSISTANT_GROUPS"):
            mappings[group] = {
                "moodle_role": self.config.get("ROLE_ASSISTANT_MOODLE", "teacher"),
                "context": "course",
                "priority": self.config.get_int("ROLE_PRIORITY_ASSISTANT", 15)
            }

        # Manager-Rollen (System-Level)
        for group in self.config.get_list("ROLE_MANAGER_GROUPS"):
            mappings[group] = {
                "moodle_role": self.config.get("ROLE_MANAGER_MOODLE", "manager"),
                "context": "system",
                "priority": self.config.get_int("ROLE_PRIORITY_MANAGER", 30)
            }

        # Override-Mappings aus JSON-Config
        custom_mappings = self.config.get("ROLE_MAPPINGS", {})
        if isinstance(custom_mappings, dict):
            mappings.update(custom_mappings)

        return mappings

    def _is_sync_disabled(self) -> bool:
        """Prueft ob Sync temporaer deaktiviert ist"""
        disable_file = self.config.get("DISABLE_SYNC_FILE", "")
        if disable_file and os.path.exists(disable_file):
            logger.warning(f"Sync disabled via file: {disable_file}")
            return True
        return False

    def run_sync(self, full_sync: bool = False) -> bool:
        """
        Fuehrt einen kompletten Sync-Zyklus durch

        Args:
            full_sync: Bei True wird Hash-Cache ignoriert

        Returns:
            True bei Erfolg
        """
        # Pruefe ob Sync deaktiviert
        if self._is_sync_disabled():
            return False

        dry_run = self.config.get_bool("DRY_RUN")
        if dry_run:
            logger.info("=== DRY RUN MODE - No changes will be made ===")

        if full_sync and self.change_detector:
            logger.info("Full sync requested - clearing hash cache")
            self.change_detector.clear()

        logger.info("=" * 60)
        logger.info("Starting Edulution-Moodle-Sync")
        logger.info("=" * 60)

        try:
            # Storage leeren fuer neuen Zyklus
            self.user_storage.clear_all_queues()
            self.course_storage.clear_all_queues()
            self.group_storage.clear_all_queues()

            # Phase 1: Daten laden
            logger.info("Phase 1: Loading data from Keycloak and Moodle")
            keycloak_users = self._load_keycloak_users()
            keycloak_groups = self._load_keycloak_groups()
            moodle_users = self._load_moodle_users()
            moodle_courses = self._load_moodle_courses()

            # Phase 2: Deltas berechnen
            logger.info("Phase 2: Calculating deltas")
            self._calculate_user_deltas(keycloak_users, moodle_users)
            self._calculate_course_deltas(keycloak_groups, moodle_courses)

            # Phase 3: Synchronisieren
            logger.info("Phase 3: Syncing to Moodle")
            if not dry_run:
                self._sync_users()
                self._sync_courses()
                self._sync_enrolments(keycloak_groups, keycloak_users)
                self._sync_groups(keycloak_groups, keycloak_users)
                self._process_deactivations()

                # Hash-Cache speichern
                if self.change_detector:
                    self.change_detector.commit()
            else:
                self._log_planned_changes()

            logger.info("=" * 60)
            logger.info("Sync finished successfully")
            logger.info("=" * 60)
            return True

        except Exception as e:
            logger.error(f"Sync failed: {e}", exc_info=True)
            self._notify_error(e)
            return False

    # ==================== DATA LOADING ====================

    def _should_sync_user(self, user: Dict, user_groups: List[str]) -> bool:
        """
        Entscheidet ob ein User synchronisiert werden soll
        basierend auf den Konfigurationsoptionen
        """
        username = user.get('username', '')
        email = user.get('email', '')

        # Ignorierte User pruefen
        ignore_users = self.config.get_list("IGNORE_USERS")
        if username in ignore_users:
            logger.debug(f"Skipping ignored user: {username}")
            return False

        # Ignorierte Email-Domains pruefen
        ignore_domains = self.config.get_list("IGNORE_EMAIL_DOMAINS")
        if email:
            email_domain = email.split('@')[-1] if '@' in email else ''
            if email_domain in ignore_domains:
                logger.debug(f"Skipping user with ignored email domain: {username}")
                return False

        # Option 1: Alle User syncen
        if self.config.get_bool("SYNC_ALL_USERS"):
            return True

        # Option 2: User mit bestimmtem Attribut
        sync_attribute = self.config.get("SYNC_USERS_WITH_ATTRIBUTE", "")
        if sync_attribute:
            attrs = user.get('attributes', {})
            attr_value = attrs.get(sync_attribute, [None])[0]

            if attr_value is None:
                return False

            # Pruefe auf spezifischen Wert
            allowed_values = self.config.get_list("SYNC_USERS_ATTRIBUTE_VALUE")
            if allowed_values and attr_value not in allowed_values:
                return False

            return True

        # Option 3: User in bestimmten Gruppen (Default)
        sync_groups = self.config.get_list("SYNC_USERS_IN_GROUPS")
        if sync_groups:
            return any(g in sync_groups for g in user_groups)

        # Fallback: Alle User
        return True

    def _should_sync_group_as_course(self, group: Dict) -> bool:
        """
        Entscheidet ob eine Keycloak-Gruppe als Moodle-Kurs synchronisiert werden soll
        """
        group_name = group.get('name', '')
        attrs = group.get('attributes', {})

        # Option 1: Alle Gruppen als Kurse
        if self.config.get_bool("SYNC_ALL_GROUPS_AS_COURSES"):
            return True

        # Option 2: Gruppen mit spezifischem Attribut
        course_attr = self.config.get("COURSE_SYNC_ATTRIBUTE", "moodleCourse")
        if course_attr:
            attr_value = attrs.get(course_attr, ['false'])[0]
            if attr_value.lower() == 'true':
                return True

        # Option 3: Gruppen matching Pattern
        pattern = self.config.get("COURSE_SYNC_GROUP_PATTERN", "")
        if pattern:
            try:
                if re.match(pattern, group_name):
                    return True
            except re.error:
                logger.warning(f"Invalid regex pattern: {pattern}")

        # Option 4: Gruppen unter bestimmter Parent-Gruppe
        parent_group = self.config.get("COURSE_SYNC_PARENT_GROUP", "")
        if parent_group:
            group_path = group.get('path', '')
            if group_path.startswith(f"/{parent_group}/"):
                return True

        return False

    def _load_keycloak_users(self) -> Dict[str, Dict]:
        """Laedt alle relevanten User aus Keycloak basierend auf Konfiguration"""
        logger.info("  Loading users from Keycloak...")

        users = {}
        max_users = self.config.get_int("MAX_USERS_PER_CYCLE", 0)

        for user in self.keycloak.get_users(max_count=max_users if max_users > 0 else None):
            # Gruppen des Users laden
            user_groups = self.keycloak.get_user_groups(user['id'])
            group_names = [g['name'] for g in user_groups]

            # Pruefen ob User synchronisiert werden soll
            if self._should_sync_user(user, group_names):
                user['groups'] = group_names
                user['moodle_role'] = self._determine_moodle_role(user, group_names)
                users[user['username']] = user

                # In Storage speichern
                self.user_storage.store(user)

                if max_users > 0 and len(users) >= max_users:
                    logger.warning(f"Reached MAX_USERS_PER_CYCLE limit: {max_users}")
                    break

        logger.info(f"  Loaded {len(users)} users from Keycloak")
        return users

    def _load_keycloak_groups(self) -> List[Dict]:
        """Laedt alle Gruppen die als Kurse synchronisiert werden sollen"""
        logger.info("  Loading groups from Keycloak...")

        groups = []
        max_courses = self.config.get_int("MAX_COURSES_PER_CYCLE", 0)

        for group in self.keycloak.get_groups():
            if self._should_sync_group_as_course(group):
                group['members'] = self.keycloak.get_group_members(group['id'])
                groups.append(group)

                if max_courses > 0 and len(groups) >= max_courses:
                    logger.warning(f"Reached MAX_COURSES_PER_CYCLE limit: {max_courses}")
                    break

        logger.info(f"  Loaded {len(groups)} course groups from Keycloak")
        return groups

    def _load_moodle_users(self) -> Dict[str, Dict]:
        """Laedt alle verwalteten User aus Moodle"""
        logger.info("  Loading users from Moodle...")

        users = {}
        for user in self.moosh.user_list():
            username = user.get('username')
            if username:
                users[username] = user

        logger.info(f"  Loaded {len(users)} users from Moodle")
        return users

    def _load_moodle_courses(self) -> Dict[str, Dict]:
        """Laedt alle Kurse aus Moodle"""
        logger.info("  Loading courses from Moodle...")

        courses = {}
        for course in self.moosh.course_list():
            shortname = course.get('shortname')
            if shortname:
                courses[shortname] = course

        logger.info(f"  Loaded {len(courses)} courses from Moodle")
        return courses

    # ==================== ROLE DETERMINATION ====================

    def _determine_moodle_role(self, user: Dict, groups: List[str]) -> Dict[str, Any]:
        """
        Ermittelt die Moodle-Rolle basierend auf Konfiguration
        Unterstuetzt verschiedene Mapping-Modi
        """
        role_mode = self.config.get("ROLE_MAPPING_MODE", "group")

        if role_mode == "attribute":
            return self._role_from_attribute(user)
        elif role_mode == "realm_role":
            return self._role_from_realm_role(user)
        else:  # Default: group
            return self._role_from_groups(groups)

    def _role_from_groups(self, groups: List[str]) -> Dict[str, Any]:
        """Ermittelt Rolle aus Gruppenzugehoerigkeit"""
        best_role = {
            "moodle_role": "student",
            "context": "course",
            "priority": 0
        }

        for group in groups:
            if group in self.role_mappings:
                mapping = self.role_mappings[group]
                if mapping.get("priority", 0) > best_role["priority"]:
                    best_role = mapping.copy()

        return best_role

    def _role_from_attribute(self, user: Dict) -> Dict[str, Any]:
        """Ermittelt Rolle aus User-Attribut"""
        attr_name = self.config.get("ROLE_MAPPING_ATTRIBUTE", "sophomorixRole")
        attrs = user.get('attributes', {})
        attr_value = attrs.get(attr_name, [None])[0]

        if attr_value is None:
            return {"moodle_role": "student", "context": "course", "priority": 0}

        # Attribute-Value-Mappings pruefen
        for mapping in self.config.get("CUSTOM_ROLE_MAPPINGS", []):
            if mapping.get("keycloak_attribute") == attr_name:
                if mapping.get("attribute_value") == attr_value:
                    return {
                        "moodle_role": mapping.get("moodle_role", "student"),
                        "context": mapping.get("context", "course"),
                        "priority": mapping.get("priority", 10)
                    }

        # Fallback: Standard-Mappings aus ENV
        student_values = self.config.get_list("ROLE_ATTRIBUTE_MAP_STUDENT")
        teacher_values = self.config.get_list("ROLE_ATTRIBUTE_MAP_TEACHER")
        admin_values = self.config.get_list("ROLE_ATTRIBUTE_MAP_ADMIN")

        if attr_value in admin_values:
            return {"moodle_role": "manager", "context": "system", "priority": 30}
        elif attr_value in teacher_values:
            return {"moodle_role": "editingteacher", "context": "course", "priority": 20}
        elif attr_value in student_values:
            return {"moodle_role": "student", "context": "course", "priority": 10}

        return {"moodle_role": "student", "context": "course", "priority": 0}

    def _role_from_realm_role(self, user: Dict) -> Dict[str, Any]:
        """Ermittelt Rolle aus Keycloak Realm-Rollen"""
        realm_roles = user.get('realmRoles', [])

        best_role = {"moodle_role": "student", "context": "course", "priority": 0}

        for role in realm_roles:
            if role in self.role_mappings:
                mapping = self.role_mappings[role]
                if mapping.get("priority", 0) > best_role["priority"]:
                    best_role = mapping.copy()

        return best_role

    # ==================== DELTA CALCULATION ====================

    def _calculate_user_deltas(self, keycloak_users: Dict, moodle_users: Dict):
        """Berechnet User-Aenderungen"""
        keycloak_set = set(keycloak_users.keys())
        moodle_set = set(moodle_users.keys())

        # Neue User
        to_add = keycloak_set - moodle_set
        # Zu aktualisierende User
        to_update = keycloak_set & moodle_set
        # Zu deaktivierende User
        to_disable = moodle_set - keycloak_set

        # Hash-Felder fuer Change Detection
        user_hash_fields = ['email', 'firstName', 'lastName', 'enabled']

        for username in to_add:
            self.user_storage.add_to_queue('add', keycloak_users[username])

        for username in to_update:
            kc_user = keycloak_users[username]

            # Hash-basierte Erkennung wenn aktiviert
            if self.change_detector:
                if not self.change_detector.has_changed('user', username, kc_user, user_hash_fields):
                    continue

            md_user = moodle_users[username]
            if self._user_needs_update(kc_user, md_user):
                self.user_storage.add_to_queue('update', kc_user)

            # Bei Wiedererscheinen: Unmark
            self.deactivation_tracker.unmark(username, 'user')

        for username in to_disable:
            # Geschuetzte User nicht deaktivieren
            protected = self.config.get_list("PROTECTED_USERS")
            if username not in protected:
                self.user_storage.add_to_queue('disable', moodle_users[username])

        add_count = len(self.user_storage.get_queue('add'))
        update_count = len(self.user_storage.get_queue('update'))
        disable_count = len(self.user_storage.get_queue('disable'))

        logger.info(f"  Users: {add_count} to add, {update_count} to update, {disable_count} to disable")

    def _user_needs_update(self, keycloak_user: Dict, moodle_user: Dict) -> bool:
        """Prueft ob ein User aktualisiert werden muss"""
        # Vergleiche relevante Felder
        kc_email = keycloak_user.get('email', '')
        kc_firstname = keycloak_user.get('firstName', '')
        kc_lastname = keycloak_user.get('lastName', '')

        # Fuer vollstaendigen Vergleich muessten wir Moodle-User-Details laden
        # Hier vereinfacht: Immer aktualisieren wenn vorhanden
        # (In Produktion: Detaillierter Feld-Vergleich)
        return True

    def _calculate_course_deltas(self, keycloak_groups: List[Dict], moodle_courses: Dict):
        """Berechnet Kurs-Aenderungen basierend auf Keycloak-Gruppen"""
        for group in keycloak_groups:
            attrs = group.get('attributes', {})
            shortname_attr = self.config.get("COURSE_SHORTNAME_ATTRIBUTE", "courseShortname")
            shortname = attrs.get(shortname_attr, [self._sanitize_shortname(group['name'])])[0]

            if shortname not in moodle_courses:
                self.course_storage.add_to_queue('add', {
                    'shortname': shortname,
                    'fullname': group['name'],
                    'category': int(attrs.get('courseCategory', [self.default_course_category])[0]),
                    'keycloak_group': group
                })

        add_count = len(self.course_storage.get_queue('add'))
        logger.info(f"  Courses: {add_count} to create")

    def _sanitize_shortname(self, name: str) -> str:
        """Erstellt gueltigen Shortname aus Gruppenname"""
        shortname = name.lower()
        shortname = re.sub(r'\s+', '-', shortname)
        shortname = re.sub(r'[^a-z0-9\-]', '', shortname)
        return shortname[:100]

    # ==================== SYNCHRONIZATION ====================

    def _sync_users(self):
        """Synchronisiert User nach Moodle"""
        # Neue User erstellen
        for user in self.user_storage.get_queue('add'):
            username = user['username']
            logger.info(f"  Creating user: {username}")

            # Management-Marker erstellen
            marker = f"{self.user_managed_marker}:{user.get('id', '')}"

            user_id = self.moosh.user_create(
                username=username,
                email=user.get('email', f"{username}@example.com"),
                firstname=user.get('firstName', username),
                lastname=user.get('lastName', ''),
                auth='oauth2',
                idnumber=marker
            )

            if user_id:
                # System-Rolle fuer Admins
                role_info = user.get('moodle_role', {})
                if role_info.get('context') == 'system':
                    moodle_role = role_info.get('moodle_role', 'manager')
                    self.moosh.user_assign_system_role(username, moodle_role)

        # User aktualisieren
        for user in self.user_storage.get_queue('update'):
            username = user['username']
            logger.info(f"  Updating user: {username}")
            self.moosh.user_mod(
                username=username,
                email=user.get('email'),
                firstname=user.get('firstName'),
                lastname=user.get('lastName')
            )

    def _sync_courses(self):
        """Synchronisiert Kurse nach Moodle"""
        for course in self.course_storage.get_queue('add'):
            shortname = course['shortname']
            logger.info(f"  Creating course: {shortname}")

            # Management-Marker
            kc_group = course.get('keycloak_group', {})
            marker = f"{self.course_managed_marker}:{kc_group.get('id', '')}"

            self.moosh.course_create(
                shortname=shortname,
                fullname=course['fullname'],
                category=course['category'],
                idnumber=marker
            )

    def _sync_enrolments(self, keycloak_groups: List[Dict], keycloak_users: Dict):
        """Synchronisiert Kurseinschreibungen"""
        for group in keycloak_groups:
            attrs = group.get('attributes', {})
            shortname_attr = self.config.get("COURSE_SHORTNAME_ATTRIBUTE", "courseShortname")
            shortname = attrs.get(shortname_attr, [self._sanitize_shortname(group['name'])])[0]

            course_id = self.moosh.course_get_id(shortname)
            if not course_id:
                logger.debug(f"  Course {shortname} not found, skipping enrolments")
                continue

            for member in group.get('members', []):
                username = member.get('username')
                if username in keycloak_users:
                    role_info = keycloak_users[username].get('moodle_role', {})
                    role = role_info.get('moodle_role', 'student')

                    # Nur Kurs-Kontext Rollen einschreiben
                    if role_info.get('context', 'course') == 'course':
                        logger.debug(f"  Enrolling {username} in {shortname} as {role}")
                        self.moosh.course_enrol(course_id, username, role)

    def _sync_groups(self, keycloak_groups: List[Dict], keycloak_users: Dict):
        """Synchronisiert Gruppen innerhalb von Kursen"""
        for group in keycloak_groups:
            attrs = group.get('attributes', {})
            shortname_attr = self.config.get("COURSE_SHORTNAME_ATTRIBUTE", "courseShortname")
            shortname = attrs.get(shortname_attr, [self._sanitize_shortname(group['name'])])[0]

            course_id = self.moosh.course_get_id(shortname)
            if not course_id:
                continue

            # Gruppe im Kurs erstellen
            group_name = group['name']
            group_id = self.moosh.group_create(group_name, course_id)

            if group_id:
                # Mitglieder hinzufuegen (nur Students)
                student_role = self.config.get("ROLE_STUDENT_MOODLE", "student")

                for member in group.get('members', []):
                    username = member.get('username')
                    if username in keycloak_users:
                        role_info = keycloak_users[username].get('moodle_role', {})
                        if role_info.get('moodle_role') == student_role:
                            self.moosh.group_memberadd(
                                group_id=group_id,
                                username=username,
                                course_id=course_id
                            )

    def _process_deactivations(self):
        """Verarbeitet Soft-Delete fuer nicht mehr vorhandene User"""
        for user in self.user_storage.get_queue('disable'):
            username = user.get('username')
            if not username:
                continue

            # Soft-Delete-Logik
            self.deactivation_tracker.mark(username, 'user', {'username': username})

            if self.deactivation_tracker.should_deactivate(username, 'user'):
                logger.info(f"  Deactivating user: {username}")
                self.moosh.user_suspend(username)
                self.deactivation_tracker.mark_deactivated(username, 'user')

            if self.deactivation_tracker.should_delete(username, 'user'):
                logger.info(f"  Deleting user: {username}")
                self.moosh.user_delete(username)
                self.deactivation_tracker.mark_deleted(username, 'user')

                # Aus Hash-Cache entfernen
                if self.change_detector:
                    self.change_detector.mark_deleted('user', username)

    # ==================== UTILITIES ====================

    def _log_planned_changes(self):
        """Loggt geplante Aenderungen im Dry-Run-Modus"""
        logger.info("=" * 60)
        logger.info("PLANNED CHANGES (DRY RUN)")
        logger.info("=" * 60)

        add_users = self.user_storage.get_queue('add')
        update_users = self.user_storage.get_queue('update')
        disable_users = self.user_storage.get_queue('disable')

        logger.info(f"Users to ADD: {len(add_users)}")
        for u in add_users[:10]:
            logger.info(f"  + {u.get('username')} ({u.get('email')})")
        if len(add_users) > 10:
            logger.info(f"  ... and {len(add_users) - 10} more")

        logger.info(f"Users to UPDATE: {len(update_users)}")
        for u in update_users[:10]:
            logger.info(f"  ~ {u.get('username')}")
        if len(update_users) > 10:
            logger.info(f"  ... and {len(update_users) - 10} more")

        logger.info(f"Users to DISABLE: {len(disable_users)}")
        for u in disable_users[:10]:
            logger.info(f"  - {u.get('username')}")
        if len(disable_users) > 10:
            logger.info(f"  ... and {len(disable_users) - 10} more")

        add_courses = self.course_storage.get_queue('add')
        logger.info(f"Courses to CREATE: {len(add_courses)}")
        for c in add_courses[:10]:
            logger.info(f"  + {c.get('shortname')}: {c.get('fullname')}")
        if len(add_courses) > 10:
            logger.info(f"  ... and {len(add_courses) - 10} more")

    def _notify_error(self, error: Exception):
        """Benachrichtigt bei Fehlern"""
        if self.config.get_bool("ERROR_NOTIFY_ENABLED"):
            email = self.config.get("ERROR_NOTIFY_EMAIL")
            if email:
                logger.info(f"Would send error notification to: {email}")
                # TODO: Implement email notification

        webhook_url = self.config.get("WEBHOOK_URL")
        if webhook_url:
            logger.info(f"Would send webhook notification to: {webhook_url}")
            # TODO: Implement webhook notification

    def get_statistics(self) -> Dict[str, Any]:
        """Gibt Statistiken ueber den letzten Sync zurueck"""
        return {
            'users': self.user_storage.get_statistics(),
            'courses': self.course_storage.get_statistics(),
            'groups': self.group_storage.get_statistics(),
            'deactivation': self.deactivation_tracker.get_statistics()
        }


def main():
    """Hauptfunktion - CLI Interface"""
    parser = argparse.ArgumentParser(
        description='Edulution Moodle Sync - Synchronizes users and courses from Keycloak to Moodle',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python sync.py                 Start continuous sync loop
  python sync.py --single-run    Run sync once and exit
  python sync.py --dry-run       Show what would be changed
  python sync.py --full          Force full sync (ignore hash cache)
        """
    )

    parser.add_argument(
        '--single-run',
        action='store_true',
        help='Run sync once and exit (no loop)'
    )

    parser.add_argument(
        '--full',
        action='store_true',
        help='Force full sync, ignore hash cache'
    )

    parser.add_argument(
        '--dry-run',
        action='store_true',
        help='Show what would be changed without making changes'
    )

    parser.add_argument(
        '--debug',
        action='store_true',
        help='Enable debug logging'
    )

    parser.add_argument(
        '--config',
        type=str,
        help='Path to override config file'
    )

    args = parser.parse_args()

    # Konfiguration erstellen
    config = ConfigurationStorage(override_file=args.config) if args.config else ConfigurationStorage()

    # CLI-Argumente ueberschreiben Config
    if args.dry_run:
        config.set("DRY_RUN", True)

    if args.debug:
        config.set("LOG_LEVEL", "DEBUG")
        logging.getLogger().setLevel(logging.DEBUG)

    # Sync initialisieren
    sync = MoodleSync(config=config)

    # Sync ausfuehren
    if args.single_run:
        logger.info("Running single sync cycle...")
        success = sync.run_sync(full_sync=args.full)
        sys.exit(0 if success else 1)
    else:
        # Continuous loop
        interval = config.get_int("SYNC_INTERVAL", 300)
        retry_interval = config.get_int("RETRY_INTERVAL", 60)

        logger.info(f"Starting sync loop with {interval}s interval")

        while True:
            try:
                success = sync.run_sync(full_sync=args.full)
                args.full = False  # Nur erster Durchlauf als full sync

                if success:
                    logger.info(f"Next sync in {interval} seconds...")
                    time.sleep(interval)
                else:
                    logger.warning(f"Sync failed or disabled, retrying in {retry_interval} seconds...")
                    time.sleep(retry_interval)

            except KeyboardInterrupt:
                logger.info("Sync stopped by user")
                break
            except Exception as e:
                logger.error(f"Unexpected error in sync loop: {e}", exc_info=True)
                logger.info(f"Retrying in {retry_interval} seconds...")
                time.sleep(retry_interval)


if __name__ == "__main__":
    main()
