"""
Keycloak API Integration

Basierend auf python-keycloak fuer Admin-Operationen und
Token-Validierung. Implementiert Retry-Logik mit exponentiellem
Backoff wie in edulution-mail.
"""

import os
import time
import logging
from typing import List, Dict, Optional

from keycloak import KeycloakAdmin, KeycloakOpenID
from keycloak.exceptions import KeycloakError

logger = logging.getLogger(__name__)

# Retry-Konfiguration (wie edulution-mail)
MAX_RETRIES = 6
RETRY_BASE_DELAY = 10  # Sekunden


class KeycloakClient:
    """
    Keycloak Admin Client fuer User- und Gruppen-Management

    Verwendet python-keycloak fuer die API-Kommunikation.
    Unterstuetzt Pagination fuer grosse Datenmengen.
    """

    def __init__(
        self,
        server_url: str = None,
        realm: str = None,
        client_id: str = None,
        client_secret: str = None,
        verify_ssl: bool = True,
        page_size: int = 50
    ):
        """
        Initialisiert den Keycloak Client

        Args:
            server_url: Keycloak Server URL (z.B. https://keycloak.example.com/auth/)
            realm: Keycloak Realm Name
            client_id: Client ID fuer Service Account
            client_secret: Client Secret
            verify_ssl: SSL-Zertifikate pruefen
            page_size: Anzahl Eintraege pro API-Aufruf
        """
        self.server_url = server_url or os.getenv(
            "KEYCLOAK_SERVER_URL",
            "https://keycloak.example.com/auth/"
        )
        self.realm = realm or os.getenv("KEYCLOAK_REALM", "edulution")
        self.client_id = client_id or os.getenv("KEYCLOAK_CLIENT_ID", "edu-moodle-sync")
        self.client_secret = client_secret or os.getenv("KEYCLOAK_SECRET_KEY", "")
        self.verify_ssl = verify_ssl if verify_ssl is not None else os.getenv(
            "KEYCLOAK_VERIFY_SSL", "1"
        ).lower() in ('1', 'true', 'yes')
        self.page_size = page_size

        self._admin: Optional[KeycloakAdmin] = None
        self._connect()

    def _connect(self):
        """Stellt Verbindung zu Keycloak her"""
        try:
            self._admin = KeycloakAdmin(
                server_url=self.server_url,
                realm_name=self.realm,
                client_id=self.client_id,
                client_secret_key=self.client_secret,
                verify=self.verify_ssl
            )
            logger.info(f"Connected to Keycloak: {self.server_url} (realm: {self.realm})")
        except KeycloakError as e:
            logger.error(f"Failed to connect to Keycloak: {e}")
            raise

    def reconnect(self):
        """Stellt Verbindung neu her (z.B. nach Token-Ablauf)"""
        self._connect()

    # ==================== USER OPERATIONS ====================

    def get_users(self, max_count: int = None, filters: Dict = None) -> List[Dict]:
        """
        Laedt alle Benutzer aus Keycloak mit Pagination und Retry-Logik

        Args:
            max_count: Maximale Anzahl zu ladender User (0 = alle)
            filters: Zusaetzliche Filter (z.B. {'enabled': True})

        Returns:
            Liste aller User-Dictionaries
        """
        logger.info("  * Downloading list of users from keycloak...")

        # Hole Gesamtanzahl zur Validierung
        try:
            users_count = self._admin.users_count()
            logger.info(f"    -> Total users in Keycloak: {users_count}")
        except Exception as e:
            logger.warning(f"    -> Could not get user count: {e}")
            users_count = None

        all_users = []
        first = 0

        while True:
            batch = None

            # Retry-Logik wie in edulution-mail
            for attempt in range(MAX_RETRIES):
                try:
                    query = {
                        "first": first,
                        "max": self.page_size,
                        "briefRepresentation": False
                    }
                    if filters:
                        query.update(filters)

                    batch = self._admin.get_users(query)

                    if batch is not None and isinstance(batch, list):
                        break

                except Exception as e:
                    logger.warning(
                        f"    -> Failed to get users batch at {first} "
                        f"(attempt {attempt + 1}/{MAX_RETRIES}): {e}"
                    )
                    if attempt < MAX_RETRIES - 1:
                        wait_time = (attempt + 1) * RETRY_BASE_DELAY
                        logger.warning(f"    -> Waiting {wait_time}s before retrying...")
                        time.sleep(wait_time)
                    else:
                        raise

            if not batch:
                break

            all_users.extend(batch)
            first += self.page_size

            if max_count and len(all_users) >= max_count:
                all_users = all_users[:max_count]
                break

            logger.debug(f"    -> Loaded {len(all_users)} users so far...")

        # Validierung wie in edulution-mail
        if users_count is not None and users_count != len(all_users):
            logger.warning(
                f"    -> Warning: Expected {users_count} users but "
                f"retrieved {len(all_users)} users"
            )

        logger.info(f"  * Loaded {len(all_users)} users from Keycloak")
        return all_users

    def get_user(self, user_id: str) -> Optional[Dict]:
        """
        Laedt einen einzelnen Benutzer nach ID

        Args:
            user_id: Keycloak User-ID

        Returns:
            User-Dictionary oder None
        """
        try:
            return self._admin.get_user(user_id)
        except KeycloakError as e:
            logger.error(f"Error loading user {user_id}: {e}")
            return None

    def get_user_by_username(self, username: str) -> Optional[Dict]:
        """
        Sucht einen Benutzer nach Username

        Args:
            username: Username

        Returns:
            User-Dictionary oder None
        """
        try:
            users = self._admin.get_users({"username": username, "exact": True})
            return users[0] if users else None
        except KeycloakError as e:
            logger.error(f"Error searching user {username}: {e}")
            return None

    def get_user_groups(self, user_id: str) -> List[Dict]:
        """
        Laedt alle Gruppen eines Benutzers

        Args:
            user_id: Keycloak User-ID

        Returns:
            Liste der Gruppen-Dictionaries
        """
        try:
            return self._admin.get_user_groups(user_id)
        except KeycloakError as e:
            logger.error(f"Error loading groups for user {user_id}: {e}")
            return []

    def get_user_realm_roles(self, user_id: str) -> List[Dict]:
        """
        Laedt die Realm-Rollen eines Benutzers

        Args:
            user_id: Keycloak User-ID

        Returns:
            Liste der Rollen-Dictionaries
        """
        try:
            return self._admin.get_realm_roles_of_user(user_id)
        except KeycloakError as e:
            logger.error(f"Error loading realm roles for user {user_id}: {e}")
            return []

    # ==================== GROUP OPERATIONS ====================

    def get_groups(self, max_count: int = None, search: str = None) -> List[Dict]:
        """
        Laedt alle Gruppen aus Keycloak mit Pagination und Retry-Logik

        Args:
            max_count: Maximale Anzahl zu ladender Gruppen
            search: Optional: Suchbegriff

        Returns:
            Liste aller Gruppen-Dictionaries
        """
        logger.info("  * Downloading list of groups from keycloak...")
        all_groups = []
        first = 0

        while True:
            batch = None

            # Retry-Logik
            for attempt in range(MAX_RETRIES):
                try:
                    query = {
                        "first": first,
                        "max": self.page_size,
                        "briefRepresentation": False
                    }
                    if search:
                        query["search"] = search

                    batch = self._admin.get_groups(query)

                    if batch is not None:
                        break

                except Exception as e:
                    logger.warning(
                        f"    -> Failed to get groups batch at {first} "
                        f"(attempt {attempt + 1}/{MAX_RETRIES}): {e}"
                    )
                    if attempt < MAX_RETRIES - 1:
                        wait_time = (attempt + 1) * RETRY_BASE_DELAY
                        logger.warning(f"    -> Waiting {wait_time}s before retrying...")
                        time.sleep(wait_time)
                    else:
                        raise

            if not batch:
                break

            all_groups.extend(batch)
            first += self.page_size

            if max_count and len(all_groups) >= max_count:
                all_groups = all_groups[:max_count]
                break

        logger.info(f"  * Loaded {len(all_groups)} groups from Keycloak")
        return all_groups

    def get_group(self, group_id: str) -> Optional[Dict]:
        """
        Laedt eine einzelne Gruppe nach ID

        Args:
            group_id: Keycloak Group-ID

        Returns:
            Gruppen-Dictionary oder None
        """
        try:
            return self._admin.get_group(group_id)
        except KeycloakError as e:
            logger.error(f"Error loading group {group_id}: {e}")
            return None

    def get_group_by_path(self, path: str) -> Optional[Dict]:
        """
        Sucht eine Gruppe nach Pfad

        Args:
            path: Gruppenpfad (z.B. '/parent/child')

        Returns:
            Gruppen-Dictionary oder None
        """
        try:
            return self._admin.get_group_by_path(path)
        except KeycloakError as e:
            logger.error(f"Error searching group by path {path}: {e}")
            return None

    def get_group_members(self, group_id: str, group_name: str = None, max_count: int = None) -> List[Dict]:
        """
        Laedt alle Mitglieder einer Gruppe mit Pagination und Retry-Logik

        Args:
            group_id: Keycloak Group-ID
            group_name: Optionaler Gruppenname fuer Logging
            max_count: Maximale Anzahl zu ladender Mitglieder

        Returns:
            Liste der User-Dictionaries
        """
        if group_name:
            logger.debug(f"    -> Loading members for group {group_name}")

        all_members = []
        first = 0

        while True:
            batch = None

            # Retry-Logik
            for attempt in range(MAX_RETRIES):
                try:
                    batch = self._admin.get_group_members(
                        group_id,
                        query={"first": first, "max": self.page_size}
                    )

                    if batch is not None:
                        break

                except Exception as e:
                    logger.warning(
                        f"       -> Failed to get members batch for group "
                        f"(attempt {attempt + 1}/{MAX_RETRIES}): {e}"
                    )
                    if attempt < MAX_RETRIES - 1:
                        wait_time = (attempt + 1) * RETRY_BASE_DELAY
                        time.sleep(wait_time)
                    else:
                        raise

            if not batch:
                break

            all_members.extend(batch)
            first += self.page_size

            if max_count and len(all_members) >= max_count:
                all_members = all_members[:max_count]
                break

        return all_members

    def get_subgroups(self, group_id: str) -> List[Dict]:
        """
        Laedt alle Untergruppen einer Gruppe

        Args:
            group_id: Keycloak Group-ID der Parent-Gruppe

        Returns:
            Liste der Subgruppen-Dictionaries
        """
        try:
            group = self._admin.get_group(group_id)
            return group.get('subGroups', [])
        except KeycloakError as e:
            logger.error(f"Error loading subgroups for {group_id}: {e}")
            return []

    def get_group_attributes(self, group_id: str) -> Dict[str, List[str]]:
        """
        Laedt die Attribute einer Gruppe

        Args:
            group_id: Keycloak Group-ID

        Returns:
            Dictionary der Attribute
        """
        group = self.get_group(group_id)
        if group:
            return group.get('attributes', {})
        return {}

    def check_group_membership(self, user_id: str, valid_groups: List[str]) -> bool:
        """
        Prueft ob ein Benutzer Mitglied einer der angegebenen Gruppen ist.
        Wie checkGroupMembershipForUser in edulution-mail.

        Args:
            user_id: Keycloak User-ID
            valid_groups: Liste erlaubter Gruppennamen

        Returns:
            True wenn User in mindestens einer Gruppe ist
        """
        try:
            groups = self._admin.get_user_groups(user_id)
            for group in groups:
                if group.get("name") in valid_groups:
                    return True
            return False
        except Exception:
            return False

    # ==================== UTILITY METHODS ====================

    def get_all_users_with_groups(self, max_users: int = None) -> List[Dict]:
        """
        Laedt alle User mit ihren Gruppen (kombinierter Aufruf)

        Args:
            max_users: Maximale Anzahl User

        Returns:
            Liste der User mit 'groups' Key
        """
        users = self.get_users(max_count=max_users)

        for user in users:
            user_groups = self.get_user_groups(user['id'])
            user['groups'] = [g['name'] for g in user_groups]
            user['group_details'] = user_groups

        return users

    def get_groups_with_members(self, max_groups: int = None) -> List[Dict]:
        """
        Laedt alle Gruppen mit ihren Mitgliedern (kombinierter Aufruf)

        Args:
            max_groups: Maximale Anzahl Gruppen

        Returns:
            Liste der Gruppen mit 'members' Key
        """
        groups = self.get_groups(max_count=max_groups)

        for group in groups:
            group['members'] = self.get_group_members(group['id'])

        return groups

    def check_connection(self) -> bool:
        """
        Prueft die Verbindung zu Keycloak

        Returns:
            True wenn Verbindung OK
        """
        try:
            self._admin.get_server_info()
            return True
        except Exception as e:
            logger.error(f"Keycloak connection check failed: {e}")
            return False


class KeycloakAuth:
    """
    Keycloak OpenID Connect Client fuer Token-Validierung

    Verwendet fuer SSO-Integration und Token-Pruefung.
    """

    def __init__(
        self,
        server_url: str = None,
        realm: str = None,
        client_id: str = None,
        client_secret: str = None,
        verify_ssl: bool = True
    ):
        """
        Initialisiert den Auth Client

        Args:
            server_url: Keycloak Server URL
            realm: Keycloak Realm Name
            client_id: Client ID
            client_secret: Client Secret
            verify_ssl: SSL-Zertifikate pruefen
        """
        self.server_url = server_url or os.getenv(
            "KEYCLOAK_SERVER_URL",
            "https://keycloak.example.com/auth/"
        )
        self.realm = realm or os.getenv("KEYCLOAK_REALM", "edulution")
        self.client_id = client_id or os.getenv("KEYCLOAK_CLIENT_ID", "edu-moodle-sync")
        self.client_secret = client_secret or os.getenv("KEYCLOAK_SECRET_KEY", "")
        self.verify_ssl = verify_ssl if verify_ssl is not None else os.getenv(
            "KEYCLOAK_VERIFY_SSL", "1"
        ).lower() in ('1', 'true', 'yes')

        self._openid: Optional[KeycloakOpenID] = None
        self._connect()

    def _connect(self):
        """Initialisiert den OpenID Client"""
        try:
            self._openid = KeycloakOpenID(
                server_url=self.server_url,
                realm_name=self.realm,
                client_id=self.client_id,
                client_secret_key=self.client_secret,
                verify=self.verify_ssl
            )
            logger.debug(f"Initialized Keycloak OpenID client")
        except Exception as e:
            logger.error(f"Failed to initialize OpenID client: {e}")
            raise

    def validate_token(self, token: str) -> Optional[Dict]:
        """
        Validiert einen Access Token

        Args:
            token: JWT Access Token

        Returns:
            Token-Info Dictionary oder None bei ungueltigem Token
        """
        try:
            token_info = self._openid.introspect(token)

            if token_info.get('active'):
                return token_info
            else:
                logger.debug("Token is not active")
                return None

        except Exception as e:
            logger.error(f"Token validation failed: {e}")
            return None

    def decode_token(self, token: str) -> Optional[Dict]:
        """
        Dekodiert einen Token ohne Validierung

        Args:
            token: JWT Token

        Returns:
            Dekodierte Token-Daten oder None
        """
        try:
            return self._openid.decode_token(token, validate=False)
        except Exception as e:
            logger.error(f"Token decode failed: {e}")
            return None

    def authenticate(self, username: str, password: str) -> Optional[Dict]:
        """
        Authentifiziert einen Benutzer mit Username/Password

        Args:
            username: Username
            password: Password

        Returns:
            Token-Response oder None bei Fehler
        """
        try:
            return self._openid.token(username, password)
        except Exception as e:
            logger.error(f"Authentication failed for {username}: {e}")
            return None

    def refresh_token(self, refresh_token: str) -> Optional[Dict]:
        """
        Erneuert einen Token mit Refresh-Token

        Args:
            refresh_token: Refresh Token

        Returns:
            Neue Token-Response oder None bei Fehler
        """
        try:
            return self._openid.refresh_token(refresh_token)
        except Exception as e:
            logger.error(f"Token refresh failed: {e}")
            return None

    def logout(self, refresh_token: str) -> bool:
        """
        Loggt einen Benutzer aus (invalidiert Token)

        Args:
            refresh_token: Refresh Token

        Returns:
            True bei Erfolg
        """
        try:
            self._openid.logout(refresh_token)
            return True
        except Exception as e:
            logger.error(f"Logout failed: {e}")
            return False

    def get_userinfo(self, token: str) -> Optional[Dict]:
        """
        Holt User-Informationen mit Access Token

        Args:
            token: Access Token

        Returns:
            User-Info Dictionary oder None
        """
        try:
            return self._openid.userinfo(token)
        except Exception as e:
            logger.error(f"Failed to get userinfo: {e}")
            return None

    def get_well_known(self) -> Optional[Dict]:
        """
        Holt OpenID Connect Well-Known Configuration

        Returns:
            Well-Known Config oder None
        """
        try:
            return self._openid.well_known()
        except Exception as e:
            logger.error(f"Failed to get well-known config: {e}")
            return None
