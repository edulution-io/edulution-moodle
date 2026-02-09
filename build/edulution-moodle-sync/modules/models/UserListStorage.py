"""
User List Storage - Verwaltet User-Daten und Sync-Queues

Speichert User-Informationen und verwaltet die Queues fuer
add/update/disable Operationen.
"""

import logging
from typing import Dict, List, Optional, Any

from .ListStorage import ListStorage

logger = logging.getLogger(__name__)


class UserListStorage(ListStorage):
    """
    Storage-Klasse fuer User-Daten

    Erweitert ListStorage um user-spezifische Funktionalitaet:
    - Lookup nach Username, Email, Keycloak-ID
    - Rollen-Tracking
    - Gruppen-Zuordnung
    """

    def __init__(self):
        """Initialisiert den User Storage"""
        super().__init__()
        self._by_email: Dict[str, str] = {}  # email -> username
        self._by_keycloak_id: Dict[str, str] = {}  # kc_id -> username
        self._roles: Dict[str, Dict] = {}  # username -> role info
        self._groups: Dict[str, List[str]] = {}  # username -> group list

    def get_identifier(self, item: Dict) -> str:
        """
        Gibt den Username als Identifier zurueck

        Args:
            item: User-Dictionary

        Returns:
            Username als Identifier
        """
        return item.get('username', '')

    def store(self, item: Dict):
        """
        Speichert einen User mit Indizes

        Args:
            item: User-Dictionary mit mindestens 'username'
        """
        super().store(item)

        username = self.get_identifier(item)

        # Email-Index
        email = item.get('email')
        if email:
            self._by_email[email.lower()] = username

        # Keycloak-ID-Index
        kc_id = item.get('id') or item.get('keycloak_id')
        if kc_id:
            self._by_keycloak_id[kc_id] = username

        # Rollen-Info
        role_info = item.get('moodle_role')
        if role_info:
            self._roles[username] = role_info

        # Gruppen
        groups = item.get('groups', [])
        if groups:
            self._groups[username] = groups

    def get_by_email(self, email: str) -> Optional[Dict]:
        """
        Sucht User nach Email

        Args:
            email: Email-Adresse

        Returns:
            User-Dictionary oder None
        """
        username = self._by_email.get(email.lower())
        if username:
            return self.get(username)
        return None

    def get_by_keycloak_id(self, kc_id: str) -> Optional[Dict]:
        """
        Sucht User nach Keycloak-ID

        Args:
            kc_id: Keycloak User-ID

        Returns:
            User-Dictionary oder None
        """
        username = self._by_keycloak_id.get(kc_id)
        if username:
            return self.get(username)
        return None

    def get_role(self, username: str) -> Optional[Dict]:
        """
        Gibt die Rollen-Info eines Users zurueck

        Args:
            username: Username

        Returns:
            Rollen-Dictionary oder None
        """
        return self._roles.get(username)

    def set_role(self, username: str, role_info: Dict):
        """
        Setzt die Rollen-Info eines Users

        Args:
            username: Username
            role_info: Rollen-Dictionary
        """
        self._roles[username] = role_info

    def get_groups(self, username: str) -> List[str]:
        """
        Gibt die Gruppen eines Users zurueck

        Args:
            username: Username

        Returns:
            Liste der Gruppennamen
        """
        return self._groups.get(username, [])

    def set_groups(self, username: str, groups: List[str]):
        """
        Setzt die Gruppen eines Users

        Args:
            username: Username
            groups: Liste der Gruppennamen
        """
        self._groups[username] = groups

    def is_in_group(self, username: str, group: str) -> bool:
        """
        Prueft ob User in einer Gruppe ist

        Args:
            username: Username
            group: Gruppenname

        Returns:
            True wenn User in Gruppe
        """
        return group in self.get_groups(username)

    def get_users_in_group(self, group: str) -> List[str]:
        """
        Gibt alle User einer Gruppe zurueck

        Args:
            group: Gruppenname

        Returns:
            Liste der Usernames
        """
        return [
            username for username, groups in self._groups.items()
            if group in groups
        ]

    def get_users_with_role(self, role: str) -> List[str]:
        """
        Gibt alle User mit einer bestimmten Rolle zurueck

        Args:
            role: Moodle-Rollenname

        Returns:
            Liste der Usernames
        """
        return [
            username for username, role_info in self._roles.items()
            if role_info.get('moodle_role') == role
        ]

    def remove(self, identifier: str):
        """
        Entfernt einen User komplett

        Args:
            identifier: Username
        """
        user = self.get(identifier)
        if user:
            # Email-Index entfernen
            email = user.get('email')
            if email and email.lower() in self._by_email:
                del self._by_email[email.lower()]

            # Keycloak-ID-Index entfernen
            kc_id = user.get('id') or user.get('keycloak_id')
            if kc_id and kc_id in self._by_keycloak_id:
                del self._by_keycloak_id[kc_id]

            # Rollen entfernen
            if identifier in self._roles:
                del self._roles[identifier]

            # Gruppen entfernen
            if identifier in self._groups:
                del self._groups[identifier]

        super().remove(identifier)

    def clear_all_queues(self):
        """Leert alle Queues und Indizes"""
        super().clear_all_queues()
        self._by_email.clear()
        self._by_keycloak_id.clear()
        self._roles.clear()
        self._groups.clear()

    def get_statistics(self) -> Dict[str, Any]:
        """
        Gibt erweiterte Statistiken zurueck

        Returns:
            Dictionary mit Statistiken
        """
        stats = super().get_statistics()
        stats.update({
            'users_with_roles': len(self._roles),
            'users_with_groups': len(self._groups),
            'unique_emails': len(self._by_email),
            'linked_keycloak_ids': len(self._by_keycloak_id)
        })
        return stats

    def to_sync_format(self, username: str) -> Optional[Dict]:
        """
        Konvertiert User-Daten in Sync-Format

        Args:
            username: Username

        Returns:
            Dictionary im Sync-Format oder None
        """
        user = self.get(username)
        if not user:
            return None

        return {
            'username': username,
            'email': user.get('email', ''),
            'firstname': user.get('firstName', user.get('firstname', '')),
            'lastname': user.get('lastName', user.get('lastname', '')),
            'keycloak_id': user.get('id', ''),
            'enabled': user.get('enabled', True),
            'groups': self.get_groups(username),
            'role': self.get_role(username),
        }
