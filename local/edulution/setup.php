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
 * Einrichtungsassistent für Edulution.
 *
 * Führt Administratoren Schritt für Schritt durch die Einrichtung.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_edulution\sync\keycloak_client;
use local_edulution\sync\naming_schema_processor;

require_login();
$context = context_system::instance();
require_capability('local/edulution:manage', $context);

$PAGE->set_url(new moodle_url('/local/edulution/setup.php'));
$PAGE->set_context($context);
$PAGE->set_title('Edulution Einrichtung');
$PAGE->set_heading('Edulution Einrichtung');
$PAGE->set_pagelayout('admin');

// Aktueller Schritt
$step = optional_param('step', 1, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

// Verarbeite Formular-Aktionen
$message = '';
$messagetype = '';

if ($action === 'save' && confirm_sesskey()) {
    switch ($step) {
        case 1: // Keycloak-Verbindung speichern
            $url = required_param('keycloak_url', PARAM_URL);
            $realm = required_param('keycloak_realm', PARAM_TEXT);
            $clientid = required_param('keycloak_client_id', PARAM_TEXT);
            $secret = required_param('keycloak_client_secret', PARAM_TEXT);
            $verifyssl = optional_param('verify_ssl', 0, PARAM_INT);

            set_config('keycloak_url', $url, 'local_edulution');
            set_config('keycloak_realm', $realm, 'local_edulution');
            set_config('keycloak_client_id', $clientid, 'local_edulution');
            set_config('keycloak_client_secret', $secret, 'local_edulution');
            set_config('verify_ssl', $verifyssl, 'local_edulution');

            // Teste Verbindung
            try {
                $client = new keycloak_client($url, $realm, $clientid, $secret);
                $result = $client->test_connection();
                if ($result['success']) {
                    $message = 'Verbindung erfolgreich! ' . ($result['message'] ?? '');
                    $messagetype = 'success';
                    $step = 2; // Weiter zum nächsten Schritt
                } else {
                    $message = 'Verbindung fehlgeschlagen: ' . ($result['message'] ?? 'Unbekannter Fehler');
                    $messagetype = 'danger';
                }
            } catch (Exception $e) {
                $message = 'Verbindungsfehler: ' . $e->getMessage();
                $messagetype = 'danger';
            }
            break;

        case 2: // Schultyp/Preset speichern
            $preset = required_param('preset', PARAM_ALPHA);
            set_config('naming_preset', $preset, 'local_edulution');

            // Lade passende Schemas
            $schemas = get_preset_schemas($preset);
            set_config('naming_schemas', json_encode($schemas), 'local_edulution');

            $message = 'Einstellungen gespeichert!';
            $messagetype = 'success';
            $step = 3;
            break;

        case 3: // Kategorie speichern
            $categoryid = required_param('parent_category_id', PARAM_INT);

            if ($categoryid == -1) {
                // Neue Kategorie erstellen
                $newcategoryname = required_param('new_category_name', PARAM_TEXT);
                $newcategoryname = trim($newcategoryname);
                if (empty($newcategoryname)) {
                    $newcategoryname = 'Edulution';
                }

                // Speichere den Namen für späteren Gebrauch
                set_config('new_category_name', $newcategoryname, 'local_edulution');

                // Prüfe ob Kategorie schon existiert
                $existingcat = $DB->get_record('course_categories', ['name' => $newcategoryname, 'parent' => 0]);
                if ($existingcat) {
                    $categoryid = $existingcat->id;
                } else {
                    // Erstelle neue Kategorie
                    $newcat = \core_course_category::create([
                        'name' => $newcategoryname,
                        'description' => 'Automatisch von Edulution erstellt',
                        'parent' => 0,
                        'visible' => 1
                    ]);
                    $categoryid = $newcat->id;
                }
            }

            set_config('parent_category_id', $categoryid, 'local_edulution');

            $message = 'Kategorie gespeichert!';
            $messagetype = 'success';
            $step = 4;
            break;

        case 4: // Sync aktivieren
            set_config('keycloak_sync_enabled', 1, 'local_edulution');
            $message = 'Einrichtung abgeschlossen! Sie können jetzt synchronisieren.';
            $messagetype = 'success';
            redirect(new moodle_url('/local/edulution/sync.php'));
            break;
    }
}

// Hole aktuelle Konfiguration (Umgebungsvariablen haben Vorrang)
$config = [
    'keycloak_url' => local_edulution_get_config('keycloak_url', ''),
    'keycloak_realm' => local_edulution_get_config('keycloak_realm', 'master'),
    'keycloak_client_id' => local_edulution_get_config('keycloak_client_id', ''),
    'keycloak_client_secret' => local_edulution_get_config('keycloak_client_secret', ''),
    'verify_ssl' => local_edulution_get_config('verify_ssl', true),
    'naming_preset' => get_config('local_edulution', 'naming_preset') ?: 'linuxmuster',
    'parent_category_id' => get_config('local_edulution', 'parent_category_id') ?: 0,
];

// Prüfe welche Werte aus Umgebungsvariablen kommen
$envconfigs = local_edulution_get_env_configs();

/**
 * Gibt die Schemas für ein Preset zurück.
 */
function get_preset_schemas(string $preset): array {
    switch ($preset) {
        case 'linuxmuster':
            return naming_schema_processor::get_german_school_defaults();

        case 'simple':
            return [
                'schemas' => [
                    [
                        'id' => 'projekt',
                        'description' => 'Alle Projektgruppen',
                        'priority' => 100,
                        'pattern' => '^p_(?P<name>.+)$',
                        'course_name' => 'Projekt: {name|titlecase}',
                        'course_shortname' => 'P_{name|upper|truncate:25}',
                        'category_path' => 'Projekte',
                        'course_idnumber_prefix' => 'p_',
                        'role_map' => ['default' => 'student', 'teacher' => 'editingteacher'],
                        'enabled' => true
                    ]
                ],
                'subject_map' => []
            ];

        case 'custom':
        default:
            return ['schemas' => [], 'subject_map' => []];
    }
}

echo $OUTPUT->header();

// Navigation bar.
echo local_edulution_render_nav('setup');
?>

<style>
.setup-wizard {
    max-width: 800px;
    margin: 0 auto;
}
.setup-step {
    padding: 30px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
.setup-progress {
    display: flex;
    justify-content: space-between;
    margin-bottom: 30px;
    padding: 0 20px;
}
.progress-step {
    flex: 1;
    text-align: center;
    position: relative;
}
.progress-step::before {
    content: '';
    position: absolute;
    top: 15px;
    left: 50%;
    width: 100%;
    height: 3px;
    background: #dee2e6;
    z-index: 0;
}
.progress-step:last-child::before {
    display: none;
}
.progress-step .step-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #dee2e6;
    color: #6c757d;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    position: relative;
    z-index: 1;
}
.progress-step.active .step-number {
    background: #0d6efd;
    color: #fff;
}
.progress-step.completed .step-number {
    background: #198754;
    color: #fff;
}
.progress-step .step-label {
    display: block;
    margin-top: 8px;
    font-size: 12px;
    color: #6c757d;
}
.progress-step.active .step-label {
    color: #0d6efd;
    font-weight: bold;
}
.form-help {
    background: #f8f9fa;
    border-left: 4px solid #0d6efd;
    padding: 15px;
    margin: 15px 0;
    font-size: 14px;
}
.preset-card {
    border: 2px solid #dee2e6;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 15px;
    cursor: pointer;
    transition: all 0.2s;
}
.preset-card:hover {
    border-color: #0d6efd;
    background: #f8f9fa;
}
.preset-card.selected {
    border-color: #0d6efd;
    background: #e7f1ff;
}
.preset-card input[type="radio"] {
    display: none;
}
.preset-card h5 {
    margin-bottom: 10px;
}
.preset-card p {
    margin-bottom: 0;
    color: #6c757d;
    font-size: 14px;
}
</style>

<div class="setup-wizard">
    <h2 class="text-center mb-4">Edulution Einrichtungsassistent</h2>

    <!-- Fortschrittsanzeige -->
    <div class="setup-progress">
        <div class="progress-step <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>">
            <span class="step-number"><?php echo $step > 1 ? '<i class="fa fa-check"></i>' : '1'; ?></span>
            <span class="step-label"><i class="fa fa-key"></i> Keycloak verbinden</span>
        </div>
        <div class="progress-step <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?>">
            <span class="step-number"><?php echo $step > 2 ? '<i class="fa fa-check"></i>' : '2'; ?></span>
            <span class="step-label"><i class="fa fa-school"></i> Schultyp wählen</span>
        </div>
        <div class="progress-step <?php echo $step >= 3 ? ($step > 3 ? 'completed' : 'active') : ''; ?>">
            <span class="step-number"><?php echo $step > 3 ? '<i class="fa fa-check"></i>' : '3'; ?></span>
            <span class="step-label"><i class="fa fa-folder"></i> Kategorie festlegen</span>
        </div>
        <div class="progress-step <?php echo $step >= 4 ? 'active' : ''; ?>">
            <span class="step-number">4</span>
            <span class="step-label"><i class="fa fa-flag-checkered"></i> Fertig</span>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $messagetype; ?> alert-dismissible fade show">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Schritt 1: Keycloak-Verbindung -->
    <?php if ($step == 1): ?>
    <div class="setup-step">
        <h3><i class="fa fa-key text-primary"></i> Schritt 1: Keycloak-Server verbinden</h3>
        <p class="text-muted">Geben Sie die Zugangsdaten für Ihren Keycloak-Server ein.</p>

        <?php if (!empty($envconfigs)): ?>
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            <strong>Hinweis:</strong> Einige Werte werden über Umgebungsvariablen gesetzt und sind bereits vorausgefüllt.
            Diese können nicht hier geändert werden.
        </div>
        <?php endif; ?>

        <div class="form-help">
            <strong>Wo finde ich diese Daten?</strong><br>
            Melden Sie sich in der Keycloak-Administrationskonsole an und gehen Sie zu:
            <ol class="mb-0 mt-2">
                <li>Wählen Sie Ihren Realm (z.B. "master" oder Ihr Schulrealm)</li>
                <li>Klicken Sie auf "Clients" in der linken Seitenleiste</li>
                <li>Wählen Sie Ihren Moodle-Client oder erstellen Sie einen neuen</li>
                <li>Die Client-ID und das Secret finden Sie in den Client-Einstellungen</li>
            </ol>
        </div>

        <form method="post" action="">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="step" value="1">

            <div class="mb-3">
                <label for="keycloak_url" class="form-label">
                    Keycloak Server-URL <span class="text-danger">*</span>
                    <?php if (isset($envconfigs['keycloak_url'])): ?>
                    <span class="badge bg-secondary">ENV</span>
                    <?php endif; ?>
                </label>
                <?php if (isset($envconfigs['keycloak_url'])): ?>
                <input type="hidden" name="keycloak_url" value="<?php echo s($config['keycloak_url']); ?>">
                <input type="url" class="form-control bg-light" id="keycloak_url"
                       value="<?php echo s($config['keycloak_url']); ?>" disabled>
                <?php else: ?>
                <input type="url" class="form-control" id="keycloak_url" name="keycloak_url"
                       value="<?php echo s($config['keycloak_url']); ?>"
                       placeholder="https://keycloak.meine-schule.de" required>
                <?php endif; ?>
                <small class="form-text text-muted">
                    <?php if (isset($envconfigs['keycloak_url'])): ?>
                    <i class="fa fa-lock"></i> Gesetzt über: <code><?php echo s($envconfigs['keycloak_url']); ?></code>
                    <?php else: ?>
                    Die vollständige URL Ihres Keycloak-Servers (ohne /auth am Ende)
                    <?php endif; ?>
                </small>
            </div>

            <div class="mb-3">
                <label for="keycloak_realm" class="form-label">
                    Realm <span class="text-danger">*</span>
                    <?php if (isset($envconfigs['keycloak_realm'])): ?>
                    <span class="badge bg-secondary">ENV</span>
                    <?php endif; ?>
                </label>
                <?php if (isset($envconfigs['keycloak_realm'])): ?>
                <input type="hidden" name="keycloak_realm" value="<?php echo s($config['keycloak_realm']); ?>">
                <input type="text" class="form-control bg-light" id="keycloak_realm"
                       value="<?php echo s($config['keycloak_realm']); ?>" disabled>
                <?php else: ?>
                <input type="text" class="form-control" id="keycloak_realm" name="keycloak_realm"
                       value="<?php echo s($config['keycloak_realm']); ?>"
                       placeholder="master" required>
                <?php endif; ?>
                <small class="form-text text-muted">
                    <?php if (isset($envconfigs['keycloak_realm'])): ?>
                    <i class="fa fa-lock"></i> Gesetzt über: <code><?php echo s($envconfigs['keycloak_realm']); ?></code>
                    <?php else: ?>
                    Der Name des Keycloak-Realms, in dem Ihre Benutzer gespeichert sind
                    <?php endif; ?>
                </small>
            </div>

            <div class="mb-3">
                <label for="keycloak_client_id" class="form-label">
                    Client-ID <span class="text-danger">*</span>
                    <?php if (isset($envconfigs['keycloak_client_id'])): ?>
                    <span class="badge bg-secondary">ENV</span>
                    <?php endif; ?>
                </label>
                <?php if (isset($envconfigs['keycloak_client_id'])): ?>
                <input type="hidden" name="keycloak_client_id" value="<?php echo s($config['keycloak_client_id']); ?>">
                <input type="text" class="form-control bg-light" id="keycloak_client_id"
                       value="<?php echo s($config['keycloak_client_id']); ?>" disabled>
                <?php else: ?>
                <input type="text" class="form-control" id="keycloak_client_id" name="keycloak_client_id"
                       value="<?php echo s($config['keycloak_client_id']); ?>"
                       placeholder="moodle-client" required>
                <?php endif; ?>
                <small class="form-text text-muted">
                    <?php if (isset($envconfigs['keycloak_client_id'])): ?>
                    <i class="fa fa-lock"></i> Gesetzt über: <code><?php echo s($envconfigs['keycloak_client_id']); ?></code>
                    <?php else: ?>
                    Die ID des Keycloak-Clients für Moodle
                    <?php endif; ?>
                </small>
            </div>

            <div class="mb-3">
                <label for="keycloak_client_secret" class="form-label">
                    Client-Secret <span class="text-danger">*</span>
                    <?php if (isset($envconfigs['keycloak_client_secret'])): ?>
                    <span class="badge bg-secondary">ENV</span>
                    <?php endif; ?>
                </label>
                <?php if (isset($envconfigs['keycloak_client_secret'])): ?>
                <input type="hidden" name="keycloak_client_secret" value="<?php echo s($config['keycloak_client_secret']); ?>">
                <input type="password" class="form-control bg-light" id="keycloak_client_secret"
                       value="********" disabled>
                <?php else: ?>
                <input type="password" class="form-control" id="keycloak_client_secret" name="keycloak_client_secret"
                       value="<?php echo s($config['keycloak_client_secret']); ?>" required>
                <?php endif; ?>
                <small class="form-text text-muted">
                    <?php if (isset($envconfigs['keycloak_client_secret'])): ?>
                    <i class="fa fa-lock"></i> Gesetzt über: <code><?php echo s($envconfigs['keycloak_client_secret']); ?></code>
                    <?php else: ?>
                    Das geheime Passwort des Clients (unter "Credentials" im Client)
                    <?php endif; ?>
                </small>
            </div>

            <div class="mb-4">
                <div class="form-check">
                    <?php if (isset($envconfigs['verify_ssl'])): ?>
                    <input type="hidden" name="verify_ssl" value="<?php echo $config['verify_ssl'] ? '1' : '0'; ?>">
                    <input type="checkbox" class="form-check-input" id="verify_ssl"
                           <?php echo $config['verify_ssl'] ? 'checked' : ''; ?> disabled>
                    <?php else: ?>
                    <input type="checkbox" class="form-check-input" id="verify_ssl" name="verify_ssl" value="1"
                           <?php echo $config['verify_ssl'] ? 'checked' : ''; ?>>
                    <?php endif; ?>
                    <label class="form-check-label" for="verify_ssl">
                        SSL-Zertifikate überprüfen
                        <?php if (isset($envconfigs['verify_ssl'])): ?>
                        <span class="badge bg-secondary">ENV</span>
                        <?php endif; ?>
                    </label>
                </div>
                <small class="form-text text-muted">
                    <?php if (isset($envconfigs['verify_ssl'])): ?>
                    <i class="fa fa-lock"></i> Gesetzt über: <code><?php echo s($envconfigs['verify_ssl']); ?></code>
                    <?php else: ?>
                    Deaktivieren Sie dies nur für Testzwecke mit selbstsignierten Zertifikaten
                    <?php endif; ?>
                </small>
            </div>

            <div class="d-flex justify-content-between">
                <span></span>
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fa fa-plug"></i> Verbindung testen & Weiter <i class="fa fa-arrow-right"></i>
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Schritt 2: Schultyp/Preset wählen -->
    <?php if ($step == 2): ?>
    <div class="setup-step">
        <h3><i class="fa fa-graduation-cap text-primary"></i> Schritt 2: Schultyp wählen</h3>
        <p class="text-muted">Wählen Sie den Typ Ihrer Schulumgebung. Dies bestimmt, wie Gruppen in Kurse umgewandelt werden.</p>

        <form method="post" action="">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="step" value="2">

            <label class="preset-card <?php echo $config['naming_preset'] === 'linuxmuster' ? 'selected' : ''; ?>">
                <input type="radio" name="preset" value="linuxmuster"
                       <?php echo $config['naming_preset'] === 'linuxmuster' ? 'checked' : ''; ?>>
                <h5><i class="fa fa-star text-warning"></i> Standard (Empfohlen)</h5>
                <p>
                    Erkennt automatisch verschiedene Gruppentypen:
                </p>
                <ul class="mt-2 mb-0" style="font-size: 14px; color: #6c757d;">
                    <li><i class="fa fa-users text-primary"></i> <strong>Fachschaften:</strong> p_alle_bio → "Fachschaft Biologie"</li>
                    <li><i class="fa fa-chalkboard-teacher text-primary"></i> <strong>Lehrer-Kurse:</strong> p_mueller_mathe_10a → "Mathematik Klasse 10A (MUELLER)"</li>
                    <li><i class="fa fa-graduation-cap text-primary"></i> <strong>Klassen-Kurse:</strong> p_10a_deutsch → "Deutsch 10A"</li>
                    <li><i class="fa fa-puzzle-piece text-primary"></i> <strong>AGs:</strong> p_robotik_ag → "AG: Robotik"</li>
                </ul>
            </label>

            <label class="preset-card <?php echo $config['naming_preset'] === 'simple' ? 'selected' : ''; ?>">
                <input type="radio" name="preset" value="simple"
                       <?php echo $config['naming_preset'] === 'simple' ? 'checked' : ''; ?>>
                <h5><i class="fa fa-cube text-info"></i> Einfach</h5>
                <p>
                    Für einfache Umgebungen. Alle Gruppen mit "p_" Präfix werden zu Projektkursen.
                </p>
            </label>

            <label class="preset-card <?php echo $config['naming_preset'] === 'custom' ? 'selected' : ''; ?>">
                <input type="radio" name="preset" value="custom"
                       <?php echo $config['naming_preset'] === 'custom' ? 'checked' : ''; ?>>
                <h5><i class="fa fa-cog text-secondary"></i> Benutzerdefiniert</h5>
                <p>
                    Für fortgeschrittene Benutzer. Sie können später eigene Regeln in den Einstellungen definieren.
                </p>
            </label>

            <div class="d-flex justify-content-between mt-4">
                <a href="?step=1" class="btn btn-outline-secondary btn-lg"><i class="fa fa-arrow-left"></i> Zurück</a>
                <button type="submit" class="btn btn-primary btn-lg">
                    Weiter <i class="fa fa-arrow-right"></i>
                </button>
            </div>
        </form>
    </div>

    <script>
    document.querySelectorAll('.preset-card').forEach(card => {
        card.addEventListener('click', function() {
            document.querySelectorAll('.preset-card').forEach(c => c.classList.remove('selected'));
            this.classList.add('selected');
            this.querySelector('input[type="radio"]').checked = true;
        });
    });
    </script>
    <?php endif; ?>

    <!-- Schritt 3: Kategorie wählen -->
    <?php if ($step == 3): ?>
    <div class="setup-step">
        <h3><i class="fa fa-folder-open text-primary"></i> Schritt 3: Kurskategorie festlegen</h3>
        <p class="text-muted">Wählen Sie, wo die synchronisierten Kurse erstellt werden sollen.</p>

        <div class="form-help">
            <i class="fa fa-info-circle text-primary"></i> <strong>Was bedeutet das?</strong><br>
            Alle Kurse, die aus Keycloak-Gruppen erstellt werden, werden in dieser Kategorie (oder Unterkategorien davon) angelegt.
            Unterkategorien wie "Fachschaften", "Klassen" usw. werden automatisch erstellt.
        </div>

        <form method="post" action="">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="step" value="3">

            <?php
            $categories = \core_course_category::make_categories_list();
            $categoryoptions = [-1 => '--- Neue Kategorie erstellen ---'] + $categories;
            $newcategoryname = get_config('local_edulution', 'new_category_name') ?: 'Edulution';
            ?>

            <div class="mb-4">
                <label for="parent_category_id" class="form-label">
                    <i class="fa fa-sitemap"></i> Übergeordnete Kategorie
                </label>
                <select class="form-control form-select" id="parent_category_id" name="parent_category_id" onchange="toggleNewCategoryInput(this)">
                    <?php foreach ($categoryoptions as $id => $name): ?>
                    <option value="<?php echo $id; ?>" <?php echo $config['parent_category_id'] == $id ? 'selected' : ''; ?>>
                        <?php echo s($name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="form-text text-muted">
                    <i class="fa fa-lightbulb-o"></i> Wählen Sie eine bestehende Kategorie oder erstellen Sie eine neue
                </small>
            </div>

            <div class="mb-4" id="new-category-input" style="<?php echo $config['parent_category_id'] != -1 ? 'display: none;' : ''; ?>">
                <label for="new_category_name" class="form-label">
                    <i class="fa fa-pencil"></i> Name der neuen Kategorie
                </label>
                <input type="text" class="form-control" id="new_category_name" name="new_category_name"
                       value="<?php echo s($newcategoryname); ?>" placeholder="z.B. Edulution, Schule, Kurse...">
                <small class="form-text text-muted">
                    <i class="fa fa-lightbulb-o"></i> Geben Sie einen Namen für die neue Hauptkategorie ein
                </small>
            </div>

            <script>
            function toggleNewCategoryInput(select) {
                var newCatDiv = document.getElementById('new-category-input');
                if (select.value == '-1') {
                    newCatDiv.style.display = 'block';
                } else {
                    newCatDiv.style.display = 'none';
                }
            }
            </script>

            <div class="d-flex justify-content-between">
                <a href="?step=2" class="btn btn-outline-secondary btn-lg"><i class="fa fa-arrow-left"></i> Zurück</a>
                <button type="submit" class="btn btn-primary btn-lg">
                    Weiter <i class="fa fa-arrow-right"></i>
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Schritt 4: Fertig -->
    <?php if ($step == 4): ?>
    <div class="setup-step text-center">
        <div class="mb-4">
            <span style="font-size: 64px; color: #198754;"><i class="fa fa-check-circle"></i></span>
        </div>
        <h3 class="text-success mb-4">Einrichtung abgeschlossen!</h3>

        <p class="lead">
            Edulution ist jetzt konfiguriert und bereit für die erste Synchronisierung.
        </p>

        <div class="alert alert-info text-start">
            <i class="fa fa-info-circle"></i> <strong>Was passiert bei der Synchronisierung?</strong>
            <ul class="mb-0 mt-2">
                <li><i class="fa fa-user-plus text-primary"></i> Benutzer aus Keycloak werden in Moodle angelegt</li>
                <li><i class="fa fa-graduation-cap text-primary"></i> Gruppen werden zu Kursen (gemäß Ihrer Einstellungen)</li>
                <li><i class="fa fa-sign-in text-primary"></i> Benutzer werden automatisch in die passenden Kurse eingeschrieben</li>
                <li><i class="fa fa-user-secret text-primary"></i> Lehrer erhalten die Rolle "Trainer/in mit Bearbeitungsrechten"</li>
            </ul>
        </div>

        <form method="post" action="">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="step" value="4">

            <div class="d-flex justify-content-center gap-3 mt-4">
                <a href="?step=3" class="btn btn-outline-secondary btn-lg"><i class="fa fa-arrow-left"></i> Zurück</a>
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fa fa-play"></i> Synchronisierung aktivieren & starten
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Hilfe-Link -->
    <div class="text-center mt-4">
        <a href="<?php echo new moodle_url('/local/edulution/pages/schema_docs.php'); ?>" class="text-muted">
            <i class="fa fa-book"></i> Erweiterte Dokumentation für Fortgeschrittene
        </a>
    </div>
</div>

<?php
echo $OUTPUT->footer();
