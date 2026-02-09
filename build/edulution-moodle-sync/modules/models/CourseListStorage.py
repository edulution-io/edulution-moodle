"""
Course List Storage - Verwaltet Kurs-Daten und Sync-Queues

Speichert Kurs-Informationen und verwaltet die Queues fuer
add/update/disable Operationen.
"""

import logging
from typing import Dict, List, Optional, Any

from .ListStorage import ListStorage

logger = logging.getLogger(__name__)


class CourseListStorage(ListStorage):
    """
    Storage-Klasse fuer Kurs-Daten

    Erweitert ListStorage um kurs-spezifische Funktionalitaet:
    - Lookup nach Shortname, Keycloak-Group-ID
    - Kategorie-Zuordnung
    - Einschreibungs-Tracking
    """

    def __init__(self):
        """Initialisiert den Course Storage"""
        super().__init__()
        self._by_keycloak_group: Dict[str, str] = {}  # kc_group_id -> shortname
        self._categories: Dict[str, int] = {}  # shortname -> category_id
        self._enrolments: Dict[str, List[Dict]] = {}  # shortname -> list of enrolments
        self._groups: Dict[str, List[Dict]] = {}  # shortname -> list of groups in course

    def get_identifier(self, item: Dict) -> str:
        """
        Gibt den Shortname als Identifier zurueck

        Args:
            item: Course-Dictionary

        Returns:
            Shortname als Identifier
        """
        return item.get('shortname', '')

    def store(self, item: Dict):
        """
        Speichert einen Kurs mit Indizes

        Args:
            item: Course-Dictionary mit mindestens 'shortname'
        """
        super().store(item)

        shortname = self.get_identifier(item)

        # Keycloak-Group-ID-Index
        kc_group = item.get('keycloak_group', {})
        kc_group_id = kc_group.get('id') if isinstance(kc_group, dict) else None
        if kc_group_id:
            self._by_keycloak_group[kc_group_id] = shortname

        # Kategorie
        category = item.get('category')
        if category:
            self._categories[shortname] = int(category)

    def get_by_keycloak_group(self, kc_group_id: str) -> Optional[Dict]:
        """
        Sucht Kurs nach Keycloak-Group-ID

        Args:
            kc_group_id: Keycloak Group-ID

        Returns:
            Course-Dictionary oder None
        """
        shortname = self._by_keycloak_group.get(kc_group_id)
        if shortname:
            return self.get(shortname)
        return None

    def get_category(self, shortname: str) -> Optional[int]:
        """
        Gibt die Kategorie-ID eines Kurses zurueck

        Args:
            shortname: Kurs-Shortname

        Returns:
            Kategorie-ID oder None
        """
        return self._categories.get(shortname)

    def set_category(self, shortname: str, category_id: int):
        """
        Setzt die Kategorie eines Kurses

        Args:
            shortname: Kurs-Shortname
            category_id: Kategorie-ID
        """
        self._categories[shortname] = category_id

    def get_courses_in_category(self, category_id: int) -> List[str]:
        """
        Gibt alle Kurse einer Kategorie zurueck

        Args:
            category_id: Kategorie-ID

        Returns:
            Liste der Shortnames
        """
        return [
            shortname for shortname, cat_id in self._categories.items()
            if cat_id == category_id
        ]

    def add_enrolment(self, shortname: str, enrolment: Dict):
        """
        Fuegt eine Einschreibung zu einem Kurs hinzu

        Args:
            shortname: Kurs-Shortname
            enrolment: Einschreibungs-Dictionary (username, role, etc.)
        """
        if shortname not in self._enrolments:
            self._enrolments[shortname] = []
        self._enrolments[shortname].append(enrolment)

    def get_enrolments(self, shortname: str) -> List[Dict]:
        """
        Gibt alle Einschreibungen eines Kurses zurueck

        Args:
            shortname: Kurs-Shortname

        Returns:
            Liste der Einschreibungen
        """
        return self._enrolments.get(shortname, [])

    def clear_enrolments(self, shortname: str):
        """
        Loescht alle Einschreibungen eines Kurses

        Args:
            shortname: Kurs-Shortname
        """
        if shortname in self._enrolments:
            self._enrolments[shortname] = []

    def is_user_enrolled(self, shortname: str, username: str) -> bool:
        """
        Prueft ob ein User in einem Kurs eingeschrieben ist

        Args:
            shortname: Kurs-Shortname
            username: Username

        Returns:
            True wenn eingeschrieben
        """
        for enrolment in self.get_enrolments(shortname):
            if enrolment.get('username') == username:
                return True
        return False

    def get_user_role_in_course(self, shortname: str, username: str) -> Optional[str]:
        """
        Gibt die Rolle eines Users in einem Kurs zurueck

        Args:
            shortname: Kurs-Shortname
            username: Username

        Returns:
            Rollenname oder None
        """
        for enrolment in self.get_enrolments(shortname):
            if enrolment.get('username') == username:
                return enrolment.get('role')
        return None

    def add_course_group(self, shortname: str, group: Dict):
        """
        Fuegt eine Gruppe zu einem Kurs hinzu

        Args:
            shortname: Kurs-Shortname
            group: Gruppen-Dictionary (name, id, members, etc.)
        """
        if shortname not in self._groups:
            self._groups[shortname] = []
        self._groups[shortname].append(group)

    def get_course_groups(self, shortname: str) -> List[Dict]:
        """
        Gibt alle Gruppen eines Kurses zurueck

        Args:
            shortname: Kurs-Shortname

        Returns:
            Liste der Gruppen
        """
        return self._groups.get(shortname, [])

    def remove(self, identifier: str):
        """
        Entfernt einen Kurs komplett

        Args:
            identifier: Shortname
        """
        course = self.get(identifier)
        if course:
            # Keycloak-Group-Index entfernen
            kc_group = course.get('keycloak_group', {})
            kc_group_id = kc_group.get('id') if isinstance(kc_group, dict) else None
            if kc_group_id and kc_group_id in self._by_keycloak_group:
                del self._by_keycloak_group[kc_group_id]

            # Kategorie entfernen
            if identifier in self._categories:
                del self._categories[identifier]

            # Einschreibungen entfernen
            if identifier in self._enrolments:
                del self._enrolments[identifier]

            # Gruppen entfernen
            if identifier in self._groups:
                del self._groups[identifier]

        super().remove(identifier)

    def clear_all_queues(self):
        """Leert alle Queues und Indizes"""
        super().clear_all_queues()
        self._by_keycloak_group.clear()
        self._categories.clear()
        self._enrolments.clear()
        self._groups.clear()

    def get_statistics(self) -> Dict[str, Any]:
        """
        Gibt erweiterte Statistiken zurueck

        Returns:
            Dictionary mit Statistiken
        """
        stats = super().get_statistics()

        total_enrolments = sum(len(e) for e in self._enrolments.values())
        total_groups = sum(len(g) for g in self._groups.values())

        stats.update({
            'courses_with_enrolments': len(self._enrolments),
            'total_enrolments': total_enrolments,
            'courses_with_groups': len(self._groups),
            'total_course_groups': total_groups,
            'linked_keycloak_groups': len(self._by_keycloak_group)
        })
        return stats

    def to_sync_format(self, shortname: str) -> Optional[Dict]:
        """
        Konvertiert Kurs-Daten in Sync-Format

        Args:
            shortname: Kurs-Shortname

        Returns:
            Dictionary im Sync-Format oder None
        """
        course = self.get(shortname)
        if not course:
            return None

        return {
            'shortname': shortname,
            'fullname': course.get('fullname', shortname),
            'category': self.get_category(shortname),
            'keycloak_group_id': course.get('keycloak_group', {}).get('id'),
            'enrolments': self.get_enrolments(shortname),
            'groups': self.get_course_groups(shortname),
        }
