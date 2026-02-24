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
 * Cookie auth test and debug page.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// This page can be accessed without login for testing purposes.
// But we'll show more info if logged in as admin.
$isadmin = false;
try {
    if (isloggedin() && !isguestuser()) {
        $context = context_system::instance();
        $isadmin = has_capability('local/edulution:manage', $context);
    }
} catch (Exception $e) {
    // Not logged in.
}

require_once($CFG->dirroot . '/local/edulution/classes/auth/cookie_auth_backend.php');

$auth = new \local_edulution\auth\cookie_auth_backend();
$debug_info = $auth->get_debug_info();
$test_results = $auth->test_configuration();

// Output format.
$format = optional_param('format', 'html', PARAM_ALPHA);

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode([
        'debug' => $debug_info,
        'test' => $test_results,
    ], JSON_PRETTY_PRINT);
    exit;
}

// HTML output.
$PAGE->set_url(new moodle_url('/local/edulution/ajax/cookie_auth_test.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Cookie Auth Test');
$PAGE->set_heading('Cookie Auth Test');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
?>

<div class="container-fluid">
    <h2>Cookie Auth - Status & Test</h2>

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0">Status</h4>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th>Cookie Auth aktiviert</th>
                            <td>
                                <?php if ($debug_info['enabled']): ?>
                                    <span class="badge bg-success">Ja</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Nein</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Cookie-Name</th>
                            <td><code><?php echo s($debug_info['cookie_name']); ?></code></td>
                        </tr>
                        <tr>
                            <th>Cookie vorhanden</th>
                            <td>
                                <?php if ($debug_info['cookie_present']): ?>
                                    <span class="badge bg-success">Ja</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Nein</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Benutzer angemeldet</th>
                            <td>
                                <?php if ($debug_info['user_logged_in']): ?>
                                    <span class="badge bg-success"><?php echo s($debug_info['current_user']); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Nein</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Session via Cookie Auth</th>
                            <td>
                                <?php if ($debug_info['session_marked']): ?>
                                    <span class="badge bg-info">Ja</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Nein</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php if ($isadmin): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0">Konfiguration</h4>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th>Realm-URL</th>
                            <td><code><?php echo s($debug_info['realm_url'] ?: '(aus Keycloak-Einstellungen)'); ?></code></td>
                        </tr>
                        <tr>
                            <th>User-Claim</th>
                            <td><code><?php echo s($debug_info['user_claim']); ?></code></td>
                        </tr>
                        <tr>
                            <th>Algorithmus</th>
                            <td><code><?php echo s($debug_info['algorithm']); ?></code></td>
                        </tr>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0">Test-Ergebnisse</h4>
                </div>
                <div class="card-body">
                    <?php foreach ($test_results['messages'] as $msg): ?>
                        <?php
                        $alert_class = 'alert-info';
                        if ($msg['type'] === 'success') $alert_class = 'alert-success';
                        if ($msg['type'] === 'error') $alert_class = 'alert-danger';
                        if ($msg['type'] === 'warning') $alert_class = 'alert-warning';
                        ?>
                        <div class="alert <?php echo $alert_class; ?> py-2 mb-2">
                            <?php echo s($msg['text']); ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($test_results['success']): ?>
                        <div class="alert alert-success mt-3">
                            <strong>Konfiguration ist korrekt!</strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($debug_info['cookie_present'] && $isadmin && isset($debug_info['token_claims'])): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h4 class="card-title mb-0">Token-Informationen</h4>
                </div>
                <div class="card-body">
                    <?php if (is_array($debug_info['token_claims'])): ?>
                    <table class="table table-sm">
                        <tr>
                            <th>Issuer</th>
                            <td><code><?php echo s($debug_info['token_claims']['iss'] ?? '-'); ?></code></td>
                        </tr>
                        <tr>
                            <th>Ablauf</th>
                            <td><?php echo s($debug_info['token_claims']['exp_human'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>Benutzername (aus Claim)</th>
                            <td><code><?php echo s($debug_info['token_claims']['username_claim'] ?? '-'); ?></code></td>
                        </tr>
                    </table>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <?php echo s($debug_info['token_claims']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isadmin): ?>
    <div class="card">
        <div class="card-header">
            <h4 class="card-title mb-0">Cookies</h4>
        </div>
        <div class="card-body">
            <table class="table table-sm">
                <thead>
                    <tr><th>Name</th><th>Vorhanden</th></tr>
                </thead>
                <tbody>
                    <?php
                    $interesting_cookies = ['authToken', 'KEYCLOAK_SESSION', 'MoodleSession', 'MOODLEID_'];
                    foreach ($interesting_cookies as $name): ?>
                    <tr>
                        <td><code><?php echo s($name); ?></code></td>
                        <td>
                            <?php
                            $found = false;
                            foreach ($_COOKIE as $key => $value) {
                                if (strpos($key, $name) === 0) {
                                    $found = true;
                                    break;
                                }
                            }
                            ?>
                            <?php if ($found): ?>
                                <span class="badge bg-success">Ja</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Nein</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="text-muted mt-3">
                <strong>Hinweis:</strong> Für iframe-SSO muss das Cookie mit <code>SameSite=None; Secure</code> gesetzt sein.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <div class="mt-4">
        <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'local_edulution_cookie_auth']); ?>"
           class="btn btn-primary">Einstellungen</a>
        <a href="<?php echo new moodle_url('/local/edulution/dashboard.php'); ?>"
           class="btn btn-secondary">Dashboard</a>
        <a href="?format=json" class="btn btn-outline-secondary" target="_blank">JSON</a>
    </div>
</div>

<?php
echo $OUTPUT->footer();
