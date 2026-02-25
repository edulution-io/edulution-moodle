<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * German language strings for local_edulution.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// ============================================================================
// PLUGIN-INFORMATIONEN
// ============================================================================
$string['pluginname'] = 'edulution';
$string['plugindescription'] = 'Komplettloesung fuer Migration, Synchronisation und Verwaltung von edulution Moodle';
$string['pluginversion'] = 'Version';
$string['pluginauthor'] = 'Autor';
$string['pluginlicense'] = 'Lizenz';
$string['pluginsupport'] = 'Support';
$string['plugindocumentation'] = 'Dokumentation';

// ============================================================================
// BERECHTIGUNGEN
// ============================================================================
$string['edulution:manage'] = 'edulution-Einstellungen verwalten';
$string['edulution:export'] = 'Daten aus Moodle exportieren';
$string['edulution:import'] = 'Daten in Moodle importieren';
$string['edulution:sync'] = 'Mit Keycloak synchronisieren';
$string['edulution:viewreports'] = 'edulution-Berichte anzeigen';
$string['edulution:viewdashboard'] = 'edulution-Dashboard anzeigen';
$string['edulution:managekeycloak'] = 'Keycloak-Integration verwalten';
$string['edulution:manageusers'] = 'Benutzersynchronisation verwalten';
$string['edulution:managecourses'] = 'Kurs-Export/Import verwalten';
$string['edulution:viewlogs'] = 'Aktivitaetsprotokolle anzeigen';
$string['edulution:deletedata'] = 'Exportierte/importierte Daten loeschen';
$string['edulution:schedule'] = 'Automatisierte Aufgaben planen';

// ============================================================================
// NAVIGATION
// ============================================================================
$string['nav_dashboard'] = 'Dashboard';
$string['nav_export'] = 'Export';
$string['nav_import'] = 'Import';
$string['nav_sync'] = 'Keycloak-Synchronisation';
$string['nav_keycloak'] = 'Keycloak-Einstellungen';
$string['nav_settings'] = 'Einstellungen';
$string['nav_reports'] = 'Berichte';
$string['nav_logs'] = 'Aktivitaetsprotokolle';
$string['nav_users'] = 'Benutzerverwaltung';
$string['nav_courses'] = 'Kursverwaltung';
$string['nav_schedule'] = 'Geplante Aufgaben';
$string['nav_help'] = 'Hilfe & Support';
$string['nav_back'] = 'Zurueck';
$string['nav_home'] = 'Startseite';

// Legacy-Navigationsstrings
$string['dashboard'] = 'Dashboard';
$string['export'] = 'Export';
$string['import'] = 'Import';
$string['sync'] = 'Keycloak-Synchronisation';
$string['keycloak'] = 'Keycloak';
$string['settings'] = 'Einstellungen';
$string['reports'] = 'Berichte';

// ============================================================================
// DASHBOARD
// ============================================================================
$string['dashboard_title'] = 'edulution Dashboard';
$string['dashboard_subtitle'] = 'Uebersicht Ihrer edulution-Integration';
$string['dashboard_welcome'] = 'Willkommen bei edulution';
$string['dashboard_welcome_message'] = 'Verwalten Sie Ihre Moodle-Migration, Synchronisation und Datenverwaltung von diesem zentralen Hub aus.';
$string['dashboard_description'] = 'Verwalten Sie Ihre Moodle-Exporte, Importe und Keycloak-Synchronisation von einem Ort aus.';

// Dashboard-Statistiken
$string['dashboard_users_count'] = 'Benutzer gesamt';
$string['dashboard_courses_count'] = 'Kurse gesamt';
$string['dashboard_categories_count'] = 'Kategorien gesamt';
$string['dashboard_enrolments_count'] = 'Einschreibungen gesamt';
$string['dashboard_active_users'] = 'Aktive Benutzer';
$string['dashboard_synced_users'] = 'Synchronisierte Benutzer';
$string['dashboard_pending_sync'] = 'Ausstehende Synchronisationen';
$string['dashboard_failed_sync'] = 'Fehlgeschlagene Synchronisationen';
$string['total_users'] = 'Benutzer gesamt';
$string['total_courses'] = 'Kurse gesamt';

// Dashboard-Zeitstempel
$string['dashboard_last_sync'] = 'Letzte Synchronisation';
$string['dashboard_last_export'] = 'Letzter Export';
$string['dashboard_last_import'] = 'Letzter Import';
$string['dashboard_last_activity'] = 'Letzte Aktivitaet';
$string['dashboard_next_scheduled'] = 'Naechste geplante';
$string['dashboard_never'] = 'Nie';
$string['dashboard_ago'] = 'vor {$a}';
$string['dashboard_in'] = 'in {$a}';
$string['last_sync'] = 'Letzte Synchronisation';
$string['export_status'] = 'Export-Status';
$string['never'] = 'Nie';
$string['no_exports'] = 'Keine Exporte';

// Dashboard-Karten
$string['dashboard_card_export'] = 'Daten exportieren';
$string['dashboard_card_export_desc'] = 'Benutzer, Kurse und Einschreibungen in externe Systeme exportieren';
$string['dashboard_card_import'] = 'Daten importieren';
$string['dashboard_card_import_desc'] = 'Daten aus externen Quellen in Moodle importieren';
$string['dashboard_card_sync'] = 'Keycloak-Synchronisation';
$string['dashboard_card_sync_desc'] = 'Benutzer mit Keycloak-Identitaetsanbieter synchronisieren';
$string['dashboard_card_settings'] = 'Einstellungen';
$string['dashboard_card_settings_desc'] = 'edulution-Plugin-Einstellungen konfigurieren';
$string['dashboard_card_reports'] = 'Berichte';
$string['dashboard_card_reports_desc'] = 'Detaillierte Berichte und Analysen anzeigen';
$string['dashboard_card_logs'] = 'Aktivitaetsprotokolle';
$string['dashboard_card_logs_desc'] = 'Aktuelle Aktivitaeten und Vorgaenge ueberpruefen';

// Dashboard-Status
$string['dashboard_status_healthy'] = 'Funktionsfaehig';
$string['dashboard_status_warning'] = 'Warnung';
$string['dashboard_status_error'] = 'Fehler';
$string['dashboard_status_unknown'] = 'Unbekannt';
$string['dashboard_status_connected'] = 'Verbunden';
$string['dashboard_status_disconnected'] = 'Getrennt';
$string['dashboard_status_syncing'] = 'Synchronisiert...';
$string['dashboard_status_idle'] = 'Bereit';

// Dashboard-Aktionen
$string['dashboard_action_refresh'] = 'Aktualisieren';
$string['dashboard_action_viewall'] = 'Alle anzeigen';
$string['dashboard_action_configure'] = 'Konfigurieren';
$string['dashboard_action_start'] = 'Starten';
$string['dashboard_action_stop'] = 'Stoppen';
$string['dashboard_action_details'] = 'Details';

// Dashboard-Schnellaktionen
$string['dashboard_quick_export'] = 'Schnell-Export';
$string['dashboard_quick_sync'] = 'Schnell-Synchronisation';
$string['dashboard_quick_import'] = 'Schnell-Import';
$string['dashboard_quick_report'] = 'Bericht erstellen';
$string['quick_actions'] = 'Schnellaktionen';
$string['start_sync'] = 'Synchronisation starten';
$string['new_export'] = 'Neuer Export';
$string['view_reports'] = 'Berichte anzeigen';

// Dashboard-Warnungen
$string['dashboard_alert_sync_required'] = 'Benutzersynchronisation erforderlich';
$string['dashboard_alert_export_pending'] = 'Export-Aufgaben stehen aus';
$string['dashboard_alert_import_failed'] = 'Letzter Import fehlgeschlagen';
$string['dashboard_alert_keycloak_disconnected'] = 'Keycloak-Verbindung verloren';
$string['dashboard_alert_update_available'] = 'Plugin-Update verfuegbar';

// Dashboard-Sonstiges
$string['recent_activity'] = 'Aktuelle Aktivitaeten';
$string['no_recent_activity'] = 'Keine aktuellen Aktivitaeten';
$string['system_status'] = 'Systemstatus';
$string['keycloak_connection'] = 'Keycloak-Verbindung';
$string['disk_space'] = 'Speicherplatz';
$string['connected'] = 'Verbunden';
$string['disconnected'] = 'Getrennt';
$string['not_configured'] = 'Nicht konfiguriert';
$string['available'] = 'Verfuegbar';
$string['used'] = 'Belegt';
$string['free'] = 'Frei';

// ============================================================================
// EXPORT
// ============================================================================
$string['export_title'] = 'Daten exportieren';
$string['export_subtitle'] = 'Moodle-Daten in externe Systeme exportieren';
$string['export_description'] = 'Exportieren Sie Ihre Moodle-Daten fuer Backup oder Migration.';

// Export-Typen
$string['export_type'] = 'Export-Typ';
$string['export_type_users'] = 'Benutzer';
$string['export_type_courses'] = 'Kurse';
$string['export_type_categories'] = 'Kategorien';
$string['export_type_enrolments'] = 'Einschreibungen';
$string['export_type_grades'] = 'Bewertungen';
$string['export_type_completions'] = 'Abschluesse';
$string['export_type_groups'] = 'Gruppen';
$string['export_type_cohorts'] = 'Globale Gruppen';
$string['export_type_roles'] = 'Rollen';
$string['export_type_all'] = 'Alle Daten';
$string['export_type_custom'] = 'Benutzerdefinierte Auswahl';

// Export-Formularfelder
$string['export_format'] = 'Export-Format';
$string['export_format_json'] = 'JSON';
$string['export_format_csv'] = 'CSV';
$string['export_format_xml'] = 'XML';
$string['export_format_sql'] = 'SQL';
$string['export_destination'] = 'Ziel';
$string['export_destination_file'] = 'Datei herunterladen';
$string['export_destination_server'] = 'Server-Speicher';
$string['export_destination_api'] = 'Externe API';
$string['export_filename'] = 'Dateiname';
$string['export_filename_help'] = 'Geben Sie den Dateinamen ohne Erweiterung ein';
$string['export_include_header'] = 'Kopfzeile einschliessen';
$string['export_include_timestamps'] = 'Zeitstempel einschliessen';
$string['export_include_ids'] = 'Interne IDs einschliessen';
$string['export_compress'] = 'Ausgabe komprimieren';
$string['export_compress_help'] = 'ZIP-Archiv der exportierten Dateien erstellen';

// Export-Optionen (Legacy)
$string['export_options'] = 'Export-Optionen';
$string['full_database_export'] = 'Vollstaendiger Datenbank-Export';
$string['full_database_export_help'] = 'Exportiert die komplette Datenbank. Empfohlen fuer vollstaendige Migration.';
$string['tables_to_exclude'] = 'Auszuschliessende Tabellen';
$string['tables_to_exclude_help'] = 'Kommagetrennte Liste von Tabellennamen, die ausgeschlossen werden sollen (ohne Praefix).';
$string['include_moodledata'] = 'Moodledata einschliessen';
$string['include_moodledata_help'] = 'Das moodledata-Verzeichnis im Export einschliessen.';
$string['include_course_backups'] = 'Kurs-Backups einschliessen';
$string['include_course_backups_help'] = '.mbz-Backup-Dateien fuer jeden Kurs erstellen.';
$string['compression_level'] = 'Komprimierungsstufe';
$string['compression_level_help'] = 'Hoehere Komprimierung erzeugt kleinere Dateien, dauert aber laenger.';
$string['compression_none'] = 'Keine (schnellste)';
$string['compression_normal'] = 'Normal';
$string['compression_maximum'] = 'Maximal (kleinste)';
$string['selective_export'] = 'Selektiver Export';
$string['select_categories'] = 'Kategorien auswaehlen';
$string['select_categories_help'] = 'Bestimmte Kategorien zum Export auswaehlen. Leer lassen fuer alle.';
$string['select_courses'] = 'Kurse auswaehlen';
$string['select_courses_help'] = 'Bestimmte Kurse zum Export auswaehlen. Leer lassen fuer alle.';

// Export-Filter
$string['export_filter_title'] = 'Filteroptionen';
$string['export_filter_daterange'] = 'Datumsbereich';
$string['export_filter_datefrom'] = 'Von Datum';
$string['export_filter_dateto'] = 'Bis Datum';
$string['export_filter_category'] = 'Kategorie';
$string['export_filter_course'] = 'Kurs';
$string['export_filter_role'] = 'Rolle';
$string['export_filter_status'] = 'Status';
$string['export_filter_active'] = 'Nur aktive';
$string['export_filter_suspended'] = 'Gesperrte einschliessen';
$string['export_filter_deleted'] = 'Geloeschte einschliessen';

// Export-Optionen erweitert
$string['export_options_title'] = 'Export-Optionen';
$string['export_option_incremental'] = 'Inkrementeller Export';
$string['export_option_incremental_help'] = 'Nur seit dem letzten Export geaenderte Datensaetze exportieren';
$string['export_option_fullexport'] = 'Vollstaendiger Export';
$string['export_option_anonymize'] = 'Personendaten anonymisieren';
$string['export_option_anonymize_help'] = 'Personenbezogene Daten durch anonyme Platzhalter ersetzen';
$string['export_option_encrypt'] = 'Ausgabe verschluesseln';
$string['export_option_encrypt_help'] = 'Exportierte Daten mit AES-256 verschluesseln';
$string['export_option_validate'] = 'Vor Export validieren';
$string['export_option_notify'] = 'Benachrichtigung bei Abschluss senden';

// Export-Fortschritt
$string['export_progress_title'] = 'Export-Fortschritt';
$string['export_progress_preparing'] = 'Export wird vorbereitet...';
$string['export_progress_processing'] = 'Datensaetze werden verarbeitet...';
$string['export_progress_processed'] = '{$a->current} von {$a->total} Datensaetzen verarbeitet';
$string['export_progress_writing'] = 'Ausgabedatei wird geschrieben...';
$string['export_progress_compressing'] = 'Dateien werden komprimiert...';
$string['export_progress_uploading'] = 'Zum Ziel hochladen...';
$string['export_progress_finalizing'] = 'Export wird abgeschlossen...';
$string['export_progress_complete'] = 'Export abgeschlossen!';
$string['export_progress_failed'] = 'Export fehlgeschlagen';
$string['export_progress_cancelled'] = 'Export abgebrochen';
$string['export_progress_percent'] = '{$a}% abgeschlossen';
$string['export_progress'] = 'Export-Fortschritt';
$string['start_export'] = 'Export starten';
$string['export_complete'] = 'Export abgeschlossen';
$string['export_failed'] = 'Export fehlgeschlagen';
$string['download_export'] = 'Export herunterladen';
$string['export_running'] = 'Export laeuft...';
$string['export_cancelled'] = 'Export abgebrochen';

// Export-Ergebnisse
$string['export_result_title'] = 'Export-Ergebnisse';
$string['export_result_success'] = 'Export erfolgreich abgeschlossen';
$string['export_result_partial'] = 'Export mit Warnungen abgeschlossen';
$string['export_result_failed'] = 'Export fehlgeschlagen';
$string['export_result_records'] = '{$a} Datensaetze exportiert';
$string['export_result_filesize'] = 'Dateigroesse: {$a}';
$string['export_result_duration'] = 'Dauer: {$a}';
$string['export_result_download'] = 'Export herunterladen';
$string['export_result_viewlog'] = 'Export-Protokoll anzeigen';

// Export-Fehler
$string['export_error_nodata'] = 'Keine Daten zum Exportieren';
$string['export_error_permission'] = 'Sie haben keine Berechtigung, diese Daten zu exportieren';
$string['export_error_writefailed'] = 'Exportdatei konnte nicht geschrieben werden';
$string['export_error_invalidformat'] = 'Ungueltiges Export-Format ausgewaehlt';
$string['export_error_invaliddestination'] = 'Ungueltiges Export-Ziel';
$string['export_error_timeout'] = 'Export-Vorgang ist abgelaufen';
$string['export_error_memory'] = 'Unzureichender Speicher fuer den Export';
$string['export_error_connection'] = 'Verbindung zum Ziel fehlgeschlagen';
$string['export_error_validation'] = 'Datenvalidierung fehlgeschlagen';
$string['export_error_unknown'] = 'Ein unbekannter Fehler ist beim Export aufgetreten';

// Export-Aktionen
$string['export_action_start'] = 'Export starten';
$string['export_action_cancel'] = 'Export abbrechen';
$string['export_action_pause'] = 'Export pausieren';
$string['export_action_resume'] = 'Export fortsetzen';
$string['export_action_retry'] = 'Export wiederholen';
$string['export_action_schedule'] = 'Export planen';
$string['export_action_preview'] = 'Daten voranzeigen';
$string['export_action_configure'] = 'Export konfigurieren';

// Export-Planung
$string['export_schedule_title'] = 'Export planen';
$string['export_schedule_frequency'] = 'Haeufigkeit';
$string['export_schedule_daily'] = 'Taeglich';
$string['export_schedule_weekly'] = 'Woechentlich';
$string['export_schedule_monthly'] = 'Monatlich';
$string['export_schedule_custom'] = 'Benutzerdefiniert';
$string['export_schedule_time'] = 'Uhrzeit';
$string['export_schedule_dayofweek'] = 'Wochentag';
$string['export_schedule_dayofmonth'] = 'Tag des Monats';
$string['export_schedule_enabled'] = 'Geplanten Export aktivieren';
$string['export_schedule_next'] = 'Naechster geplanter Lauf: {$a}';

// ============================================================================
// IMPORT
// ============================================================================
$string['import_title'] = 'Daten importieren';
$string['import_subtitle'] = 'Daten aus externen Quellen in Moodle importieren';
$string['import_description'] = 'Daten aus einem edulution-Exportpaket importieren.';

// Import-Typen
$string['import_type'] = 'Import-Typ';
$string['import_type_users'] = 'Benutzer';
$string['import_type_courses'] = 'Kurse';
$string['import_type_categories'] = 'Kategorien';
$string['import_type_enrolments'] = 'Einschreibungen';
$string['import_type_grades'] = 'Bewertungen';
$string['import_type_groups'] = 'Gruppen';
$string['import_type_cohorts'] = 'Globale Gruppen';
$string['import_type_custom'] = 'Benutzerdefinierter Import';

// Import-Quelle
$string['import_source'] = 'Import-Quelle';
$string['import_source_file'] = 'Datei hochladen';
$string['import_source_url'] = 'Externe URL';
$string['import_source_api'] = 'API-Endpunkt';
$string['import_source_server'] = 'Server-Datei';
$string['import_file'] = 'Datei auswaehlen';
$string['import_file_help'] = 'Unterstuetzte Formate: CSV, JSON, XML';
$string['import_url'] = 'Quell-URL';
$string['import_url_help'] = 'Geben Sie die URL der Datenquelle ein';

// Import-Formularfelder
$string['import_format'] = 'Dateiformat';
$string['import_format_auto'] = 'Automatisch erkennen';
$string['import_encoding'] = 'Datei-Kodierung';
$string['import_encoding_utf8'] = 'UTF-8';
$string['import_encoding_latin1'] = 'ISO-8859-1';
$string['import_encoding_auto'] = 'Automatisch erkennen';
$string['import_delimiter'] = 'CSV-Trennzeichen';
$string['import_delimiter_comma'] = 'Komma (,)';
$string['import_delimiter_semicolon'] = 'Semikolon (;)';
$string['import_delimiter_tab'] = 'Tabulator';
$string['import_hasheader'] = 'Erste Zeile ist Kopfzeile';
$string['import_skiprows'] = 'Zeilen ueberspringen';
$string['import_skiprows_help'] = 'Anzahl der Zeilen, die am Anfang uebersprungen werden sollen';

// Import-Optionen (Legacy)
$string['upload_export'] = 'Exportdatei hochladen';
$string['upload_export_help'] = 'Laden Sie eine mit edulution-Export erstellte ZIP-Datei hoch.';
$string['preview_contents'] = 'Inhalte voranzeigen';
$string['import_options'] = 'Import-Optionen';
$string['import_users'] = 'Benutzer importieren';
$string['import_courses'] = 'Kurse importieren';
$string['import_categories'] = 'Kategorien importieren';
$string['import_enrollments'] = 'Einschreibungen importieren';
$string['start_import'] = 'Import starten';
$string['import_progress'] = 'Import-Fortschritt';
$string['import_complete'] = 'Import abgeschlossen';
$string['import_failed'] = 'Import fehlgeschlagen';
$string['import_cli_note'] = 'Hinweis: Ein vollstaendiger Datenbankimport muss aus Sicherheitsgruenden ueber die Kommandozeile durchgefuehrt werden.';
$string['no_file_uploaded'] = 'Keine Datei hochgeladen';
$string['invalid_export_file'] = 'Ungueltige Exportdatei';
$string['file_uploaded'] = 'Datei erfolgreich hochgeladen';

// Import-Zuordnung
$string['import_mapping_title'] = 'Feldzuordnung';
$string['import_mapping_description'] = 'Quellfelder den Moodle-Feldern zuordnen';
$string['import_mapping_source'] = 'Quellfeld';
$string['import_mapping_target'] = 'Moodle-Feld';
$string['import_mapping_default'] = 'Standardwert';
$string['import_mapping_required'] = 'Erforderlich';
$string['import_mapping_skip'] = 'Dieses Feld ueberspringen';
$string['import_mapping_automap'] = 'Felder automatisch zuordnen';
$string['import_mapping_clear'] = 'Zuordnung loeschen';
$string['import_mapping_save'] = 'Zuordnung speichern';
$string['import_mapping_load'] = 'Gespeicherte Zuordnung laden';

// Import-Optionen erweitert
$string['import_options_title'] = 'Import-Optionen';
$string['import_option_update'] = 'Vorhandene Datensaetze aktualisieren';
$string['import_option_update_help'] = 'Datensaetze aktualisieren, wenn sie bereits existieren';
$string['import_option_create'] = 'Neue Datensaetze erstellen';
$string['import_option_skip_existing'] = 'Vorhandene Datensaetze ueberspringen';
$string['import_option_delete_missing'] = 'Fehlende Datensaetze loeschen';
$string['import_option_validate'] = 'Vor Import validieren';
$string['import_option_simulate'] = 'Simulationsmodus (Testlauf)';
$string['import_option_simulate_help'] = 'Import-Vorschau ohne Aenderungen vorzunehmen';
$string['import_option_notify'] = 'Benachrichtigung bei Abschluss senden';
$string['import_option_sendwelcome'] = 'Willkommens-E-Mail an neue Benutzer senden';

// Import-Fortschritt
$string['import_progress_title'] = 'Import-Fortschritt';
$string['import_progress_uploading'] = 'Datei wird hochgeladen...';
$string['import_progress_parsing'] = 'Daten werden analysiert...';
$string['import_progress_validating'] = 'Datensaetze werden validiert...';
$string['import_progress_processing'] = 'Datensaetze werden verarbeitet...';
$string['import_progress_processed'] = '{$a->current} von {$a->total} Datensaetzen verarbeitet';
$string['import_progress_finalizing'] = 'Import wird abgeschlossen...';
$string['import_progress_complete'] = 'Import abgeschlossen!';
$string['import_progress_failed'] = 'Import fehlgeschlagen';
$string['import_progress_cancelled'] = 'Import abgebrochen';
$string['import_progress_percent'] = '{$a}% abgeschlossen';

// Import-Ergebnisse
$string['import_result_title'] = 'Import-Ergebnisse';
$string['import_result_success'] = 'Import erfolgreich abgeschlossen';
$string['import_result_partial'] = 'Import mit Fehlern abgeschlossen';
$string['import_result_failed'] = 'Import fehlgeschlagen';
$string['import_result_created'] = '{$a} Datensaetze erstellt';
$string['import_result_updated'] = '{$a} Datensaetze aktualisiert';
$string['import_result_skipped'] = '{$a} Datensaetze uebersprungen';
$string['import_result_failed_records'] = '{$a} Datensaetze fehlgeschlagen';
$string['import_result_duration'] = 'Dauer: {$a}';
$string['import_result_viewlog'] = 'Import-Protokoll anzeigen';
$string['import_result_download_errors'] = 'Fehlerbericht herunterladen';

// Import-Fehler
$string['import_error_nofile'] = 'Keine Datei hochgeladen';
$string['import_error_invalidfile'] = 'Ungueltiges Dateiformat';
$string['import_error_emptyfile'] = 'Datei ist leer';
$string['import_error_toolarge'] = 'Datei ist zu gross';
$string['import_error_permission'] = 'Sie haben keine Berechtigung, Daten zu importieren';
$string['import_error_parsing'] = 'Datei konnte nicht analysiert werden';
$string['import_error_mapping'] = 'Feldzuordnung ist unvollstaendig';
$string['import_error_validation'] = 'Datenvalidierung fehlgeschlagen';
$string['import_error_duplicate'] = 'Doppelter Datensatz gefunden';
$string['import_error_required'] = 'Pflichtfeld fehlt';
$string['import_error_invalid_value'] = 'Ungueltiger Wert fuer Feld {$a}';
$string['import_error_connection'] = 'Verbindung zur Quelle fehlgeschlagen';
$string['import_error_timeout'] = 'Import-Vorgang ist abgelaufen';
$string['import_error_unknown'] = 'Ein unbekannter Fehler ist beim Import aufgetreten';

// Import-Aktionen
$string['import_action_start'] = 'Import starten';
$string['import_action_cancel'] = 'Import abbrechen';
$string['import_action_pause'] = 'Import pausieren';
$string['import_action_resume'] = 'Import fortsetzen';
$string['import_action_retry'] = 'Import wiederholen';
$string['import_action_preview'] = 'Daten voranzeigen';
$string['import_action_validate'] = 'Daten validieren';
$string['import_action_configure'] = 'Import konfigurieren';

// Import-Vorschau
$string['import_preview_title'] = 'Import-Vorschau';
$string['import_preview_description'] = 'Daten vor dem Import ueberpruefen';
$string['import_preview_records'] = 'Zeige {$a->shown} von {$a->total} Datensaetzen';
$string['import_preview_valid'] = 'Gueltig';
$string['import_preview_invalid'] = 'Ungueltig';
$string['import_preview_warning'] = 'Warnung';

// ============================================================================
// KEYCLOAK-SYNCHRONISATION
// ============================================================================
$string['sync_title'] = 'Keycloak-Synchronisation';
$string['sync_subtitle'] = 'Benutzer zwischen Moodle und Keycloak synchronisieren';
$string['sync_description'] = 'Benutzer zwischen Keycloak und Moodle synchronisieren.';

// Sync-Status
$string['sync_status_title'] = 'Synchronisationsstatus';
$string['sync_status_connected'] = 'Mit Keycloak verbunden';
$string['sync_status_disconnected'] = 'Von Keycloak getrennt';
$string['sync_status_syncing'] = 'Synchronisation laeuft';
$string['sync_status_idle'] = 'Bereit';
$string['sync_status_error'] = 'Synchronisationsfehler';
$string['sync_status_lastrun'] = 'Letzte Synchronisation: {$a}';
$string['sync_status_nextrun'] = 'Naechste Synchronisation: {$a}';
$string['sync_status_never'] = 'Noch nie synchronisiert';
$string['connection_status'] = 'Verbindungsstatus';

// Sync-Statistiken
$string['sync_stats_title'] = 'Synchronisationsstatistiken';
$string['sync_stats_total_users'] = 'Keycloak-Benutzer gesamt';
$string['sync_stats_synced_users'] = 'Synchronisierte Benutzer';
$string['sync_stats_pending_users'] = 'Ausstehende Benutzer';
$string['sync_stats_failed_users'] = 'Fehlgeschlagene Benutzer';
$string['sync_stats_new_users'] = 'Neue Benutzer';
$string['sync_stats_updated_users'] = 'Aktualisierte Benutzer';
$string['sync_stats_disabled_users'] = 'Deaktivierte Benutzer';
$string['sync_stats_deleted_users'] = 'Geloeschte Benutzer';

// Sync-Vorschau
$string['sync_preview_title'] = 'Synchronisationsvorschau';
$string['sync_preview_description'] = 'Aenderungen vor der Synchronisation voranzeigen';
$string['sync_preview_create'] = 'Anzulegende Benutzer';
$string['sync_preview_update'] = 'Zu aktualisierende Benutzer';
$string['sync_preview_disable'] = 'Zu deaktivierende Benutzer';
$string['sync_preview_delete'] = 'Zu loeschende Benutzer';
$string['sync_preview_nochanges'] = 'Keine Aenderungen erkannt';
$string['sync_preview_changes'] = '{$a} Aenderungen erkannt';
$string['sync_preview_refresh'] = 'Vorschau aktualisieren';

// Sync-Optionen (Legacy)
$string['preview_mode'] = 'Vorschaumodus';
$string['preview_mode_help'] = 'Zeigt an, was passieren wuerde, ohne Aenderungen vorzunehmen.';
$string['sync_options'] = 'Synchronisationsoptionen';
$string['sync_new_users'] = 'Neue Benutzer synchronisieren';
$string['sync_existing_users'] = 'Bestehende Benutzer aktualisieren';
$string['sync_deletions'] = 'Loeschungen verarbeiten';
$string['start_sync_button'] = 'Synchronisation starten';
$string['preview_sync'] = 'Synchronisation voranzeigen';
$string['users_to_create'] = 'Anzulegende Benutzer';
$string['users_to_update'] = 'Zu aktualisierende Benutzer';
$string['users_to_delete'] = 'Zu loeschende Benutzer';
$string['sync_progress'] = 'Synchronisationsfortschritt';
$string['sync_complete'] = 'Synchronisation abgeschlossen';
$string['sync_failed'] = 'Synchronisation fehlgeschlagen';
$string['sync_results'] = 'Synchronisationsergebnisse';
$string['users_created'] = 'Angelegte Benutzer';
$string['users_updated'] = 'Aktualisierte Benutzer';
$string['users_deleted'] = 'Geloeschte Benutzer';
$string['users_skipped'] = 'Uebersprungene Benutzer';
$string['errors_occurred'] = 'Aufgetretene Fehler';
$string['no_changes_required'] = 'Keine Aenderungen erforderlich';
$string['last_sync_time'] = 'Letzte Synchronisation: {$a}';
$string['sync_not_configured'] = 'Die Keycloak-Synchronisation ist nicht konfiguriert. Bitte konfigurieren Sie zuerst die Keycloak-Einstellungen.';

// Sync-Optionen erweitert
$string['sync_options_title'] = 'Synchronisationsoptionen';
$string['sync_option_direction'] = 'Synchronisationsrichtung';
$string['sync_option_keycloak_to_moodle'] = 'Keycloak zu Moodle';
$string['sync_option_moodle_to_keycloak'] = 'Moodle zu Keycloak';
$string['sync_option_bidirectional'] = 'Bidirektional';
$string['sync_option_create_users'] = 'Neue Benutzer erstellen';
$string['sync_option_update_users'] = 'Bestehende Benutzer aktualisieren';
$string['sync_option_disable_missing'] = 'Fehlende Benutzer deaktivieren';
$string['sync_option_delete_missing'] = 'Fehlende Benutzer loeschen';
$string['sync_option_sync_roles'] = 'Rollen synchronisieren';
$string['sync_option_sync_groups'] = 'Gruppen synchronisieren';
$string['sync_option_sync_attributes'] = 'Benutzerdefinierte Attribute synchronisieren';

// Sync-Feldzuordnung
$string['sync_mapping_title'] = 'Feldzuordnung';
$string['sync_mapping_keycloak'] = 'Keycloak-Feld';
$string['sync_mapping_moodle'] = 'Moodle-Feld';
$string['sync_mapping_direction'] = 'Richtung';
$string['sync_mapping_transform'] = 'Transformation';
$string['sync_mapping_add'] = 'Zuordnung hinzufuegen';
$string['sync_mapping_remove'] = 'Zuordnung entfernen';

// Sync-Fortschritt
$string['sync_progress_title'] = 'Synchronisationsfortschritt';
$string['sync_progress_connecting'] = 'Verbindung zu Keycloak wird hergestellt...';
$string['sync_progress_fetching'] = 'Benutzer aus Keycloak werden abgerufen...';
$string['sync_progress_comparing'] = 'Benutzerdaten werden verglichen...';
$string['sync_progress_creating'] = 'Neue Benutzer werden erstellt...';
$string['sync_progress_updating'] = 'Bestehende Benutzer werden aktualisiert...';
$string['sync_progress_disabling'] = 'Fehlende Benutzer werden deaktiviert...';
$string['sync_progress_deleting'] = 'Benutzer werden geloescht...';
$string['sync_progress_syncing_roles'] = 'Rollen werden synchronisiert...';
$string['sync_progress_syncing_groups'] = 'Gruppen werden synchronisiert...';
$string['sync_progress_finalizing'] = 'Synchronisation wird abgeschlossen...';
$string['sync_progress_complete'] = 'Synchronisation abgeschlossen!';
$string['sync_progress_processed'] = '{$a->current} von {$a->total} Benutzern verarbeitet';
$string['sync_progress_percent'] = '{$a}% abgeschlossen';

// Sync-Ergebnisse
$string['sync_result_title'] = 'Synchronisationsergebnisse';
$string['sync_result_success'] = 'Synchronisation erfolgreich abgeschlossen';
$string['sync_result_partial'] = 'Synchronisation mit Fehlern abgeschlossen';
$string['sync_result_failed'] = 'Synchronisation fehlgeschlagen';
$string['sync_result_created'] = '{$a} Benutzer erstellt';
$string['sync_result_updated'] = '{$a} Benutzer aktualisiert';
$string['sync_result_disabled'] = '{$a} Benutzer deaktiviert';
$string['sync_result_deleted'] = '{$a} Benutzer geloescht';
$string['sync_result_skipped'] = '{$a} Benutzer uebersprungen';
$string['sync_result_errors'] = '{$a} Fehler aufgetreten';
$string['sync_result_duration'] = 'Dauer: {$a}';
$string['sync_result_viewlog'] = 'Synchronisationsprotokoll anzeigen';

// Sync-Fehler
$string['sync_error_connection'] = 'Verbindung zu Keycloak fehlgeschlagen';
$string['sync_error_authentication'] = 'Keycloak-Authentifizierung fehlgeschlagen';
$string['sync_error_permission'] = 'Unzureichende Berechtigungen in Keycloak';
$string['sync_error_timeout'] = 'Keycloak-Anfrage ist abgelaufen';
$string['sync_error_api'] = 'Keycloak-API-Fehler: {$a}';
$string['sync_error_user_create'] = 'Benutzer konnte nicht erstellt werden: {$a}';
$string['sync_error_user_update'] = 'Benutzer konnte nicht aktualisiert werden: {$a}';
$string['sync_error_user_delete'] = 'Benutzer konnte nicht geloescht werden: {$a}';
$string['sync_error_duplicate_email'] = 'Doppelte E-Mail-Adresse: {$a}';
$string['sync_error_invalid_data'] = 'Ungueltige Benutzerdaten';
$string['sync_error_unknown'] = 'Ein unbekannter Fehler ist bei der Synchronisation aufgetreten';

// Sync-Aktionen
$string['sync_action_start'] = 'Synchronisation starten';
$string['sync_action_stop'] = 'Synchronisation stoppen';
$string['sync_action_preview'] = 'Aenderungen voranzeigen';
$string['sync_action_fullsync'] = 'Vollstaendige Synchronisation';
$string['sync_action_incrementalsync'] = 'Inkrementelle Synchronisation';
$string['sync_action_schedule'] = 'Synchronisation planen';
$string['sync_action_configure'] = 'Synchronisation konfigurieren';
$string['sync_action_test'] = 'Verbindung testen';

// Sync-Planung
$string['sync_schedule_title'] = 'Geplante Synchronisation';
$string['sync_schedule_enabled'] = 'Geplante Synchronisation aktivieren';
$string['sync_schedule_frequency'] = 'Haeufigkeit';
$string['sync_schedule_hourly'] = 'Stuendlich';
$string['sync_schedule_daily'] = 'Taeglich';
$string['sync_schedule_weekly'] = 'Woechentlich';
$string['sync_schedule_custom'] = 'Benutzerdefiniert (Cron)';
$string['sync_schedule_cron'] = 'Cron-Ausdruck';
$string['sync_schedule_next'] = 'Naechster geplanter Lauf: {$a}';

// ============================================================================
// KEYCLOAK-EINSTELLUNGEN / EINRICHTUNGSASSISTENT
// ============================================================================
$string['keycloak_title'] = 'Keycloak-Konfiguration';
$string['keycloak_subtitle'] = 'Ihre Keycloak-Integration konfigurieren';
$string['keycloak_description'] = 'Verbindung zu Ihrem Keycloak-Server konfigurieren.';

// Einrichtungsassistent
$string['keycloak_wizard_title'] = 'Keycloak-Einrichtungsassistent';
$string['keycloak_wizard_intro'] = 'Dieser Assistent fuehrt Sie durch die Keycloak-Integrationseinrichtung.';
$string['keycloak_wizard_step1'] = 'Verbindungseinstellungen';
$string['keycloak_wizard_step2'] = 'Authentifizierung';
$string['keycloak_wizard_step3'] = 'Benutzerzuordnung';
$string['keycloak_wizard_step4'] = 'Rollenzuordnung';
$string['keycloak_wizard_step5'] = 'Verbindung testen';
$string['keycloak_wizard_step6'] = 'Abschluss';
$string['keycloak_wizard_next'] = 'Weiter';
$string['keycloak_wizard_previous'] = 'Zurueck';
$string['keycloak_wizard_finish'] = 'Einrichtung abschliessen';
$string['keycloak_wizard_skip'] = 'Assistent ueberspringen';
$string['keycloak_setup_wizard'] = 'Keycloak-Einrichtungsassistent';
$string['step'] = 'Schritt {$a}';
$string['step_server'] = 'Server-Einstellungen';
$string['step_client'] = 'Client-Einstellungen';
$string['step_sync'] = 'Synchronisationseinstellungen';
$string['step_test'] = 'Verbindung testen';

// Verbindungseinstellungen
$string['keycloak_server_url'] = 'Keycloak-Server-URL';
$string['keycloak_server_url_help'] = 'Die Basis-URL Ihres Keycloak-Servers (z.B. https://keycloak.example.com)';
$string['keycloak_realm'] = 'Realm';
$string['keycloak_realm_help'] = 'Der Keycloak-Realm fuer die Authentifizierung';
$string['keycloak_client_id'] = 'Client-ID';
$string['keycloak_client_id_help'] = 'Die in Keycloak registrierte Client-ID';
$string['keycloak_client_secret'] = 'Client-Geheimnis';
$string['keycloak_client_secret_help'] = 'Das Client-Geheimnis fuer die Authentifizierung';
$string['keycloak_admin_username'] = 'Admin-Benutzername';
$string['keycloak_admin_username_help'] = 'Admin-Konto fuer Keycloak-API-Zugriff';
$string['keycloak_admin_password'] = 'Admin-Passwort';
$string['keycloak_admin_password_help'] = 'Passwort fuer das Admin-Konto';
$string['keycloak_ssl_verify'] = 'SSL-Zertifikat verifizieren';
$string['keycloak_ssl_verify_help'] = 'SSL-Zertifikat des Keycloak-Servers verifizieren';
$string['keycloak_timeout'] = 'Verbindungs-Timeout';
$string['keycloak_timeout_help'] = 'Verbindungs-Timeout in Sekunden';

// Verbindungsstrings (Legacy)
$string['test_connection'] = 'Verbindung testen';
$string['connection_successful'] = 'Verbindung erfolgreich!';
$string['connection_failed'] = 'Verbindung fehlgeschlagen: {$a}';
$string['save_configuration'] = 'Konfiguration speichern';
$string['configuration_saved'] = 'Konfiguration erfolgreich gespeichert';
$string['previous_step'] = 'Zurueck';
$string['next_step'] = 'Weiter';
$string['finish_setup'] = 'Einrichtung abschliessen';

// Authentifizierungseinstellungen
$string['keycloak_auth_method'] = 'Authentifizierungsmethode';
$string['keycloak_auth_client_credentials'] = 'Client-Anmeldedaten';
$string['keycloak_auth_password'] = 'Resource Owner Password';
$string['keycloak_auth_token'] = 'Service-Account-Token';
$string['keycloak_scope'] = 'OAuth-Bereiche';
$string['keycloak_scope_help'] = 'Anzufragende OAuth-Bereiche (kommagetrennt)';

// Benutzerzuordnung
$string['keycloak_user_mapping_title'] = 'Benutzerattribut-Zuordnung';
$string['keycloak_map_username'] = 'Benutzername-Feld';
$string['keycloak_map_email'] = 'E-Mail-Feld';
$string['keycloak_map_firstname'] = 'Vorname-Feld';
$string['keycloak_map_lastname'] = 'Nachname-Feld';
$string['keycloak_map_idnumber'] = 'ID-Nummer-Feld';
$string['keycloak_map_department'] = 'Abteilungs-Feld';
$string['keycloak_map_institution'] = 'Institutions-Feld';
$string['keycloak_map_phone'] = 'Telefon-Feld';
$string['keycloak_map_address'] = 'Adress-Feld';
$string['keycloak_map_city'] = 'Stadt-Feld';
$string['keycloak_map_country'] = 'Land-Feld';
$string['keycloak_custom_attributes'] = 'Benutzerdefinierte Attribute';
$string['keycloak_custom_attributes_help'] = 'Zusaetzliche Keycloak-Attribute den Moodle-Profilfeldern zuordnen';

// Rollenzuordnung
$string['keycloak_role_mapping_title'] = 'Rollenzuordnung';
$string['keycloak_role_mapping_description'] = 'Keycloak-Rollen den Moodle-Rollen zuordnen';
$string['keycloak_role_source'] = 'Keycloak-Rolle';
$string['keycloak_role_target'] = 'Moodle-Rolle';
$string['keycloak_role_context'] = 'Kontext';
$string['keycloak_role_add'] = 'Rollenzuordnung hinzufuegen';
$string['keycloak_role_remove'] = 'Zuordnung entfernen';
$string['keycloak_sync_realm_roles'] = 'Realm-Rollen synchronisieren';
$string['keycloak_sync_client_roles'] = 'Client-Rollen synchronisieren';
$string['keycloak_default_role'] = 'Standard-Moodle-Rolle';
$string['keycloak_default_role_help'] = 'Standardrolle fuer Benutzer ohne spezifische Rollenzuordnung';

// Gruppenzuordnung
$string['keycloak_group_mapping_title'] = 'Gruppenzuordnung';
$string['keycloak_group_mapping_description'] = 'Keycloak-Gruppen den Moodle-globalen Gruppen zuordnen';
$string['keycloak_sync_groups'] = 'Gruppen synchronisieren';
$string['keycloak_group_source'] = 'Keycloak-Gruppe';
$string['keycloak_group_target'] = 'Moodle-globale Gruppe';
$string['keycloak_group_prefix'] = 'Gruppen-Praefix-Filter';
$string['keycloak_auto_create_cohorts'] = 'Globale Gruppen automatisch erstellen';
$string['keycloak_auto_create_cohorts_help'] = 'Globale Gruppen fuer neue Keycloak-Gruppen automatisch erstellen';

// Verbindung testen
$string['keycloak_test_title'] = 'Verbindung testen';
$string['keycloak_test_description'] = 'Verbindung zu Ihrem Keycloak-Server testen';
$string['keycloak_test_button'] = 'Verbindung testen';
$string['keycloak_test_success'] = 'Verbindung erfolgreich!';
$string['keycloak_test_failed'] = 'Verbindung fehlgeschlagen: {$a}';
$string['keycloak_test_users_found'] = '{$a} Benutzer in Keycloak gefunden';
$string['keycloak_test_roles_found'] = '{$a} Rollen in Keycloak gefunden';
$string['keycloak_test_groups_found'] = '{$a} Gruppen in Keycloak gefunden';

// Einrichtung abgeschlossen
$string['keycloak_setup_complete'] = 'Keycloak-Einrichtung abgeschlossen';
$string['keycloak_setup_success'] = 'Ihre Keycloak-Integration ist jetzt konfiguriert.';
$string['keycloak_setup_next_steps'] = 'Naechste Schritte';
$string['keycloak_setup_run_sync'] = 'Erste Synchronisation durchfuehren';
$string['keycloak_setup_configure_schedule'] = 'Geplante Synchronisation konfigurieren';
$string['keycloak_setup_view_users'] = 'Synchronisierte Benutzer anzeigen';

// ============================================================================
// EINSTELLUNGEN
// ============================================================================
$string['settings_title'] = 'edulution-Einstellungen';
$string['settings_subtitle'] = 'Plugin-Einstellungen konfigurieren';
$string['settings_saved'] = 'Einstellungen erfolgreich gespeichert';
$string['settings_error'] = 'Fehler beim Speichern der Einstellungen';
$string['settings_description'] = 'edulution-Plugin-Einstellungen konfigurieren.';

// Allgemeine Einstellungen
$string['settings_general'] = 'Allgemeine Einstellungen';
$string['settings_enabled'] = 'Plugin aktivieren';
$string['settings_enabled_help'] = 'edulution-Plugin aktivieren oder deaktivieren';
$string['settings_debug'] = 'Debug-Modus';
$string['settings_debug_help'] = 'Ausfuehrliches Logging fuer Fehlersuche aktivieren';
$string['settings_log_level'] = 'Log-Level';
$string['settings_log_level_help'] = 'Minimales Log-Level festlegen';
$string['settings_log_level_error'] = 'Fehler';
$string['settings_log_level_warning'] = 'Warnung';
$string['settings_log_level_info'] = 'Info';
$string['settings_log_level_debug'] = 'Debug';
$string['settings_log_retention'] = 'Log-Aufbewahrungstage';
$string['settings_log_retention_help'] = 'Anzahl der Tage, die Log-Eintraege aufbewahrt werden';

// Einstellungen Legacy
$string['general_settings'] = 'Allgemeine Einstellungen';
$string['enable_plugin'] = 'Plugin aktivieren';
$string['enable_plugin_help'] = 'Aktivieren oder deaktivieren Sie die edulution-Plugin-Funktionalitaet.';
$string['export_settings'] = 'Export-Einstellungen';
$string['export_directory'] = 'Export-Verzeichnis';
$string['export_directory_help'] = 'Verzeichnis, in dem Exportdateien gespeichert werden.';
$string['export_retention'] = 'Export-Aufbewahrung (Tage)';
$string['export_retention_help'] = 'Anzahl der Tage, die Exportdateien vor der Bereinigung aufbewahrt werden.';
$string['sync_settings'] = 'Synchronisationseinstellungen';
$string['enable_keycloak_sync'] = 'Keycloak-Synchronisation aktivieren';
$string['enable_keycloak_sync_help'] = 'Automatische Synchronisation mit Keycloak aktivieren.';
$string['sync_patterns'] = 'Synchronisationsmuster';
$string['sync_patterns_help'] = 'Regulaere Ausdruecke zum Filtern der zu synchronisierenden Benutzer.';
$string['user_pattern'] = 'Benutzermuster';
$string['user_pattern_help'] = 'Regex-Muster fuer Benutzernamen (z.B. ^schueler_.*)';
$string['email_pattern'] = 'E-Mail-Muster';
$string['email_pattern_help'] = 'Regex-Muster fuer E-Mail-Adressen';
$string['category_mappings'] = 'Kategoriezuordnungen';
$string['category_mappings_help'] = 'Ordnen Sie Keycloak-Gruppen Moodle-Kurskategorien zu.';
$string['keycloak_group'] = 'Keycloak-Gruppe';
$string['moodle_category'] = 'Moodle-Kategorie';
$string['add_mapping'] = 'Zuordnung hinzufuegen';
$string['remove_mapping'] = 'Entfernen';
$string['blacklist_settings'] = 'Sperrlisten-Einstellungen';
$string['user_blacklist'] = 'Benutzer-Sperrliste';
$string['user_blacklist_help'] = 'Liste von Benutzernamen, die von der Synchronisation ausgeschlossen werden (einer pro Zeile).';
$string['email_blacklist'] = 'E-Mail-Sperrliste';
$string['email_blacklist_help'] = 'Liste von E-Mail-Adressen/Domains, die ausgeschlossen werden (einer pro Zeile).';
$string['save_settings'] = 'Einstellungen speichern';

// Export-Einstellungen
$string['settings_export'] = 'Export-Einstellungen';
$string['settings_export_enabled'] = 'Export aktivieren';
$string['settings_export_path'] = 'Export-Pfad';
$string['settings_export_path_help'] = 'Verzeichnispfad fuer die Speicherung von Exportdateien';
$string['settings_export_format'] = 'Standard-Export-Format';
$string['settings_export_compress'] = 'Exporte komprimieren';
$string['settings_export_max_records'] = 'Max. Datensaetze pro Export';
$string['settings_export_max_records_help'] = 'Maximale Anzahl von Datensaetzen pro Exportdatei';
$string['settings_export_cleanup'] = 'Exporte automatisch bereinigen';
$string['settings_export_cleanup_help'] = 'Alte Exportdateien automatisch loeschen';
$string['settings_export_cleanup_days'] = 'Bereinigung nach Tagen';

// Import-Einstellungen
$string['settings_import'] = 'Import-Einstellungen';
$string['settings_import_enabled'] = 'Import aktivieren';
$string['settings_import_path'] = 'Import-Pfad';
$string['settings_import_path_help'] = 'Verzeichnispfad fuer Importdateien';
$string['settings_import_max_size'] = 'Max. Dateigroesse (MB)';
$string['settings_import_max_size_help'] = 'Maximale Dateigroesse fuer Importe';
$string['settings_import_batch_size'] = 'Stapelgroesse';
$string['settings_import_batch_size_help'] = 'Anzahl der Datensaetze, die pro Stapel verarbeitet werden';
$string['settings_import_timeout'] = 'Import-Timeout (Sekunden)';
$string['settings_import_allowed_types'] = 'Erlaubte Dateitypen';

// Keycloak-Einstellungen
$string['settings_keycloak'] = 'Keycloak-Einstellungen';
$string['settings_keycloak_enabled'] = 'Keycloak-Integration aktivieren';
$string['settings_keycloak_auto_sync'] = 'Auto-Sync bei Anmeldung';
$string['settings_keycloak_auto_sync_help'] = 'Benutzerdaten automatisch bei Anmeldung synchronisieren';
$string['settings_keycloak_create_users'] = 'Benutzer automatisch erstellen';
$string['settings_keycloak_create_users_help'] = 'Moodle-Benutzer automatisch aus Keycloak erstellen';
$string['settings_keycloak_update_users'] = 'Benutzer automatisch aktualisieren';
$string['settings_keycloak_update_users_help'] = 'Benutzerdaten automatisch aus Keycloak aktualisieren';
$string['settings_keycloak_sync_interval'] = 'Synchronisationsintervall (Minuten)';
$string['settings_keycloak_sync_batch'] = 'Synchronisations-Stapelgroesse';

// Benachrichtigungseinstellungen
$string['settings_notifications'] = 'Benachrichtigungseinstellungen';
$string['settings_notify_admin'] = 'Admin benachrichtigen';
$string['settings_notify_admin_help'] = 'Benachrichtigungen an Administratoren senden';
$string['settings_notify_email'] = 'Benachrichtigungs-E-Mail';
$string['settings_notify_email_help'] = 'E-Mail-Adresse fuer Benachrichtigungen';
$string['settings_notify_on_error'] = 'Bei Fehler benachrichtigen';
$string['settings_notify_on_success'] = 'Bei Erfolg benachrichtigen';
$string['settings_notify_on_sync'] = 'Bei Synchronisationsabschluss benachrichtigen';
$string['settings_notify_on_export'] = 'Bei Exportabschluss benachrichtigen';
$string['settings_notify_on_import'] = 'Bei Importabschluss benachrichtigen';

// Leistungseinstellungen
$string['settings_performance'] = 'Leistungseinstellungen';
$string['settings_cache_enabled'] = 'Caching aktivieren';
$string['settings_cache_ttl'] = 'Cache-TTL (Sekunden)';
$string['settings_cache_ttl_help'] = 'Lebensdauer fuer gecachte Daten';
$string['settings_async_enabled'] = 'Asynchrone Verarbeitung aktivieren';
$string['settings_async_enabled_help'] = 'Grosse Vorgaenge asynchron verarbeiten';
$string['settings_max_execution_time'] = 'Max. Ausfuehrungszeit (Sekunden)';
$string['settings_memory_limit'] = 'Speicherlimit (MB)';

// Sicherheitseinstellungen
$string['settings_security'] = 'Sicherheitseinstellungen';
$string['settings_encrypt_exports'] = 'Exporte verschluesseln';
$string['settings_encrypt_exports_help'] = 'Exportierte Dateien mit AES-256 verschluesseln';
$string['settings_encryption_key'] = 'Verschluesselungsschluessel';
$string['settings_encryption_key_help'] = 'Schluessel zum Ver-/Entschluesseln von Daten';
$string['settings_api_key'] = 'API-Schluessel';
$string['settings_api_key_help'] = 'API-Schluessel fuer externe Integrationen';
$string['settings_allowed_ips'] = 'Erlaubte IP-Adressen';
$string['settings_allowed_ips_help'] = 'IP-Adressen, die auf die API zugreifen duerfen';
$string['settings_rate_limit'] = 'API-Ratenlimit';
$string['settings_rate_limit_help'] = 'Maximale API-Anfragen pro Minute';

// settings.php-Strings
$string['keycloaksync'] = 'Keycloak-Synchronisation';
$string['keycloaksettings'] = 'Keycloak-Einstellungen';
$string['keycloaksettings_desc'] = 'Konfigurieren Sie die Verbindung zu Ihrem Keycloak-Identitaetsserver.';
$string['generalsettings'] = 'Allgemeine Einstellungen';
$string['generalsettings_desc'] = 'Allgemeine Plugin-Konfigurationsoptionen.';
$string['enabled'] = 'edulution aktivieren';
$string['enabled_desc'] = 'Aktivieren oder deaktivieren Sie die edulution-Plugin-Funktionalitaet.';
$string['keycloak_sync_enabled'] = 'Keycloak-Synchronisation aktivieren';
$string['keycloak_sync_enabled_desc'] = 'Automatische Benutzersynchronisation mit Keycloak aktivieren.';
$string['keycloak_url'] = 'Keycloak-Server-URL';
$string['keycloak_url_desc'] = 'Die Basis-URL Ihres Keycloak-Servers (z.B. https://keycloak.example.com).';
$string['keycloak_realm_desc'] = 'Der Keycloak-Realm fuer die Authentifizierung.';
$string['keycloak_client_id_desc'] = 'Die in Keycloak konfigurierte OAuth2-Client-ID.';
$string['keycloak_client_secret_desc'] = 'Das Client-Geheimnis fuer den OAuth2-Client.';
$string['exportimportsettings'] = 'Export/Import-Einstellungen';
$string['exportimportsettings_desc'] = 'Konfigurieren Sie Dateipfade und Optionen fuer Export und Import.';
$string['export_path'] = 'Export-Pfad';
$string['export_path_desc'] = 'Verzeichnispfad, in dem Exportdateien gespeichert werden.';
$string['import_path'] = 'Import-Pfad';
$string['import_path_desc'] = 'Verzeichnispfad fuer Importdateien.';
$string['export_retention_days'] = 'Export-Aufbewahrung (Tage)';
$string['export_retention_days_desc'] = 'Anzahl der Tage, die Exportdateien vor der automatischen Bereinigung aufbewahrt werden.';

// ============================================================================
// BERICHTE
// ============================================================================
$string['reports_title'] = 'Berichte';
$string['reports_subtitle'] = 'Detaillierte Berichte und Analysen anzeigen';
$string['reports_description'] = 'Synchronisations- und Export-Verlauf sowie Protokolle anzeigen.';

// Berichtstypen
$string['report_sync_history'] = 'Synchronisationsverlauf';
$string['report_export_history'] = 'Export-Verlauf';
$string['report_import_history'] = 'Import-Verlauf';
$string['report_user_activity'] = 'Benutzeraktivitaet';
$string['report_error_log'] = 'Fehlerprotokoll';
$string['report_performance'] = 'Leistungsbericht';
$string['report_audit'] = 'Pruefprotokoll';
$string['sync_history'] = 'Synchronisationsverlauf';

// Reports summary strings
$string['summary'] = 'Zusammenfassung';
$string['total_syncs'] = 'Synchronisierungen';
$string['total_exports'] = 'Exporte';
$string['total_errors'] = 'Fehler';
$string['successful'] = 'erfolgreich';
$string['run_sync'] = 'Sync starten';
$string['error_details'] = 'Fehlerdetails';
$string['filename'] = 'Dateiname';
$string['unavailable'] = 'nicht verfügbar';
$string['export_history'] = 'Export-Verlauf';
$string['error_logs'] = 'Fehlerprotokolle';

// Berichtsfilter
$string['report_filter_daterange'] = 'Datumsbereich';
$string['report_filter_status'] = 'Status';
$string['report_filter_type'] = 'Typ';
$string['report_filter_user'] = 'Benutzer';
$string['report_filter_apply'] = 'Filter anwenden';
$string['report_filter_clear'] = 'Filter loeschen';
$string['filter_by_date'] = 'Nach Datum filtern';
$string['filter_by_type'] = 'Nach Typ filtern';
$string['filter_by_status'] = 'Nach Status filtern';
$string['clear_filters'] = 'Filter zuruecksetzen';
$string['export_report'] = 'Bericht exportieren';

// Berichtsspalten
$string['report_col_date'] = 'Datum';
$string['report_col_time'] = 'Uhrzeit';
$string['report_col_type'] = 'Typ';
$string['report_col_status'] = 'Status';
$string['report_col_user'] = 'Benutzer';
$string['report_col_details'] = 'Details';
$string['report_col_duration'] = 'Dauer';
$string['report_col_records'] = 'Datensaetze';
$string['report_col_errors'] = 'Fehler';
$string['report_col_action'] = 'Aktion';
$string['date'] = 'Datum';
$string['type'] = 'Typ';
$string['status'] = 'Status';
$string['details'] = 'Details';
$string['duration'] = 'Dauer';
$string['file_size'] = 'Dateigroesse';
$string['success'] = 'Erfolgreich';
$string['failed'] = 'Fehlgeschlagen';
$string['partial'] = 'Teilweise';
$string['pending'] = 'Ausstehend';
$string['running'] = 'Laeuft';
$string['view_details'] = 'Details anzeigen';
$string['download'] = 'Herunterladen';
$string['delete'] = 'Loeschen';
$string['no_records'] = 'Keine Eintraege gefunden';

// Berichtsaktionen
$string['report_download'] = 'Bericht herunterladen';
$string['report_print'] = 'Bericht drucken';
$string['report_email'] = 'Bericht per E-Mail senden';
$string['report_schedule'] = 'Bericht planen';
$string['report_refresh'] = 'Aktualisieren';

// ============================================================================
// CLI-MELDUNGEN
// ============================================================================
$string['cli_export_start'] = 'Export wird gestartet...';
$string['cli_export_complete'] = 'Export erfolgreich abgeschlossen';
$string['cli_export_failed'] = 'Export fehlgeschlagen: {$a}';
$string['cli_import_start'] = 'Import wird gestartet...';
$string['cli_import_complete'] = 'Import erfolgreich abgeschlossen';
$string['cli_import_failed'] = 'Import fehlgeschlagen: {$a}';
$string['cli_sync_start'] = 'Synchronisation wird gestartet...';
$string['cli_sync_complete'] = 'Synchronisation erfolgreich abgeschlossen';
$string['cli_sync_failed'] = 'Synchronisation fehlgeschlagen: {$a}';
$string['cli_processing'] = 'Verarbeite {$a->current} von {$a->total}...';
$string['cli_progress'] = 'Fortschritt: {$a}%';
$string['cli_error'] = 'Fehler: {$a}';
$string['cli_warning'] = 'Warnung: {$a}';
$string['cli_info'] = 'Info: {$a}';
$string['cli_usage'] = 'Verwendung: {$a}';
$string['cli_help'] = 'Verwenden Sie --help fuer weitere Informationen';
$string['cli_option_help'] = 'Diese Hilfemeldung anzeigen';
$string['cli_option_verbose'] = 'Ausfuehrliche Ausgabe aktivieren';
$string['cli_option_quiet'] = 'Ausgabe unterdruecken';
$string['cli_option_dryrun'] = 'Testlauf (keine Aenderungen)';
$string['cli_option_force'] = 'Vorgang erzwingen';
$string['cli_option_format'] = 'Ausgabeformat (json, csv, table)';
$string['cli_option_output'] = 'Ausgabedateipfad';
$string['cli_option_type'] = 'Zu verarbeitender Datentyp';
$string['cli_option_limit'] = 'Anzahl der Datensaetze begrenzen';
$string['cli_option_offset'] = 'Datensaetze ueberspringen';
$string['cli_invalid_option'] = 'Ungueltige Option: {$a}';
$string['cli_missing_argument'] = 'Fehlendes erforderliches Argument: {$a}';
$string['cli_file_not_found'] = 'Datei nicht gefunden: {$a}';
$string['cli_directory_not_found'] = 'Verzeichnis nicht gefunden: {$a}';
$string['cli_permission_denied'] = 'Zugriff verweigert: {$a}';

// ============================================================================
// FEHLERMELDUNGEN
// ============================================================================
$string['error_general'] = 'Ein Fehler ist aufgetreten';
$string['error_unexpected'] = 'Ein unerwarteter Fehler ist aufgetreten';
$string['error_permission_denied'] = 'Zugriff verweigert';
$string['error_not_found'] = 'Ressource nicht gefunden';
$string['error_invalid_request'] = 'Ungueltige Anfrage';
$string['error_invalid_data'] = 'Ungueltige Daten bereitgestellt';
$string['error_missing_data'] = 'Erforderliche Daten fehlen';
$string['error_database'] = 'Datenbankfehler';
$string['error_connection'] = 'Verbindungsfehler';
$string['error_timeout'] = 'Vorgang ist abgelaufen';
$string['error_file_read'] = 'Datei konnte nicht gelesen werden';
$string['error_file_write'] = 'Datei konnte nicht geschrieben werden';
$string['error_file_delete'] = 'Datei konnte nicht geloescht werden';
$string['error_directory_create'] = 'Verzeichnis konnte nicht erstellt werden';
$string['error_api'] = 'API-Fehler: {$a}';
$string['error_authentication'] = 'Authentifizierung fehlgeschlagen';
$string['error_authorization'] = 'Autorisierung fehlgeschlagen';
$string['error_configuration'] = 'Konfigurationsfehler';
$string['error_plugin_disabled'] = 'Plugin ist deaktiviert';
$string['error_feature_disabled'] = 'Diese Funktion ist deaktiviert';
$string['error_maintenance'] = 'System befindet sich im Wartungsmodus';
$string['error_quota_exceeded'] = 'Kontingent ueberschritten';
$string['error_rate_limit'] = 'Ratenlimit ueberschritten';
$string['error_session_expired'] = 'Sitzung abgelaufen';
$string['error_try_again'] = 'Bitte versuchen Sie es spaeter erneut';
$string['error_contact_admin'] = 'Bitte kontaktieren Sie den Administrator';

// Fehlermeldungen (Legacy)
$string['error_no_permission'] = 'Sie haben keine Berechtigung, diese Aktion durchzufuehren';
$string['error_export_failed'] = 'Export fehlgeschlagen: {$a}';
$string['error_import_failed'] = 'Import fehlgeschlagen: {$a}';
$string['error_sync_failed'] = 'Synchronisation fehlgeschlagen: {$a}';
$string['error_keycloak_connection'] = 'Verbindung zu Keycloak konnte nicht hergestellt werden: {$a}';
$string['error_file_not_found'] = 'Datei nicht gefunden';
$string['error_directory_not_writable'] = 'Verzeichnis ist nicht beschreibbar: {$a}';
$string['error_invalid_file'] = 'Ungueltiges Dateiformat';

// ============================================================================
// ERFOLGSMELDUNGEN
// ============================================================================
$string['success_general'] = 'Vorgang erfolgreich abgeschlossen';
$string['success_saved'] = 'Aenderungen erfolgreich gespeichert';
$string['success_created'] = 'Datensatz erfolgreich erstellt';
$string['success_updated'] = 'Datensatz erfolgreich aktualisiert';
$string['success_deleted'] = 'Datensatz erfolgreich geloescht';
$string['success_imported'] = 'Daten erfolgreich importiert';
$string['success_exported'] = 'Daten erfolgreich exportiert';
$string['success_synced'] = 'Daten erfolgreich synchronisiert';
$string['success_connected'] = 'Erfolgreich verbunden';
$string['success_disconnected'] = 'Erfolgreich getrennt';
$string['success_scheduled'] = 'Aufgabe erfolgreich geplant';
$string['success_cancelled'] = 'Vorgang abgebrochen';
$string['success_enabled'] = 'Funktion aktiviert';
$string['success_disabled'] = 'Funktion deaktiviert';
$string['success_configuration'] = 'Konfiguration gespeichert';
$string['success_test_passed'] = 'Test bestanden';

// ============================================================================
// VALIDIERUNGSMELDUNGEN
// ============================================================================
$string['validation_required'] = 'Dieses Feld ist erforderlich';
$string['validation_email'] = 'Bitte geben Sie eine gueltige E-Mail-Adresse ein';
$string['validation_url'] = 'Bitte geben Sie eine gueltige URL ein';
$string['validation_number'] = 'Bitte geben Sie eine gueltige Zahl ein';
$string['validation_integer'] = 'Bitte geben Sie eine ganze Zahl ein';
$string['validation_positive'] = 'Bitte geben Sie eine positive Zahl ein';
$string['validation_min'] = 'Wert muss mindestens {$a} sein';
$string['validation_max'] = 'Wert darf hoechstens {$a} sein';
$string['validation_minlength'] = 'Mindestlaenge ist {$a} Zeichen';
$string['validation_maxlength'] = 'Maximale Laenge ist {$a} Zeichen';
$string['validation_pattern'] = 'Wert entspricht nicht dem erforderlichen Format';
$string['validation_unique'] = 'Dieser Wert existiert bereits';
$string['validation_exists'] = 'Dieser Wert existiert nicht';
$string['validation_date'] = 'Bitte geben Sie ein gueltiges Datum ein';
$string['validation_dateformat'] = 'Datumsformat sollte {$a} sein';
$string['validation_datefuture'] = 'Datum muss in der Zukunft liegen';
$string['validation_datepast'] = 'Datum muss in der Vergangenheit liegen';
$string['validation_file_required'] = 'Bitte waehlen Sie eine Datei aus';
$string['validation_file_type'] = 'Ungueltiger Dateityp. Erlaubte Typen: {$a}';
$string['validation_file_size'] = 'Datei ist zu gross. Maximale Groesse: {$a}';
$string['validation_file_empty'] = 'Datei ist leer';
$string['validation_json'] = 'Ungueltiges JSON-Format';
$string['validation_xml'] = 'Ungueltiges XML-Format';
$string['validation_csv'] = 'Ungueltiges CSV-Format';
$string['validation_username'] = 'Ungueltiges Benutzernamenformat';
$string['validation_password'] = 'Passwort erfuellt nicht die Anforderungen';
$string['validation_confirm'] = 'Werte stimmen nicht ueberein';
$string['validation_ip'] = 'Bitte geben Sie eine gueltige IP-Adresse ein';
$string['validation_port'] = 'Bitte geben Sie eine gueltige Portnummer ein (1-65535)';

// ============================================================================
// BESTAETIGUNGSMELDUNGEN
// ============================================================================
$string['confirm_delete'] = 'Sind Sie sicher, dass Sie dies loeschen moechten?';
$string['confirm_delete_multiple'] = 'Sind Sie sicher, dass Sie {$a} Elemente loeschen moechten?';
$string['confirm_cancel'] = 'Sind Sie sicher, dass Sie abbrechen moechten?';
$string['confirm_discard'] = 'Sind Sie sicher, dass Sie die Aenderungen verwerfen moechten?';
$string['confirm_overwrite'] = 'Vorhandene Daten werden ueberschrieben. Fortfahren?';
$string['confirm_sync'] = 'Sind Sie sicher, dass Sie die Synchronisation starten moechten?';
$string['confirm_export'] = 'Sind Sie sicher, dass Sie den Export starten moechten?';
$string['confirm_import'] = 'Sind Sie sicher, dass Sie den Import starten moechten?';
$string['confirm_reset'] = 'Sind Sie sicher, dass Sie auf die Standardwerte zuruecksetzen moechten?';
$string['confirm_enable'] = 'Sind Sie sicher, dass Sie dies aktivieren moechten?';
$string['confirm_disable'] = 'Sind Sie sicher, dass Sie dies deaktivieren moechten?';
$string['confirm_delete_export'] = 'Sind Sie sicher, dass Sie diesen Export loeschen moechten?';
$string['confirm_start_sync'] = 'Sind Sie sicher, dass Sie den Synchronisationsprozess starten moechten?';
$string['confirm_cancel_operation'] = 'Sind Sie sicher, dass Sie diesen Vorgang abbrechen moechten?';

// ============================================================================
// ALLGEMEIN / UI-ELEMENTE
// ============================================================================
$string['yes'] = 'Ja';
$string['no'] = 'Nein';
$string['ok'] = 'OK';
$string['cancel'] = 'Abbrechen';
$string['save'] = 'Speichern';
$string['save_changes'] = 'Aenderungen speichern';
$string['apply'] = 'Anwenden';
$string['close'] = 'Schliessen';
$string['edit'] = 'Bearbeiten';
$string['view'] = 'Anzeigen';
$string['add'] = 'Hinzufuegen';
$string['remove'] = 'Entfernen';
$string['create'] = 'Erstellen';
$string['update'] = 'Aktualisieren';
$string['search'] = 'Suchen';
$string['filter'] = 'Filtern';
$string['clear'] = 'Loeschen';
$string['reset'] = 'Zuruecksetzen';
$string['refresh'] = 'Aktualisieren';
$string['upload'] = 'Hochladen';
$string['start'] = 'Starten';
$string['stop'] = 'Stoppen';
$string['pause'] = 'Pausieren';
$string['resume'] = 'Fortsetzen';
$string['retry'] = 'Wiederholen';
$string['skip'] = 'Ueberspringen';
$string['back'] = 'Zurueck';
$string['next'] = 'Weiter';
$string['previous'] = 'Zurueck';
$string['first'] = 'Erste';
$string['last'] = 'Letzte';
$string['finish'] = 'Abschliessen';
$string['done'] = 'Fertig';
$string['enable'] = 'Aktivieren';
$string['disable'] = 'Deaktivieren';
$string['active'] = 'Aktiv';
$string['inactive'] = 'Inaktiv';
$string['name'] = 'Name';
$string['description'] = 'Beschreibung';
$string['time'] = 'Uhrzeit';
$string['datetime'] = 'Datum/Uhrzeit';
$string['created'] = 'Erstellt';
$string['modified'] = 'Geaendert';
$string['user'] = 'Benutzer';
$string['users'] = 'Benutzer';
$string['course'] = 'Kurs';
$string['courses'] = 'Kurse';
$string['category'] = 'Kategorie';
$string['categories'] = 'Kategorien';
$string['group'] = 'Gruppe';
$string['groups'] = 'Gruppen';
$string['role'] = 'Rolle';
$string['roles'] = 'Rollen';
$string['all'] = 'Alle';
$string['none'] = 'Keine';
$string['select'] = 'Auswaehlen';
$string['select_all'] = 'Alle auswaehlen';
$string['deselect_all'] = 'Alle abwaehlen';
$string['loading'] = 'Wird geladen...';
$string['processing'] = 'Wird verarbeitet...';
$string['please_wait'] = 'Bitte warten...';
$string['no_data'] = 'Keine Daten verfuegbar';
$string['no_results'] = 'Keine Ergebnisse gefunden';
$string['showing'] = 'Zeige {$a->start} bis {$a->end} von {$a->total}';
$string['page'] = 'Seite';
$string['of'] = 'von';
$string['items_per_page'] = 'Eintraege pro Seite';
$string['total'] = 'Gesamt';
$string['actions'] = 'Aktionen';
$string['options'] = 'Optionen';
$string['more'] = 'Mehr';
$string['less'] = 'Weniger';
$string['expand'] = 'Erweitern';
$string['collapse'] = 'Zuklappen';
$string['show'] = 'Anzeigen';
$string['hide'] = 'Verbergen';
$string['required'] = 'Erforderlich';
$string['optional'] = 'Optional';
$string['default'] = 'Standard';
$string['custom'] = 'Benutzerdefiniert';
$string['advanced'] = 'Erweitert';
$string['basic'] = 'Grundlegend';
$string['help'] = 'Hilfe';
$string['info'] = 'Information';
$string['warning'] = 'Warnung';
$string['error'] = 'Fehler';
$string['complete'] = 'Abgeschlossen';
$string['incomplete'] = 'Unvollstaendig';
$string['unknown'] = 'Unbekannt';
$string['new'] = 'Neu';
$string['old'] = 'Alt';
$string['current'] = 'Aktuell';
$string['version'] = 'Version';
$string['id'] = 'ID';
$string['preview'] = 'Vorschau';
$string['configure'] = 'Konfigurieren';
$string['test'] = 'Testen';
$string['copy'] = 'Kopieren';
$string['copied'] = 'Kopiert!';
$string['sort'] = 'Sortieren';
$string['sort_asc'] = 'Aufsteigend sortieren';
$string['sort_desc'] = 'Absteigend sortieren';
$string['more_info'] = 'Weitere Informationen';

// ============================================================================
// ZEITEINHEITEN
// ============================================================================
$string['second'] = 'Sekunde';
$string['seconds'] = 'Sekunden';
$string['minute'] = 'Minute';
$string['minutes'] = 'Minuten';
$string['hour'] = 'Stunde';
$string['hours'] = 'Stunden';
$string['day'] = 'Tag';
$string['days'] = 'Tage';
$string['week'] = 'Woche';
$string['weeks'] = 'Wochen';
$string['month'] = 'Monat';
$string['months'] = 'Monate';
$string['year'] = 'Jahr';
$string['years'] = 'Jahre';

// Zeitraeume
$string['today'] = 'Heute';
$string['yesterday'] = 'Gestern';
$string['last_7_days'] = 'Letzte 7 Tage';
$string['last_30_days'] = 'Letzte 30 Tage';
$string['all_time'] = 'Gesamter Zeitraum';

// ============================================================================
// AUFGABEN-STRINGS
// ============================================================================
$string['task_sync_users'] = 'Benutzer mit Keycloak synchronisieren';
$string['task_export_data'] = 'Geplante Daten exportieren';
$string['task_import_data'] = 'Geplante Daten importieren';
$string['task_cleanup_logs'] = 'Alte Log-Eintraege bereinigen';
$string['task_cleanup_exports'] = 'Alte Exportdateien bereinigen';
$string['task_send_notifications'] = 'Ausstehende Benachrichtigungen senden';
$string['task_update_statistics'] = 'Dashboard-Statistiken aktualisieren';

// ============================================================================
// DATENSCHUTZ-API
// ============================================================================
$string['privacy:metadata:local_edulution_sync'] = 'Informationen ueber die Benutzersynchronisation mit Keycloak';
$string['privacy:metadata:local_edulution_sync:userid'] = 'Die ID des synchronisierten Benutzers';
$string['privacy:metadata:local_edulution_sync:keycloakid'] = 'Die Keycloak-Benutzer-ID';
$string['privacy:metadata:local_edulution_sync:synced'] = 'Zeitpunkt der letzten Synchronisation des Benutzers';
$string['privacy:metadata:local_edulution_export'] = 'Informationen ueber Datenexporte';
$string['privacy:metadata:local_edulution_export:userid'] = 'Die ID des Benutzers, der den Export durchgefuehrt hat';
$string['privacy:metadata:local_edulution_export:timecreated'] = 'Zeitpunkt der Export-Erstellung';
$string['privacy:metadata:local_edulution_log'] = 'Aktivitaetsprotokoll-Informationen';
$string['privacy:metadata:local_edulution_log:userid'] = 'Die ID des Benutzers, der die Aktion durchgefuehrt hat';
$string['privacy:metadata:local_edulution_log:action'] = 'Die durchgefuehrte Aktion';
$string['privacy:metadata:local_edulution_log:timecreated'] = 'Zeitpunkt der Aktion';

// ============================================================================
// EREIGNIS-STRINGS
// ============================================================================
$string['event_sync_started'] = 'Keycloak-Synchronisation gestartet';
$string['event_sync_completed'] = 'Keycloak-Synchronisation abgeschlossen';
$string['event_sync_failed'] = 'Keycloak-Synchronisation fehlgeschlagen';
$string['event_export_started'] = 'Datenexport gestartet';
$string['event_export_completed'] = 'Datenexport abgeschlossen';
$string['event_export_failed'] = 'Datenexport fehlgeschlagen';
$string['event_import_started'] = 'Datenimport gestartet';
$string['event_import_completed'] = 'Datenimport abgeschlossen';
$string['event_import_failed'] = 'Datenimport fehlgeschlagen';
$string['event_user_synced'] = 'Benutzer aus Keycloak synchronisiert';
$string['event_settings_updated'] = 'Plugin-Einstellungen aktualisiert';

// ============================================================================
// AJAX-ANTWORTEN
// ============================================================================
$string['ajax_error'] = 'Bei der Verarbeitung Ihrer Anfrage ist ein Fehler aufgetreten';
$string['ajax_success'] = 'Vorgang erfolgreich abgeschlossen';
$string['ajax_unauthorized'] = 'Sie sind nicht berechtigt, diese Aktion durchzufuehren';
$string['ajax_invalid_sesskey'] = 'Ungueltiger Sitzungsschluessel';

// ============================================================================
// FORTSCHRITTSMELDUNGEN
// ============================================================================
$string['progress_initializing'] = 'Initialisierung...';
$string['progress_exporting_users'] = 'Benutzer werden exportiert...';
$string['progress_exporting_courses'] = 'Kurse werden exportiert...';
$string['progress_exporting_categories'] = 'Kategorien werden exportiert...';
$string['progress_exporting_database'] = 'Datenbank wird exportiert...';
$string['progress_creating_package'] = 'Paket wird erstellt...';
$string['progress_finalizing'] = 'Wird abgeschlossen...';
$string['progress_importing'] = 'Daten werden importiert...';
$string['progress_syncing_users'] = 'Benutzer werden synchronisiert...';
$string['progress_complete'] = 'Abgeschlossen';
$string['progress_of'] = '{$a->current} von {$a->total}';

// ============================================================================
// AKTIVITAETSPROTOKOLL-TYPEN
// ============================================================================
$string['activity_export_started'] = 'Export gestartet';
$string['activity_export_completed'] = 'Export abgeschlossen';
$string['activity_export_failed'] = 'Export fehlgeschlagen';
$string['activity_import_started'] = 'Import gestartet';
$string['activity_import_completed'] = 'Import abgeschlossen';
$string['activity_import_failed'] = 'Import fehlgeschlagen';
$string['activity_sync_started'] = 'Keycloak-Synchronisation gestartet';
$string['activity_sync_completed'] = 'Keycloak-Synchronisation abgeschlossen';
$string['activity_sync_failed'] = 'Keycloak-Synchronisation fehlgeschlagen';
$string['activity_settings_updated'] = 'Einstellungen aktualisiert';
$string['activity_keycloak_configured'] = 'Keycloak-Konfiguration aktualisiert';

// ============================================================================
// HILFE-TOOLTIPS
// ============================================================================
$string['help_export'] = 'Erstellen Sie eine Sicherung Ihrer Moodle-Daten, die in eine andere Moodle-Instanz importiert werden kann.';
$string['help_import'] = 'Stellen Sie Daten aus einem frueheren edulution-Export wieder her.';
$string['help_sync'] = 'Halten Sie Benutzerkonten zwischen Keycloak und Moodle synchron.';
$string['help_keycloak'] = 'Konfigurieren Sie die Verbindung zu Ihrem Keycloak-Identitaetsanbieter.';

// ============================================================================
// KURS-SYNCHRONISATION
// ============================================================================
$string['course_category_classes'] = 'Klassen';
$string['course_category_projects'] = 'Projekte';
$string['course_category_grade'] = 'Klassenstufe {$a}';
$string['course_category_kursstufe'] = 'Kursstufe';
$string['course_sync_created'] = 'Kurs erstellt: {$a}';
$string['course_sync_updated'] = 'Kurs aktualisiert: {$a}';
$string['course_sync_skipped'] = 'Kurs uebersprungen (existiert bereits): {$a}';
$string['course_name_prefix_project'] = 'Projekt: ';
$string['course_name_prefix_class'] = 'Klasse ';
$string['course_name_formatted'] = 'Kursname formatiert: {$a->original} -> {$a->formatted}';

// Kurs- & Kategorieeinstellungen
$string['coursecategorysettings'] = 'Kurs- & Kategorieeinstellungen';
$string['coursecategorysettings_desc'] = 'Konfigurieren Sie, wo synchronisierte Kurse erstellt werden und wie sie benannt werden.';
$string['parentcategory'] = 'Uebergeordnete Kategorie';
$string['parentcategory_desc'] = 'Waehlen Sie eine bestehende Kategorie als Elternkategorie fuer synchronisierte Kurse, oder erstellen Sie eine neue.';
$string['createnewcategory'] = '-- Neue "edulution Sync" Kategorie erstellen --';
$string['categorynamemain'] = 'Name der Hauptkategorie';
$string['categorynamemain_desc'] = 'Name fuer die Hauptkategorie (nur bei Neuerstellung verwendet).';
$string['categorynameclasses'] = 'Name der Klassenkategorie';
$string['categorynameclasses_desc'] = 'Name fuer die Unterkategorie der Klassen.';
$string['categorynameprojects'] = 'Name der Projektkategorie';
$string['categorynameprojects_desc'] = 'Name fuer die Unterkategorie der Projekte.';
$string['coursenaming'] = 'Kursbenennung';
$string['coursenaming_desc'] = 'Konfigurieren Sie, wie Kurse benannt werden, wenn sie aus Keycloak-Gruppen erstellt werden.';
$string['courseprefixproject'] = 'Projektkurs-Praefix';
$string['courseprefixproject_desc'] = 'Praefix fuer Projektkurse (z.B. "Projekt: " macht aus "p_biologie" den Namen "Projekt: Biologie").';
$string['courseprefixclass'] = 'Klassenkurs-Praefix';
$string['courseprefixclass_desc'] = 'Praefix fuer Klassenkurse (z.B. "Klasse " macht aus "10a" den Namen "Klasse 10A").';
$string['courseformatnames'] = 'Kursnamen formatieren';
$string['courseformatnames_desc'] = 'Kursnamen automatisch formatieren (Praefixe wie p_ entfernen, Woerter gross schreiben, etc.).';

// ============================================================================
// EINSCHREIBUNGS-SYNCHRONISATION
// ============================================================================
$string['enrollment_sync_enrolled'] = 'Benutzer {$a->user} in Kurs {$a->course} eingeschrieben';
$string['enrollment_sync_unenrolled'] = 'Benutzer {$a->user} aus Kurs {$a->course} ausgetragen';
$string['enrollment_sync_skipped'] = 'Einschreibung fuer Benutzer {$a->user} uebersprungen';
$string['enrollment_role_student'] = 'Teilnehmer/in';
$string['enrollment_role_teacher'] = 'Trainer/in';
$string['enrollment_role_editingteacher'] = 'Trainer/in mit Bearbeitungsrecht';

// ============================================================================
// BENUTZER-SYNCHRONISATION
// ============================================================================
$string['user_sync_created'] = 'Benutzer erstellt: {$a}';
$string['user_sync_updated'] = 'Benutzer aktualisiert: {$a}';
$string['user_sync_skipped'] = 'Benutzer uebersprungen: {$a->user} - {$a->reason}';
$string['user_sync_teacher_detected'] = 'Lehrer erkannt (LDAP_ENTRY_DN): {$a}';
$string['user_sync_student_detected'] = 'Schueler erkannt: {$a}';
$string['user_profile_field_keycloak_id'] = 'Keycloak-ID';
$string['user_profile_field_keycloak_id_desc'] = 'Eindeutige Kennung aus Keycloak zur Benutzerverknuepfung';
