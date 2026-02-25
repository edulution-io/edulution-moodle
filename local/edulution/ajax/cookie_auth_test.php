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
 * Cookie auth diagnostic endpoint.
 *
 * Runs a complete end-to-end simulation of the cookie auth flow
 * and reports exactly what would happen and where it fails.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/edulution/classes/auth/cookie_auth_backend.php');

$auth = new \local_edulution\auth\cookie_auth_backend();
$diag = $auth->run_full_diagnostic();

// JSON output.
$format = optional_param('format', 'html', PARAM_ALPHA);
if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// HTML output.
$PAGE->set_url(new moodle_url('/local/edulution/ajax/cookie_auth_test.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Cookie Auth - Diagnose');
$PAGE->set_heading('Cookie Auth - Diagnose');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

require_once($CFG->dirroot . '/local/edulution/lib.php');
echo local_edulution_render_nav('cookie_auth');

// Status icons.
$icons = [
    'ok' => '<span style="color:#198754;font-weight:bold">&#10004;</span>',
    'fail' => '<span style="color:#dc3545;font-weight:bold">&#10008;</span>',
    'warn' => '<span style="color:#ffc107;font-weight:bold">&#9888;</span>',
];

// Main result banner.
$would_work = $diag['would_auth_work'];
$enabled = $diag['config']['enabled'];
?>

<div class="container-fluid" style="max-width: 900px;">

    <!-- Ergebnis -->
    <?php if ($would_work && $enabled): ?>
        <div class="alert alert-success py-3 mb-4" style="font-size: 1.2em;">
            <strong>&#10004; Auto-Login wuerde funktionieren</strong>
        </div>
    <?php elseif ($would_work && !$enabled): ?>
        <div class="alert alert-warning py-3 mb-4" style="font-size: 1.2em;">
            <strong>&#9888; Alle Checks OK - aber Cookie Auth ist deaktiviert</strong><br>
            <small>Aktivieren unter: Plugins &gt; Lokale Plugins &gt; edulution &gt; Cookie Auth (SSO)</small>
        </div>
    <?php else: ?>
        <div class="alert alert-danger py-3 mb-4" style="font-size: 1.2em;">
            <strong>&#10008; Auto-Login wuerde NICHT funktionieren</strong><br>
            <span><?php echo s($diag['failure_reason']); ?></span>
        </div>
    <?php endif; ?>

    <!-- Steps -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Auth-Flow Simulation</h5></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th style="width:30px"></th>
                        <th>Schritt</th>
                        <th>Ergebnis</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($diag['steps'] as $step): ?>
                    <tr<?php echo $step['status'] === 'fail' ? ' class="table-danger"' : ''; ?>>
                        <td class="text-center"><?php echo $icons[$step['status']]; ?></td>
                        <td><?php echo s($step['name']); ?></td>
                        <td><code><?php echo s($step['detail']); ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row">
        <!-- Config -->
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">Konfiguration</h5></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th>Cookie Auth</th>
                            <td>
                                <?php if ($diag['config']['enabled']): ?>
                                    <span class="badge bg-success">Aktiviert</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Deaktiviert</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Cookie-Name</th>
                            <td><code><?php echo s($diag['config']['cookie_name']); ?></code></td>
                        </tr>
                        <tr>
                            <th>User-Claim</th>
                            <td><code><?php echo s($diag['config']['user_claim']); ?></code></td>
                        </tr>
                        <tr>
                            <th>Algorithmus</th>
                            <td><code><?php echo s($diag['config']['algorithm']); ?></code></td>
                        </tr>
                        <tr>
                            <th>Realm-URL</th>
                            <td><code style="word-break:break-all"><?php echo s($diag['config']['realm_url']); ?></code></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Moodle Session -->
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">Moodle-Session</h5></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <th>Eingeloggt</th>
                            <td>
                                <?php if ($diag['moodle_session']['user_logged_in']): ?>
                                    <span class="badge bg-success"><?php echo s($diag['moodle_session']['current_user']); ?></span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Nein</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Via Cookie Auth</th>
                            <td>
                                <?php if ($diag['moodle_session']['session_via_cookie_auth']): ?>
                                    <span class="badge bg-info">Ja</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Nein</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php if (isset($diag['token'])): ?>
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">Token</h5></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <?php foreach ($diag['token'] as $key => $val): ?>
                        <tr>
                            <th><?php echo s($key); ?></th>
                            <td><code><?php echo s($val ?? '-'); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($diag['moodle_user'])): ?>
            <div class="card mb-4">
                <div class="card-header"><h5 class="mb-0">Moodle-User (gefunden)</h5></div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <?php foreach ($diag['moodle_user'] as $key => $val): ?>
                        <tr>
                            <th><?php echo s($key); ?></th>
                            <td><code><?php echo s(is_bool($val) ? ($val ? 'true' : 'false') : $val); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cookies -->
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Cookies im Browser</h5></div>
        <div class="card-body">
            <table class="table table-sm mb-0">
                <thead><tr><th>Cookie</th><th>Vorhanden</th></tr></thead>
                <tbody>
                    <?php
                    $check_cookies = [
                        $diag['config']['cookie_name'],
                        'MoodleSession',
                        'MOODLEID_',
                    ];
                    foreach ($check_cookies as $name):
                        $found = false;
                        foreach ($_COOKIE as $key => $value) {
                            if (strpos($key, $name) === 0) {
                                $found = true;
                                break;
                            }
                        }
                    ?>
                    <tr>
                        <td><code><?php echo s($name); ?></code></td>
                        <td>
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
        </div>
    </div>

    <div class="mb-4">
        <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'local_edulution_cookie_auth']); ?>"
           class="btn btn-primary">Einstellungen</a>
        <a href="<?php echo new moodle_url('/local/edulution/dashboard.php'); ?>"
           class="btn btn-secondary">Dashboard</a>
        <a href="?format=json" class="btn btn-outline-secondary" target="_blank">JSON</a>
        <a href="?" class="btn btn-outline-secondary">Neu laden</a>
    </div>
</div>

<?php
echo $OUTPUT->footer();
