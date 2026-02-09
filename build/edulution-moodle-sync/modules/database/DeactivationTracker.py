"""
Deactivation Tracker - Soft-Delete Logik fuer User und Kurse

Implementiert das Soft-Delete-Pattern analog zu edulution-mail:
- Objekte werden bei fehlendem Keycloak-Match markiert
- Nach X Markierungen und Grace-Period wird deaktiviert/geloescht
- Reaktivierung bei Wiedererscheinen in Keycloak
"""

import os
import json
import time
import logging
from typing import Dict, List, Optional, Any
from dataclasses import dataclass, asdict

logger = logging.getLogger(__name__)


@dataclass
class TrackedItem:
    """Datenklasse fuer getrackte Items"""
    identifier: str
    item_type: str  # 'user', 'course', 'group'
    first_marked: float  # Unix timestamp
    last_marked: float  # Unix timestamp
    mark_count: int
    status: str  # 'active', 'marked', 'deactivated', 'deleted'
    metadata: Dict[str, Any]  # Zusaetzliche Daten (username, email, etc.)


class DeactivationTracker:
    """
    Tracker fuer Soft-Delete-Logik

    Funktionsweise:
    1. Objekte die nicht mehr in Keycloak sind werden markiert
    2. Mark-Counter wird bei jedem Sync-Zyklus erhoeht
    3. Nach SOFT_DELETE_MARK_COUNT Markierungen: Deaktivierung
    4. Nach SOFT_DELETE_GRACE_PERIOD: Loeschung (wenn DELETE_ENABLED)
    5. Bei Wiedererscheinen: Reaktivierung und Counter-Reset
    """

    def __init__(
        self,
        storage_file: str = None,
        mark_count_threshold: int = 10,
        grace_period: int = 2592000,  # 30 Tage in Sekunden
        soft_delete_enabled: bool = True,
        delete_enabled: bool = False
    ):
        """
        Initialisiert den Tracker

        Args:
            storage_file: Pfad zur Persistenz-Datei
            mark_count_threshold: Anzahl Markierungen bis Deaktivierung
            grace_period: Zeit in Sekunden bis Loeschung nach Deaktivierung
            soft_delete_enabled: Soft-Delete aktiviert
            delete_enabled: Endgueltige Loeschung aktiviert
        """
        self.storage_file = storage_file or os.getenv(
            "DEACTIVATION_TRACKER_FILE",
            "/srv/data/deactivation_tracker.json"
        )
        self.mark_count_threshold = mark_count_threshold
        self.grace_period = grace_period
        self.soft_delete_enabled = soft_delete_enabled
        self.delete_enabled = delete_enabled

        self._tracked: Dict[str, TrackedItem] = {}
        self._load()

    def _make_key(self, item_type: str, identifier: str) -> str:
        """Erstellt einen eindeutigen Schluessel"""
        return f"{item_type}:{identifier}"

    def _load(self):
        """Laedt gespeicherte Tracking-Daten"""
        if os.path.exists(self.storage_file):
            try:
                with open(self.storage_file, 'r') as f:
                    data = json.load(f)
                    for key, item_data in data.items():
                        self._tracked[key] = TrackedItem(**item_data)
                logger.info(f"Loaded {len(self._tracked)} tracked items from {self.storage_file}")
            except json.JSONDecodeError as e:
                logger.error(f"Error parsing tracker file: {e}")
            except Exception as e:
                logger.error(f"Error loading tracker file: {e}")

    def _save(self):
        """Speichert Tracking-Daten"""
        try:
            # Verzeichnis erstellen falls nicht vorhanden
            os.makedirs(os.path.dirname(self.storage_file), exist_ok=True)

            data = {
                key: asdict(item)
                for key, item in self._tracked.items()
            }
            with open(self.storage_file, 'w') as f:
                json.dump(data, f, indent=2)
            logger.debug(f"Saved {len(self._tracked)} tracked items")
        except Exception as e:
            logger.error(f"Error saving tracker file: {e}")

    def mark(
        self,
        identifier: str,
        item_type: str = 'user',
        metadata: Dict[str, Any] = None
    ):
        """
        Markiert ein Item als fehlend

        Args:
            identifier: Eindeutiger Identifier (z.B. username, shortname)
            item_type: Typ des Items ('user', 'course', 'group')
            metadata: Zusaetzliche Informationen
        """
        if not self.soft_delete_enabled:
            return

        key = self._make_key(item_type, identifier)
        now = time.time()

        if key in self._tracked:
            # Existierendes Item: Counter erhoehen
            item = self._tracked[key]
            item.last_marked = now
            item.mark_count += 1
            if metadata:
                item.metadata.update(metadata)
            logger.debug(f"Marked {key} (count: {item.mark_count})")
        else:
            # Neues Item: Erstellen
            self._tracked[key] = TrackedItem(
                identifier=identifier,
                item_type=item_type,
                first_marked=now,
                last_marked=now,
                mark_count=1,
                status='marked',
                metadata=metadata or {}
            )
            logger.info(f"Started tracking {key}")

        self._save()

    def unmark(self, identifier: str, item_type: str = 'user'):
        """
        Entfernt Markierung (bei Wiedererscheinen in Keycloak)

        Args:
            identifier: Eindeutiger Identifier
            item_type: Typ des Items
        """
        key = self._make_key(item_type, identifier)

        if key in self._tracked:
            item = self._tracked[key]
            old_status = item.status

            # Reaktivierung
            item.status = 'active'
            item.mark_count = 0

            logger.info(f"Unmarked {key} (was {old_status})")
            self._save()

    def remove(self, identifier: str, item_type: str = 'user'):
        """
        Entfernt ein Item komplett aus dem Tracking

        Args:
            identifier: Eindeutiger Identifier
            item_type: Typ des Items
        """
        key = self._make_key(item_type, identifier)

        if key in self._tracked:
            del self._tracked[key]
            logger.info(f"Removed {key} from tracking")
            self._save()

    def should_deactivate(self, identifier: str, item_type: str = 'user') -> bool:
        """
        Prueft ob ein Item deaktiviert werden soll

        Args:
            identifier: Eindeutiger Identifier
            item_type: Typ des Items

        Returns:
            True wenn Deaktivierung faellig
        """
        if not self.soft_delete_enabled:
            return False

        key = self._make_key(item_type, identifier)
        item = self._tracked.get(key)

        if not item:
            return False

        # Bereits deaktiviert?
        if item.status == 'deactivated':
            return False

        # Mark-Count erreicht?
        if item.mark_count >= self.mark_count_threshold:
            return True

        return False

    def should_delete(self, identifier: str, item_type: str = 'user') -> bool:
        """
        Prueft ob ein Item geloescht werden soll

        Args:
            identifier: Eindeutiger Identifier
            item_type: Typ des Items

        Returns:
            True wenn Loeschung faellig
        """
        if not self.delete_enabled:
            return False

        key = self._make_key(item_type, identifier)
        item = self._tracked.get(key)

        if not item:
            return False

        # Muss zuerst deaktiviert sein
        if item.status != 'deactivated':
            return False

        # Grace Period abgelaufen?
        time_since_deactivation = time.time() - item.last_marked
        if time_since_deactivation >= self.grace_period:
            return True

        return False

    def mark_deactivated(self, identifier: str, item_type: str = 'user'):
        """
        Setzt Status auf deaktiviert

        Args:
            identifier: Eindeutiger Identifier
            item_type: Typ des Items
        """
        key = self._make_key(item_type, identifier)
        item = self._tracked.get(key)

        if item:
            item.status = 'deactivated'
            item.last_marked = time.time()
            logger.info(f"Deactivated {key}")
            self._save()

    def mark_deleted(self, identifier: str, item_type: str = 'user'):
        """
        Setzt Status auf geloescht

        Args:
            identifier: Eindeutiger Identifier
            item_type: Typ des Items
        """
        key = self._make_key(item_type, identifier)
        item = self._tracked.get(key)

        if item:
            item.status = 'deleted'
            logger.info(f"Marked {key} as deleted")
            self._save()

    def get_status(self, identifier: str, item_type: str = 'user') -> Optional[str]:
        """
        Gibt den Status eines Items zurueck

        Args:
            identifier: Eindeutiger Identifier
            item_type: Typ des Items

        Returns:
            Status-String oder None
        """
        key = self._make_key(item_type, identifier)
        item = self._tracked.get(key)
        return item.status if item else None

    def get_mark_count(self, identifier: str, item_type: str = 'user') -> int:
        """
        Gibt den Mark-Counter eines Items zurueck

        Args:
            identifier: Eindeutiger Identifier
            item_type: Typ des Items

        Returns:
            Mark-Count (0 wenn nicht getrackt)
        """
        key = self._make_key(item_type, identifier)
        item = self._tracked.get(key)
        return item.mark_count if item else 0

    def get_item(self, identifier: str, item_type: str = 'user') -> Optional[TrackedItem]:
        """
        Gibt ein getracktes Item zurueck

        Args:
            identifier: Eindeutiger Identifier
            item_type: Typ des Items

        Returns:
            TrackedItem oder None
        """
        key = self._make_key(item_type, identifier)
        return self._tracked.get(key)

    def get_items_to_deactivate(self, item_type: str = None) -> List[TrackedItem]:
        """
        Gibt alle Items zurueck die deaktiviert werden sollen

        Args:
            item_type: Optional Filter nach Typ

        Returns:
            Liste der TrackedItems
        """
        result = []
        for item in self._tracked.values():
            if item_type and item.item_type != item_type:
                continue
            if self.should_deactivate(item.identifier, item.item_type):
                result.append(item)
        return result

    def get_items_to_delete(self, item_type: str = None) -> List[TrackedItem]:
        """
        Gibt alle Items zurueck die geloescht werden sollen

        Args:
            item_type: Optional Filter nach Typ

        Returns:
            Liste der TrackedItems
        """
        result = []
        for item in self._tracked.values():
            if item_type and item.item_type != item_type:
                continue
            if self.should_delete(item.identifier, item.item_type):
                result.append(item)
        return result

    def get_statistics(self) -> Dict[str, Any]:
        """
        Gibt Statistiken ueber das Tracking zurueck

        Returns:
            Dictionary mit Statistiken
        """
        stats = {
            'total_tracked': len(self._tracked),
            'by_status': {},
            'by_type': {},
            'pending_deactivation': 0,
            'pending_deletion': 0
        }

        for item in self._tracked.values():
            # By Status
            stats['by_status'][item.status] = stats['by_status'].get(item.status, 0) + 1

            # By Type
            stats['by_type'][item.item_type] = stats['by_type'].get(item.item_type, 0) + 1

            # Pending counts
            if self.should_deactivate(item.identifier, item.item_type):
                stats['pending_deactivation'] += 1
            if self.should_delete(item.identifier, item.item_type):
                stats['pending_deletion'] += 1

        return stats

    def cleanup_deleted(self):
        """Entfernt alle Items mit Status 'deleted' aus dem Tracking"""
        to_remove = [
            key for key, item in self._tracked.items()
            if item.status == 'deleted'
        ]
        for key in to_remove:
            del self._tracked[key]

        if to_remove:
            logger.info(f"Cleaned up {len(to_remove)} deleted items")
            self._save()

    def reset_all(self):
        """Setzt das komplette Tracking zurueck"""
        count = len(self._tracked)
        self._tracked.clear()
        self._save()
        logger.warning(f"Reset tracking, removed {count} items")
