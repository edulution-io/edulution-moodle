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
 * Admin settings for the Edulution local plugin.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Include environment-aware admin settings.
require_once(__DIR__ . '/classes/admin/setting_envaware.php');

use local_edulution\admin\setting_configtext_envaware;
use local_edulution\admin\setting_configpassword_envaware;
use local_edulution\admin\setting_configcheckbox_envaware;

if ($hassiteconfig) {
    // Hauptkategorie unter "Plugins > Lokale Plugins".
    $ADMIN->add('localplugins', new admin_category('local_edulution', 'Edulution'));

    // =====================================================
    // Hauptnavigation - nur Dashboard als Einstiegspunkt
    // Der Setup-Wizard wird vom Dashboard aus verlinkt
    // =====================================================

    // Dashboard als Hauptseite.
    $ADMIN->add('local_edulution', new admin_externalpage(
        'local_edulution_dashboard',
        'Übersicht',
        new moodle_url('/local/edulution/dashboard.php'),
        'local/edulution:manage'
    ));

    // =====================================================
    // Einstellungen - Keycloak-Verbindung
    // =====================================================
    $settings = new admin_settingpage('local_edulution_settings', 'Keycloak');

    $settings->add(new admin_setting_heading('local_edulution/keycloak_heading',
        'Keycloak-Server',
        'Geben Sie hier die Zugangsdaten für Ihren Keycloak-Server ein. ' .
        'Diese finden Sie in der Keycloak-Administrationskonsole unter "Clients".'));

    $settings->add(new setting_configtext_envaware('local_edulution/keycloak_url',
        'Server-URL',
        'Die vollständige URL Ihres Keycloak-Servers, z.B. https://keycloak.meine-schule.de',
        '', PARAM_URL));

    $settings->add(new setting_configtext_envaware('local_edulution/keycloak_realm',
        'Realm',
        'Der Name des Keycloak-Realms (z.B. "master" oder Ihr Schulname).',
        'master', PARAM_TEXT));

    $settings->add(new setting_configtext_envaware('local_edulution/keycloak_client_id',
        'Client-ID',
        'Die ID des Keycloak-Clients für Moodle (z.B. "moodle-client")',
        '', PARAM_TEXT));

    $settings->add(new setting_configpassword_envaware('local_edulution/keycloak_client_secret',
        'Client-Secret',
        'Das geheime Passwort des Clients (unter "Credentials" in den Client-Einstellungen)',
        ''));

    $settings->add(new setting_configcheckbox_envaware('local_edulution/verify_ssl',
        'SSL-Zertifikate prüfen',
        'SSL-Zertifikate bei der Verbindung überprüfen. Nur für Testzwecke mit selbstsignierten Zertifikaten deaktivieren.',
        1));

    $settings->add(new setting_configcheckbox_envaware('local_edulution/keycloak_sync_enabled',
        'Automatische Synchronisierung aktivieren',
        'Wenn aktiviert, werden Benutzer und Gruppen regelmäßig automatisch synchronisiert.',
        0));

    // Sync-Intervall.
    $intervals = [
        '15' => 'Alle 15 Minuten',
        '30' => 'Alle 30 Minuten',
        '60' => 'Jede Stunde',
        '360' => 'Alle 6 Stunden',
        '720' => 'Alle 12 Stunden',
        '1440' => 'Einmal täglich',
    ];
    $settings->add(new admin_setting_configselect('local_edulution/sync_interval',
        'Synchronisierungs-Intervall',
        'Wie oft soll die automatische Synchronisierung laufen?',
        '60',
        $intervals));

    $ADMIN->add('local_edulution', $settings);

    // =====================================================
    // Synchronisierungs-Optionen
    // =====================================================
    $syncsettings = new admin_settingpage('local_edulution_sync_options', 'Synchronisierung');

    $syncsettings->add(new admin_setting_heading('local_edulution/sync_heading',
        'Synchronisierungs-Verhalten',
        'Legen Sie fest, was bei der Synchronisierung passieren soll.'));

    $syncsettings->add(new admin_setting_configcheckbox('local_edulution/sync_create_users',
        'Neue Benutzer anlegen',
        'Benutzer aus Keycloak, die noch nicht in Moodle existieren, automatisch anlegen.',
        1));

    $syncsettings->add(new admin_setting_configcheckbox('local_edulution/sync_update_users',
        'Bestehende Benutzer aktualisieren',
        'Benutzerdaten (Name, E-Mail) mit Keycloak synchron halten.',
        1));

    $syncsettings->add(new admin_setting_heading('local_edulution/sync_danger_heading',
        'Vorsicht bei diesen Optionen',
        '<div style="background:#fff3cd;border:1px solid #ffc107;padding:15px;border-radius:5px;">' .
        '<strong>Diese Optionen können Daten löschen!</strong> Aktivieren Sie diese nur, wenn Sie sicher sind, ' .
        'dass alle Ihre Benutzer ausschließlich über Keycloak verwaltet werden.</div>'));

    $syncsettings->add(new admin_setting_configcheckbox('local_edulution/sync_suspend_users',
        'Fehlende Benutzer sperren',
        'Benutzer in Moodle sperren, wenn sie nicht mehr in Keycloak existieren. ' .
        '<strong>Achtung:</strong> Manuell angelegte Moodle-Benutzer könnten versehentlich gesperrt werden!',
        0));

    $syncsettings->add(new admin_setting_configcheckbox('local_edulution/sync_unenroll_users',
        'Entfernte Benutzer abmelden',
        'Benutzer aus Kursen abmelden, wenn sie aus der entsprechenden Keycloak-Gruppe entfernt wurden.',
        0));

    $syncsettings->add(new admin_setting_heading('local_edulution/role_heading',
        'Lehrer-Erkennung',
        'So erkennt das Plugin, ob ein Keycloak-Benutzer ein Lehrer ist. ' .
        'Lehrer werden anhand des konfigurierten Attributs erkannt.'));

    $syncsettings->add(new admin_setting_configtext('local_edulution/teacher_role_attribute',
        'Rollen-Attribut',
        'Das Keycloak-Benutzerattribut, das die Rolle enthält (z.B. sophomorixRole, role, userType)',
        'sophomorixRole', PARAM_TEXT));

    $syncsettings->add(new admin_setting_configtext('local_edulution/teacher_role_value',
        'Wert für Lehrer',
        'Der Wert des Attributs, der einen Lehrer kennzeichnet (z.B. teacher)',
        'teacher', PARAM_TEXT));

    $ADMIN->add('local_edulution', $syncsettings);

    // =====================================================
    // Kategorie-Einstellungen
    // =====================================================
    $categorysettings = new admin_settingpage('local_edulution_categories', 'Kurskategorien');

    $categorysettings->add(new admin_setting_heading('local_edulution/category_heading',
        'Wo sollen Kurse erstellt werden?',
        'Wählen Sie die Kategorie, in der synchronisierte Kurse angelegt werden. ' .
        'Unterkategorien (z.B. "Fachschaften", "Klassen") werden automatisch erstellt.'));

    // Kategorien für Dropdown holen.
    $categories = \core_course_category::make_categories_list();
    $categoryoptions = [0 => '--- Neue Kategorie "Edulution" erstellen ---'] + $categories;

    $categorysettings->add(new admin_setting_configselect('local_edulution/parent_category_id',
        'Übergeordnete Kategorie',
        'Wählen Sie eine bestehende Kategorie oder lassen Sie eine neue erstellen.',
        0,
        $categoryoptions));

    $categorysettings->add(new admin_setting_configtext('local_edulution/category_name_main',
        'Name der Hauptkategorie',
        'Nur relevant, wenn eine neue Kategorie erstellt wird. Leer lassen für "Edulution".',
        '', PARAM_TEXT));

    $ADMIN->add('local_edulution', $categorysettings);

    // =====================================================
    // Erweiterte Einstellungen (für Experten)
    // =====================================================
    $advancedsettings = new admin_settingpage('local_edulution_advanced', 'Erweitert');

    $advancedsettings->add(new admin_setting_heading('local_edulution/advanced_heading',
        'Erweiterte Einstellungen',
        '<div style="background:#f8d7da;border:1px solid #dc3545;padding:15px;border-radius:5px;">' .
        '<strong>Nur für fortgeschrittene Benutzer!</strong> Diese Einstellungen erfordern technisches Wissen. ' .
        'Die Standardwerte funktionieren für die meisten Schulen.</div>'));

    // Preset-Auswahl statt JSON
    $presets = [
        'linuxmuster' => 'Standard (empfohlen)',
        'simple' => 'Einfach (alle p_* Gruppen werden Projekte)',
        'custom' => 'Benutzerdefiniert (JSON erforderlich)',
    ];
    $advancedsettings->add(new admin_setting_configselect('local_edulution/naming_preset',
        'Namensschema-Vorlage',
        'Wählen Sie eine vordefinierte Vorlage für die Kurs-Benennung. ' .
        'Der Standard erkennt automatisch Fachschaften, Klassen-Kurse, AGs und Projekte.',
        'linuxmuster',
        $presets));

    $advancedsettings->add(new admin_setting_configtextarea('local_edulution/naming_schemas',
        'Benutzerdefinierte Schemas (JSON)',
        'Nur ausfüllen, wenn Sie "Benutzerdefiniert" gewählt haben. ' .
        'Leer lassen, um die Standardwerte zu verwenden.<br>' .
        '<a href="' . new moodle_url('/local/edulution/pages/schema_docs.php') . '">Dokumentation anzeigen</a>',
        '',
        PARAM_RAW));

    $ADMIN->add('local_edulution', $advancedsettings);

    // =====================================================
    // Cookie Auth (SSO für iFrames)
    // =====================================================
    $cookieauthsettings = new admin_settingpage('local_edulution_cookie_auth', 'Cookie Auth (SSO)');

    $cookieauthsettings->add(new admin_setting_heading('local_edulution/cookie_auth_heading',
        'Cookie-basierte Authentifizierung',
        'Ermöglicht automatische Anmeldung über JWT-Token in Cookies. ' .
        'Ideal für die Einbettung von Moodle in iFrames mit Single-Sign-On.'));

    $cookieauthsettings->add(new admin_setting_configcheckbox('local_edulution/cookie_auth_enabled',
        'Cookie Auth aktivieren',
        'Wenn aktiviert, werden Benutzer automatisch angemeldet, wenn ein gültiger JWT-Token im Cookie vorhanden ist.',
        0));

    $cookieauthsettings->add(new admin_setting_configtext('local_edulution/cookie_auth_cookie_name',
        'Cookie-Name',
        'Name des Cookies, der den JWT-Token enthält.',
        'authToken', PARAM_TEXT));

    $cookieauthsettings->add(new admin_setting_configtext('local_edulution/cookie_auth_user_claim',
        'Benutzer-Claim',
        'JWT-Claim für den Benutzernamen (z.B. preferred_username, sub, email). ' .
        'Unterstützt Punkt-Notation für verschachtelte Claims (z.B. user.name).',
        'preferred_username', PARAM_TEXT));

    $cookieauthsettings->add(new admin_setting_heading('local_edulution/cookie_auth_key_heading',
        'Token-Verifizierung',
        'Konfigurieren Sie, wie JWT-Token verifiziert werden. ' .
        'Wenn keine Realm-URL angegeben ist, werden die Keycloak-Einstellungen oben verwendet.'));

    $cookieauthsettings->add(new admin_setting_configtext('local_edulution/cookie_auth_realm_url',
        'Realm-URL (optional)',
        'Vollständige URL des Keycloak-Realms (z.B. https://keycloak.example.com/realms/myrealm). ' .
        'Der Public Key wird automatisch abgerufen. Leer lassen, um die Keycloak-Einstellungen zu verwenden.',
        '', PARAM_URL));

    $cookieauthsettings->add(new admin_setting_configtextarea('local_edulution/cookie_auth_public_key',
        'Public Key (optional)',
        'PEM-formatierter Public Key für die Token-Verifizierung. ' .
        'Nur ausfüllen, wenn kein automatischer Abruf gewünscht ist.',
        '', PARAM_RAW));

    $algorithms = [
        'RS256' => 'RS256 (empfohlen)',
        'RS384' => 'RS384',
        'RS512' => 'RS512',
    ];
    $cookieauthsettings->add(new admin_setting_configselect('local_edulution/cookie_auth_algorithm',
        'Algorithmus',
        'JWT-Signaturalgorithmus.',
        'RS256',
        $algorithms));

    $cookieauthsettings->add(new admin_setting_configtext('local_edulution/cookie_auth_issuer',
        'Issuer (optional)',
        'Erwarteter Issuer (iss) im Token. Leer lassen, um die Realm-URL zu verwenden.',
        '', PARAM_TEXT));

    $cookieauthsettings->add(new admin_setting_configcheckbox('local_edulution/cookie_auth_fallback_email',
        'E-Mail-Fallback',
        'Wenn der Benutzer nicht über den Benutzernamen gefunden wird, nach E-Mail-Adresse suchen.',
        0));

    $cookieauthsettings->add(new admin_setting_configcheckbox('local_edulution/cookie_auth_debug',
        'Debug-Modus',
        'Ausführliche Debugging-Meldungen aktivieren (nur für Entwicklung).',
        0));

    // Test-Link hinzufügen.
    $cookieauthsettings->add(new admin_setting_heading('local_edulution/cookie_auth_test_heading',
        'Testen',
        '<a href="' . new moodle_url('/local/edulution/ajax/cookie_auth_test.php') .
        '" class="btn btn-secondary" target="_blank">Konfiguration testen</a>'));

    $ADMIN->add('local_edulution', $cookieauthsettings);
}
