"""
List Storage - Basis-Klasse fuer Queue-basierte Datenspeicherung

Implementiert das Queue-Pattern fuer add/update/disable Operationen
analog zur edulution-mail Architektur.
"""

import logging
from typing import Any, Dict, List, Optional
from abc import ABC, abstractmethod

logger = logging.getLogger(__name__)


class ListStorage(ABC):
    """
    Abstrakte Basis-Klasse fuer Daten-Storage mit Queues

    Verwaltet drei Queues:
    - add: Neue Objekte die erstellt werden sollen
    - update: Existierende Objekte die aktualisiert werden sollen
    - disable: Objekte die deaktiviert/geloescht werden sollen
    """

    def __init__(self):
        """Initialisiert die Storage-Klasse mit leeren Queues"""
        self._queues: Dict[str, List[Dict]] = {
            'add': [],
            'update': [],
            'disable': []
        }
        self._items: Dict[str, Dict] = {}  # Key: identifier, Value: item data
        self._processed: Dict[str, bool] = {}  # Track processed items

    @abstractmethod
    def get_identifier(self, item: Dict) -> str:
        """
        Gibt den eindeutigen Identifier eines Items zurueck

        Args:
            item: Das Item-Dictionary

        Returns:
            Eindeutiger Identifier als String
        """
        pass

    def add_to_queue(self, queue_name: str, item: Dict):
        """
        Fuegt ein Item zu einer Queue hinzu

        Args:
            queue_name: Name der Queue ('add', 'update', 'disable')
            item: Das hinzuzufuegende Item
        """
        if queue_name not in self._queues:
            logger.warning(f"Unknown queue: {queue_name}")
            return

        identifier = self.get_identifier(item)
        if identifier in self._processed:
            logger.debug(f"Item {identifier} already processed, skipping")
            return

        self._queues[queue_name].append(item)
        self._processed[identifier] = True
        logger.debug(f"Added {identifier} to {queue_name} queue")

    def get_queue(self, queue_name: str) -> List[Dict]:
        """
        Gibt alle Items einer Queue zurueck

        Args:
            queue_name: Name der Queue

        Returns:
            Liste der Items in der Queue
        """
        return self._queues.get(queue_name, [])

    def clear_queue(self, queue_name: str):
        """
        Leert eine Queue

        Args:
            queue_name: Name der Queue
        """
        if queue_name in self._queues:
            self._queues[queue_name] = []
            logger.debug(f"Cleared {queue_name} queue")

    def clear_all_queues(self):
        """Leert alle Queues"""
        for queue_name in self._queues:
            self._queues[queue_name] = []
        self._processed = {}
        logger.debug("Cleared all queues")

    def store(self, item: Dict):
        """
        Speichert ein Item im internen Storage

        Args:
            item: Das zu speichernde Item
        """
        identifier = self.get_identifier(item)
        self._items[identifier] = item

    def get(self, identifier: str) -> Optional[Dict]:
        """
        Holt ein Item aus dem Storage

        Args:
            identifier: Der Identifier des Items

        Returns:
            Das Item oder None
        """
        return self._items.get(identifier)

    def exists(self, identifier: str) -> bool:
        """
        Prueft ob ein Item existiert

        Args:
            identifier: Der Identifier des Items

        Returns:
            True wenn Item existiert
        """
        return identifier in self._items

    def remove(self, identifier: str):
        """
        Entfernt ein Item aus dem Storage

        Args:
            identifier: Der Identifier des Items
        """
        if identifier in self._items:
            del self._items[identifier]
            logger.debug(f"Removed {identifier} from storage")

    def get_all(self) -> Dict[str, Dict]:
        """
        Gibt alle gespeicherten Items zurueck

        Returns:
            Dictionary aller Items
        """
        return self._items.copy()

    def count(self) -> int:
        """
        Gibt die Anzahl der gespeicherten Items zurueck

        Returns:
            Anzahl der Items
        """
        return len(self._items)

    def queue_counts(self) -> Dict[str, int]:
        """
        Gibt die Anzahl der Items pro Queue zurueck

        Returns:
            Dictionary mit Queue-Namen und Anzahlen
        """
        return {
            queue_name: len(items)
            for queue_name, items in self._queues.items()
        }

    def get_statistics(self) -> Dict[str, Any]:
        """
        Gibt Statistiken ueber den Storage zurueck

        Returns:
            Dictionary mit Statistiken
        """
        return {
            'total_items': self.count(),
            'queues': self.queue_counts(),
            'processed': len(self._processed)
        }
