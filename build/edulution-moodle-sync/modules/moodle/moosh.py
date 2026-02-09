"""
Moosh CLI Wrapper fuer Moodle-Operationen

Moosh ist ein CLI-Tool fuer Moodle-Administration.
Diese Klasse wrappet die wichtigsten Befehle fuer den Sync.
"""

import subprocess
import logging
import os
import re
from typing import List, Dict, Optional, Tuple

logger = logging.getLogger(__name__)


class MooshWrapper:
    """
    Wrapper fuer Moosh CLI-Befehle

    Moosh-Befehle werden als Subprozesse ausgefuehrt.
    Die Klasse bietet typisierte Methoden fuer alle benoetigten Operationen.
    """

    def __init__(
        self,
        moodle_path: str = None,
        timeout: int = 60,
        dry_run: bool = False
    ):
        """
        Initialisiert den Moosh Wrapper

        Args:
            moodle_path: Pfad zur Moodle-Installation
            timeout: Timeout in Sekunden fuer Befehle
            dry_run: Bei True werden Befehle nur geloggt, nicht ausgefuehrt
        """
        self.moodle_path = moodle_path or os.getenv(
            "MOODLE_PATH",
            "/var/www/html/moodle"
        )
        self.timeout = timeout
        self.dry_run = dry_run

    def _run(
        self,
        command: str,
        *args,
        timeout: int = None,
        check_error: bool = True
    ) -> subprocess.CompletedProcess:
        """
        Fuehrt einen Moosh-Befehl aus

        Args:
            command: Moosh-Befehl (z.B. 'user-create')
            *args: Befehlsargumente
            timeout: Optional eigener Timeout
            check_error: Bei True wird bei Fehler gewarnt

        Returns:
            CompletedProcess-Objekt
        """
        # Argumente zu Strings konvertieren und None-Werte filtern
        str_args = [str(a) for a in args if a is not None]

        cmd = ["moosh", "-n", "-p", self.moodle_path, command] + str_args

        cmd_str = ' '.join(cmd)
        logger.debug(f"Executing: {cmd_str}")

        if self.dry_run:
            logger.info(f"[DRY RUN] Would execute: {cmd_str}")
            return subprocess.CompletedProcess(
                args=cmd,
                returncode=0,
                stdout="DRY_RUN",
                stderr=""
            )

        try:
            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=timeout or self.timeout
            )

            if result.returncode != 0 and check_error:
                logger.warning(
                    f"Moosh command '{command}' returned {result.returncode}: "
                    f"{result.stderr.strip()}"
                )

            return result

        except subprocess.TimeoutExpired:
            logger.error(f"Moosh command '{command}' timed out after {timeout or self.timeout}s")
            return subprocess.CompletedProcess(
                args=cmd,
                returncode=-1,
                stdout="",
                stderr="Timeout"
            )
        except Exception as e:
            logger.error(f"Error executing moosh command: {e}")
            return subprocess.CompletedProcess(
                args=cmd,
                returncode=-1,
                stdout="",
                stderr=str(e)
            )

    # ==================== USER MANAGEMENT ====================

    def user_create(
        self,
        username: str,
        email: str,
        firstname: str,
        lastname: str,
        password: str = None,
        auth: str = "oauth2",
        idnumber: str = None
    ) -> Optional[int]:
        """
        Erstellt einen neuen Benutzer

        Args:
            username: Benutzername
            email: E-Mail-Adresse
            firstname: Vorname
            lastname: Nachname
            password: Passwort (optional, default 'NONE' fuer OAuth)
            auth: Authentifizierungsmethode
            idnumber: ID-Nummer/Marker fuer Management

        Returns:
            User-ID bei Erfolg, None bei Fehler
        """
        args = [
            "--email", email,
            "--firstname", firstname,
            "--lastname", lastname,
            "--auth", auth,
        ]

        if password:
            args.extend(["--password", password])
        else:
            args.extend(["--password", "NONE"])

        if idnumber:
            args.extend(["--idnumber", idnumber])

        args.append(username)

        result = self._run("user-create", *args)

        if result.returncode == 0:
            try:
                user_id = int(result.stdout.strip())
                logger.info(f"Created user {username} with ID {user_id}")
                return user_id
            except ValueError:
                logger.warning(f"Could not parse user ID from output: {result.stdout}")
                return None
        return None

    def user_mod(
        self,
        username: str,
        email: str = None,
        firstname: str = None,
        lastname: str = None,
        idnumber: str = None,
        suspended: int = None
    ) -> bool:
        """
        Aktualisiert einen Benutzer

        Args:
            username: Benutzername
            email: Neue E-Mail (optional)
            firstname: Neuer Vorname (optional)
            lastname: Neuer Nachname (optional)
            idnumber: Neue ID-Nummer (optional)
            suspended: 1 = suspendiert, 0 = aktiv (optional)

        Returns:
            True bei Erfolg
        """
        args = []
        if email:
            args.extend(["--email", email])
        if firstname:
            args.extend(["--firstname", firstname])
        if lastname:
            args.extend(["--lastname", lastname])
        if idnumber:
            args.extend(["--idnumber", idnumber])
        if suspended is not None:
            args.extend(["--suspended", str(suspended)])

        args.append(username)

        result = self._run("user-mod", *args)
        success = result.returncode == 0
        if success:
            logger.info(f"Updated user {username}")
        return success

    def user_delete(self, username: str) -> bool:
        """
        Loescht einen Benutzer

        Args:
            username: Benutzername

        Returns:
            True bei Erfolg
        """
        result = self._run("user-delete", username)
        success = result.returncode == 0
        if success:
            logger.info(f"Deleted user {username}")
        return success

    def user_list(self, where_clause: str = "1=1", ids_only: bool = False) -> List[Dict]:
        """
        Listet Benutzer auf

        Args:
            where_clause: SQL WHERE-Bedingung
            ids_only: Bei True nur IDs zurueckgeben

        Returns:
            Liste von User-Dictionaries
        """
        args = []
        if ids_only:
            args.append("-i")
        args.append(where_clause)

        result = self._run("user-list", *args, check_error=False)

        users = []
        if result.returncode == 0 and result.stdout.strip():
            for line in result.stdout.strip().split('\n'):
                if line:
                    parts = line.split('\t')
                    if ids_only:
                        users.append({"id": parts[0]})
                    elif len(parts) >= 2:
                        users.append({
                            "id": parts[0],
                            "username": parts[1] if len(parts) > 1 else None
                        })
        return users

    def user_get_id(self, username: str) -> Optional[int]:
        """
        Ermittelt die User-ID anhand des Usernamens

        Args:
            username: Benutzername

        Returns:
            User-ID oder None
        """
        result = self._run("user-list", "-i", f"username='{username}'", check_error=False)

        if result.returncode == 0 and result.stdout.strip():
            try:
                return int(result.stdout.strip().split('\n')[0])
            except ValueError:
                return None
        return None

    def user_suspend(self, username: str) -> bool:
        """
        Suspendiert einen Benutzer

        Args:
            username: Benutzername

        Returns:
            True bei Erfolg
        """
        return self.user_mod(username, suspended=1)

    def user_unsuspend(self, username: str) -> bool:
        """
        Aktiviert einen suspendierten Benutzer

        Args:
            username: Benutzername

        Returns:
            True bei Erfolg
        """
        return self.user_mod(username, suspended=0)

    # ==================== COURSE MANAGEMENT ====================

    def course_create(
        self,
        shortname: str,
        fullname: str,
        category: int = 1,
        format: str = "topics",
        idnumber: str = None,
        visible: int = 1
    ) -> Optional[int]:
        """
        Erstellt einen neuen Kurs

        Args:
            shortname: Kurs-Kurzname (eindeutig)
            fullname: Voller Kursname
            category: Kategorie-ID
            format: Kursformat (topics, weeks, etc.)
            idnumber: ID-Nummer/Marker
            visible: Sichtbarkeit (0 oder 1)

        Returns:
            Kurs-ID bei Erfolg, None bei Fehler
        """
        args = [
            "--category", str(category),
            "--fullname", fullname,
            "--format", format,
            "--visible", str(visible)
        ]

        if idnumber:
            args.extend(["--idnumber", idnumber])

        args.append(shortname)

        result = self._run("course-create", *args)

        if result.returncode == 0:
            try:
                course_id = int(result.stdout.strip())
                logger.info(f"Created course {shortname} with ID {course_id}")
                return course_id
            except ValueError:
                logger.warning(f"Could not parse course ID from output: {result.stdout}")
                return None
        return None

    def course_list(self, where_clause: str = "1=1", ids_only: bool = False) -> List[Dict]:
        """
        Listet Kurse auf

        Args:
            where_clause: SQL WHERE-Bedingung
            ids_only: Bei True nur IDs zurueckgeben

        Returns:
            Liste von Kurs-Dictionaries
        """
        args = []
        if ids_only:
            args.append("-i")
        args.append(where_clause)

        result = self._run("course-list", *args, check_error=False)

        courses = []
        if result.returncode == 0 and result.stdout.strip():
            for line in result.stdout.strip().split('\n'):
                if line:
                    parts = line.split('\t')
                    if ids_only:
                        courses.append({"id": parts[0]})
                    elif len(parts) >= 2:
                        courses.append({
                            "id": parts[0],
                            "shortname": parts[1] if len(parts) > 1 else None
                        })
        return courses

    def course_get_id(self, shortname: str) -> Optional[int]:
        """
        Ermittelt die Kurs-ID anhand des Shortnames

        Args:
            shortname: Kurs-Kurzname

        Returns:
            Kurs-ID oder None
        """
        result = self._run("course-list", "-i", f"shortname='{shortname}'", check_error=False)

        if result.returncode == 0 and result.stdout.strip():
            try:
                return int(result.stdout.strip().split('\n')[0])
            except ValueError:
                return None
        return None

    def course_config_set(self, course_id: int, setting: str, value: str) -> bool:
        """
        Setzt eine Kurs-Einstellung

        Args:
            course_id: Kurs-ID
            setting: Einstellungsname
            value: Neuer Wert

        Returns:
            True bei Erfolg
        """
        result = self._run("course-config-set", str(course_id), setting, value)
        return result.returncode == 0

    # ==================== ENROLMENT ====================

    def course_enrol(
        self,
        course_id: int,
        username: str,
        role: str = "student"
    ) -> bool:
        """
        Schreibt einen Benutzer in einen Kurs ein

        Args:
            course_id: Kurs-ID
            username: Benutzername
            role: Rolle (student, editingteacher, teacher, manager, etc.)

        Returns:
            True bei Erfolg
        """
        result = self._run(
            "course-enrol",
            "-r", role,
            str(course_id),
            username
        )
        success = result.returncode == 0
        if success:
            logger.info(f"Enrolled {username} in course {course_id} as {role}")
        return success

    def course_unenrol(self, course_id: int, user_id: int) -> bool:
        """
        Schreibt einen Benutzer aus einem Kurs aus

        Args:
            course_id: Kurs-ID
            user_id: User-ID

        Returns:
            True bei Erfolg
        """
        result = self._run("course-unenrol", str(course_id), str(user_id))
        success = result.returncode == 0
        if success:
            logger.info(f"Unenrolled user {user_id} from course {course_id}")
        return success

    def course_enrol_list(self, course_id: int) -> List[Dict]:
        """
        Listet alle eingeschriebenen User eines Kurses

        Args:
            course_id: Kurs-ID

        Returns:
            Liste der Einschreibungen
        """
        result = self._run("course-enrolment-list", str(course_id), check_error=False)

        enrolments = []
        if result.returncode == 0 and result.stdout.strip():
            for line in result.stdout.strip().split('\n'):
                if line:
                    parts = line.split('\t')
                    if len(parts) >= 3:
                        enrolments.append({
                            "user_id": parts[0],
                            "username": parts[1] if len(parts) > 1 else None,
                            "role": parts[2] if len(parts) > 2 else None
                        })
        return enrolments

    # ==================== GROUP MANAGEMENT ====================

    def group_create(
        self,
        groupname: str,
        course_id: int,
        description: str = "",
        idnumber: str = None
    ) -> Optional[int]:
        """
        Erstellt eine Gruppe in einem Kurs

        Args:
            groupname: Gruppenname
            course_id: Kurs-ID
            description: Beschreibung
            idnumber: ID-Nummer

        Returns:
            Gruppen-ID bei Erfolg, None bei Fehler
        """
        args = ["--description", description]
        if idnumber:
            args.extend(["--idnumber", idnumber])
        args.extend([groupname, str(course_id)])

        result = self._run("group-create", *args)

        if result.returncode == 0:
            try:
                group_id = int(result.stdout.strip())
                logger.info(f"Created group {groupname} in course {course_id} with ID {group_id}")
                return group_id
            except ValueError:
                logger.warning(f"Could not parse group ID from output: {result.stdout}")
                return None
        return None

    def group_memberadd(
        self,
        group_id: int,
        user_id: int = None,
        username: str = None,
        course_id: int = None
    ) -> bool:
        """
        Fuegt ein Mitglied zu einer Gruppe hinzu

        Args:
            group_id: Gruppen-ID
            user_id: User-ID (Alternative zu username)
            username: Benutzername (Alternative zu user_id)
            course_id: Kurs-ID (bei Verwendung von username)

        Returns:
            True bei Erfolg
        """
        if username and course_id:
            result = self._run(
                "group-memberadd",
                "-g", str(group_id),
                "-c", str(course_id),
                username
            )
        elif user_id:
            result = self._run(
                "group-memberadd",
                "-g", str(group_id),
                str(user_id)
            )
        else:
            logger.error("group_memberadd requires either user_id or (username and course_id)")
            return False

        success = result.returncode == 0
        if success:
            identifier = username or user_id
            logger.debug(f"Added {identifier} to group {group_id}")
        return success

    def group_memberremove(
        self,
        group_id: int,
        user_id: int
    ) -> bool:
        """
        Entfernt ein Mitglied aus einer Gruppe

        Args:
            group_id: Gruppen-ID
            user_id: User-ID

        Returns:
            True bei Erfolg
        """
        result = self._run("group-memberremove", str(group_id), str(user_id))
        return result.returncode == 0

    def group_list(self, course_id: int) -> List[Dict]:
        """
        Listet alle Gruppen eines Kurses

        Args:
            course_id: Kurs-ID

        Returns:
            Liste der Gruppen
        """
        result = self._run("group-list", str(course_id), check_error=False)

        groups = []
        if result.returncode == 0 and result.stdout.strip():
            for line in result.stdout.strip().split('\n'):
                if line:
                    parts = line.split('\t')
                    if len(parts) >= 2:
                        groups.append({
                            "id": parts[0],
                            "name": parts[1] if len(parts) > 1 else None
                        })
        return groups

    # ==================== ROLE MANAGEMENT ====================

    def user_assign_system_role(self, username: str, role: str) -> bool:
        """
        Weist einem Benutzer eine System-Rolle zu

        Args:
            username: Benutzername
            role: Rollenname (z.B. 'manager')

        Returns:
            True bei Erfolg
        """
        result = self._run("user-assign-system-role", username, role)
        success = result.returncode == 0
        if success:
            logger.info(f"Assigned system role {role} to {username}")
        return success

    def user_unassign_system_role(self, username: str, role: str) -> bool:
        """
        Entfernt eine System-Rolle von einem Benutzer

        Args:
            username: Benutzername
            role: Rollenname

        Returns:
            True bei Erfolg
        """
        result = self._run("user-unassign-system-role", username, role)
        success = result.returncode == 0
        if success:
            logger.info(f"Unassigned system role {role} from {username}")
        return success

    # ==================== COHORT MANAGEMENT ====================

    def cohort_create(
        self,
        name: str,
        idnumber: str = None,
        description: str = "",
        category: int = None
    ) -> Optional[int]:
        """
        Erstellt eine Kohorte (globale Gruppe)

        Args:
            name: Kohortenname
            idnumber: ID-Nummer
            description: Beschreibung
            category: Kategorie-ID (System-Kohorte wenn None)

        Returns:
            Kohorten-ID bei Erfolg
        """
        args = ["-d", description]
        if idnumber:
            args.extend(["-i", idnumber])
        if category:
            args.extend(["-c", str(category)])
        args.append(f'"{name}"')

        result = self._run("cohort-create", *args)

        if result.returncode == 0:
            try:
                return int(result.stdout.strip())
            except ValueError:
                return None
        return None

    def cohort_enrol(
        self,
        cohort_name: str,
        user_id: int = None,
        course_id: int = None
    ) -> bool:
        """
        Schreibt User in Kohorte oder Kohorte in Kurs ein

        Args:
            cohort_name: Kohortenname
            user_id: User-ID (fuer User-Einschreibung)
            course_id: Kurs-ID (fuer Kurs-Einschreibung)

        Returns:
            True bei Erfolg
        """
        if course_id:
            result = self._run("cohort-enrol", "-c", str(course_id), f'"{cohort_name}"')
        elif user_id:
            result = self._run("cohort-enrol", str(user_id), f'"{cohort_name}"')
        else:
            return False
        return result.returncode == 0

    # ==================== MAINTENANCE ====================

    def maintenance_on(self, message: str = None) -> bool:
        """
        Aktiviert Wartungsmodus

        Args:
            message: Optional Nachricht fuer Benutzer

        Returns:
            True bei Erfolg
        """
        args = []
        if message:
            args.extend(["-m", message])

        result = self._run("maintenance-on", *args)
        success = result.returncode == 0
        if success:
            logger.info("Maintenance mode enabled")
        return success

    def maintenance_off(self) -> bool:
        """
        Deaktiviert Wartungsmodus

        Returns:
            True bei Erfolg
        """
        result = self._run("maintenance-off")
        success = result.returncode == 0
        if success:
            logger.info("Maintenance mode disabled")
        return success

    def cache_clear(self) -> bool:
        """
        Leert den Moodle-Cache

        Returns:
            True bei Erfolg
        """
        result = self._run("cache-clear")
        success = result.returncode == 0
        if success:
            logger.info("Cache cleared")
        return success

    # ==================== CONFIG ====================

    def config_get(self, name: str, plugin: str = None) -> Optional[str]:
        """
        Liest eine Moodle-Konfiguration

        Args:
            name: Konfigurationsname
            plugin: Plugin-Name (optional)

        Returns:
            Konfigurationswert oder None
        """
        args = []
        if plugin:
            args.extend(["--plugin", plugin])
        args.append(name)

        result = self._run("config-get", *args, check_error=False)
        if result.returncode == 0:
            return result.stdout.strip()
        return None

    def config_set(self, name: str, value: str, plugin: str = None) -> bool:
        """
        Setzt eine Moodle-Konfiguration

        Args:
            name: Konfigurationsname
            value: Neuer Wert
            plugin: Plugin-Name (optional)

        Returns:
            True bei Erfolg
        """
        args = []
        if plugin:
            args.extend(["--plugin", plugin])
        args.extend([name, value])

        result = self._run("config-set", *args)
        return result.returncode == 0

    # ==================== UTILITY ====================

    def check_connection(self) -> bool:
        """
        Prueft ob Moosh funktioniert

        Returns:
            True wenn Verbindung OK
        """
        result = self._run("info", check_error=False)
        return result.returncode == 0

    def get_version(self) -> Optional[str]:
        """
        Gibt die Moodle-Version zurueck

        Returns:
            Versionsnummer oder None
        """
        result = self._run("info", check_error=False)
        if result.returncode == 0:
            # Parse version from info output
            for line in result.stdout.split('\n'):
                if 'version' in line.lower():
                    match = re.search(r'(\d+\.\d+(?:\.\d+)?)', line)
                    if match:
                        return match.group(1)
        return None
