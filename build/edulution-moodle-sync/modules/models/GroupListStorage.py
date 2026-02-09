"""
Group List Storage - Verwaltet Gruppen-Daten und Sync-Queues

Speichert Gruppen-Informationen (Moodle-Gruppen innerhalb von Kursen)
und verwaltet die Queues fuer add/update/disable Operationen.
"""

import logging
from typing import Dict, List, Optional, Any, Tuple

from .ListStorage import ListStorage

logger = logging.getLogger(__name__)


class GroupListStorage(ListStorage):
    """
    Storage-Klasse fuer Gruppen-Daten (Gruppen innerhalb von Moodle-Kursen)

    Erweitert ListStorage um gruppen-spezifische Funktionalitaet:
    - Lookup nach Gruppenname und Kurs
    - Mitglieder-Tracking
    - Keycloak-Subgroup-Zuordnung
    """

    def __init__(self):
        """Initialisiert den Group Storage"""
        super().__init__()
        # Index: (course_shortname, group_name) -> composite_key
        self._by_course_and_name: Dict[Tuple[str, str], str] = {}
        # Index: keycloak_subgroup_id -> composite_key
        self._by_keycloak_subgroup: Dict[str, str] = {}
        # Mitglieder: composite_key -> list of usernames
        self._members: Dict[str, List[str]] = {}
        # Kurs-Index: course_shortname -> list of composite_keys
        self._by_course: Dict[str, List[str]] = {}

    def _make_composite_key(self, course_shortname: str, group_name: str) -> str:
        """
        Erstellt einen zusammengesetzten Schluessel

        Args:
            course_shortname: Kurs-Shortname
            group_name: Gruppenname

        Returns:
            Zusammengesetzter Schluessel
        """
        return f"{course_shortname}::{group_name}"

    def _parse_composite_key(self, key: str) -> Tuple[str, str]:
        """
        Parst einen zusammengesetzten Schluessel

        Args:
            key: Zusammengesetzter Schluessel

        Returns:
            Tuple (course_shortname, group_name)
        """
        parts = key.split('::', 1)
        if len(parts) == 2:
            return (parts[0], parts[1])
        return ('', key)

    def get_identifier(self, item: Dict) -> str:
        """
        Gibt den zusammengesetzten Identifier zurueck

        Args:
            item: Group-Dictionary

        Returns:
            Zusammengesetzter Identifier (course::groupname)
        """
        course = item.get('course_shortname', item.get('course', ''))
        name = item.get('name', item.get('groupname', ''))
        return self._make_composite_key(course, name)

    def store(self, item: Dict):
        """
        Speichert eine Gruppe mit Indizes

        Args:
            item: Group-Dictionary mit 'course_shortname' und 'name'
        """
        super().store(item)

        composite_key = self.get_identifier(item)
        course_shortname = item.get('course_shortname', item.get('course', ''))
        group_name = item.get('name', item.get('groupname', ''))

        # Index: (course, name) -> key
        self._by_course_and_name[(course_shortname, group_name)] = composite_key

        # Index: keycloak_subgroup_id -> key
        kc_subgroup_id = item.get('keycloak_subgroup_id')
        if kc_subgroup_id:
            self._by_keycloak_subgroup[kc_subgroup_id] = composite_key

        # Index: course -> keys
        if course_shortname not in self._by_course:
            self._by_course[course_shortname] = []
        if composite_key not in self._by_course[course_shortname]:
            self._by_course[course_shortname].append(composite_key)

        # Mitglieder initialisieren
        members = item.get('members', [])
        if members:
            self._members[composite_key] = members

    def get_by_course_and_name(self, course_shortname: str, group_name: str) -> Optional[Dict]:
        """
        Sucht Gruppe nach Kurs und Name

        Args:
            course_shortname: Kurs-Shortname
            group_name: Gruppenname

        Returns:
            Group-Dictionary oder None
        """
        composite_key = self._by_course_and_name.get((course_shortname, group_name))
        if composite_key:
            return self.get(composite_key)
        return None

    def get_by_keycloak_subgroup(self, kc_subgroup_id: str) -> Optional[Dict]:
        """
        Sucht Gruppe nach Keycloak-Subgroup-ID

        Args:
            kc_subgroup_id: Keycloak Subgroup-ID

        Returns:
            Group-Dictionary oder None
        """
        composite_key = self._by_keycloak_subgroup.get(kc_subgroup_id)
        if composite_key:
            return self.get(composite_key)
        return None

    def get_groups_in_course(self, course_shortname: str) -> List[Dict]:
        """
        Gibt alle Gruppen eines Kurses zurueck

        Args:
            course_shortname: Kurs-Shortname

        Returns:
            Liste der Gruppen-Dictionaries
        """
        keys = self._by_course.get(course_shortname, [])
        return [self.get(key) for key in keys if self.get(key)]

    def get_group_names_in_course(self, course_shortname: str) -> List[str]:
        """
        Gibt alle Gruppennamen eines Kurses zurueck

        Args:
            course_shortname: Kurs-Shortname

        Returns:
            Liste der Gruppennamen
        """
        groups = self.get_groups_in_course(course_shortname)
        return [g.get('name', g.get('groupname', '')) for g in groups]

    def add_member(self, course_shortname: str, group_name: str, username: str):
        """
        Fuegt ein Mitglied zu einer Gruppe hinzu

        Args:
            course_shortname: Kurs-Shortname
            group_name: Gruppenname
            username: Username des neuen Mitglieds
        """
        composite_key = self._make_composite_key(course_shortname, group_name)
        if composite_key not in self._members:
            self._members[composite_key] = []
        if username not in self._members[composite_key]:
            self._members[composite_key].append(username)

    def remove_member(self, course_shortname: str, group_name: str, username: str):
        """
        Entfernt ein Mitglied aus einer Gruppe

        Args:
            course_shortname: Kurs-Shortname
            group_name: Gruppenname
            username: Username des zu entfernenden Mitglieds
        """
        composite_key = self._make_composite_key(course_shortname, group_name)
        if composite_key in self._members:
            self._members[composite_key] = [
                u for u in self._members[composite_key] if u != username
            ]

    def get_members(self, course_shortname: str, group_name: str) -> List[str]:
        """
        Gibt alle Mitglieder einer Gruppe zurueck

        Args:
            course_shortname: Kurs-Shortname
            group_name: Gruppenname

        Returns:
            Liste der Usernames
        """
        composite_key = self._make_composite_key(course_shortname, group_name)
        return self._members.get(composite_key, [])

    def set_members(self, course_shortname: str, group_name: str, members: List[str]):
        """
        Setzt die Mitgliederliste einer Gruppe

        Args:
            course_shortname: Kurs-Shortname
            group_name: Gruppenname
            members: Liste der Usernames
        """
        composite_key = self._make_composite_key(course_shortname, group_name)
        self._members[composite_key] = members

    def is_member(self, course_shortname: str, group_name: str, username: str) -> bool:
        """
        Prueft ob ein User Mitglied einer Gruppe ist

        Args:
            course_shortname: Kurs-Shortname
            group_name: Gruppenname
            username: Username

        Returns:
            True wenn Mitglied
        """
        return username in self.get_members(course_shortname, group_name)

    def get_user_groups_in_course(self, course_shortname: str, username: str) -> List[str]:
        """
        Gibt alle Gruppen eines Users in einem Kurs zurueck

        Args:
            course_shortname: Kurs-Shortname
            username: Username

        Returns:
            Liste der Gruppennamen
        """
        result = []
        for group_name in self.get_group_names_in_course(course_shortname):
            if self.is_member(course_shortname, group_name, username):
                result.append(group_name)
        return result

    def remove(self, identifier: str):
        """
        Entfernt eine Gruppe komplett

        Args:
            identifier: Zusammengesetzter Schluessel (course::groupname)
        """
        group = self.get(identifier)
        if group:
            course_shortname, group_name = self._parse_composite_key(identifier)

            # Index (course, name) entfernen
            key = (course_shortname, group_name)
            if key in self._by_course_and_name:
                del self._by_course_and_name[key]

            # Keycloak-Index entfernen
            kc_subgroup_id = group.get('keycloak_subgroup_id')
            if kc_subgroup_id and kc_subgroup_id in self._by_keycloak_subgroup:
                del self._by_keycloak_subgroup[kc_subgroup_id]

            # Kurs-Index aktualisieren
            if course_shortname in self._by_course:
                self._by_course[course_shortname] = [
                    k for k in self._by_course[course_shortname] if k != identifier
                ]

            # Mitglieder entfernen
            if identifier in self._members:
                del self._members[identifier]

        super().remove(identifier)

    def clear_all_queues(self):
        """Leert alle Queues und Indizes"""
        super().clear_all_queues()
        self._by_course_and_name.clear()
        self._by_keycloak_subgroup.clear()
        self._members.clear()
        self._by_course.clear()

    def get_statistics(self) -> Dict[str, Any]:
        """
        Gibt erweiterte Statistiken zurueck

        Returns:
            Dictionary mit Statistiken
        """
        stats = super().get_statistics()

        total_members = sum(len(m) for m in self._members.values())

        stats.update({
            'courses_with_groups': len(self._by_course),
            'total_group_memberships': total_members,
            'linked_keycloak_subgroups': len(self._by_keycloak_subgroup)
        })
        return stats

    def to_sync_format(self, course_shortname: str, group_name: str) -> Optional[Dict]:
        """
        Konvertiert Gruppen-Daten in Sync-Format

        Args:
            course_shortname: Kurs-Shortname
            group_name: Gruppenname

        Returns:
            Dictionary im Sync-Format oder None
        """
        group = self.get_by_course_and_name(course_shortname, group_name)
        if not group:
            return None

        return {
            'course_shortname': course_shortname,
            'name': group_name,
            'moodle_id': group.get('id'),
            'keycloak_subgroup_id': group.get('keycloak_subgroup_id'),
            'members': self.get_members(course_shortname, group_name),
        }
