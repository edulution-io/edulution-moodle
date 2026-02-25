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
 * Keycloak configuration page for local_edulution.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../lib.php');

// Require login and capability.
require_login();
require_capability('local/edulution:manage', context_system::instance());

// Set up the page.
$PAGE->set_url(new moodle_url('/local/edulution/pages/keycloak.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('keycloak_title', 'local_edulution'));
$PAGE->set_heading(get_string('keycloak_title', 'local_edulution'));
$PAGE->set_pagelayout('admin');

// Add JavaScript module.
$PAGE->requires->js_call_amd('local_edulution/keycloak', 'init');

// Handle form submission.
$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $keycloakUrl = required_param('keycloak_url', PARAM_URL);
    $keycloakRealm = required_param('keycloak_realm', PARAM_ALPHANUMEXT);
    $keycloakClientId = required_param('keycloak_client_id', PARAM_ALPHANUMEXT);
    $keycloakClientSecret = required_param('keycloak_client_secret', PARAM_RAW);

    // Validate inputs.
    if (empty($keycloakUrl)) {
        $errors[] = get_string('keycloak_url_required', 'local_edulution');
    }
    if (empty($keycloakRealm)) {
        $errors[] = get_string('keycloak_realm_required', 'local_edulution');
    }
    if (empty($keycloakClientId)) {
        $errors[] = get_string('keycloak_client_id_required', 'local_edulution');
    }

    if (empty($errors)) {
        // Save configuration.
        set_config('keycloak_url', $keycloakUrl, 'local_edulution');
        set_config('keycloak_realm', $keycloakRealm, 'local_edulution');
        set_config('keycloak_client_id', $keycloakClientId, 'local_edulution');
        if (!empty($keycloakClientSecret) && $keycloakClientSecret !== '********') {
            set_config('keycloak_client_secret', $keycloakClientSecret, 'local_edulution');
        }

        // Log activity.
        local_edulution_log_activity_record('keycloak_config', get_string('activity_keycloak_configured', 'local_edulution'));

        redirect(
            new moodle_url('/local/edulution/pages/keycloak.php'),
            get_string('configuration_saved', 'local_edulution'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// Get current configuration.
$keycloakUrl = get_config('local_edulution', 'keycloak_url') ?: '';
$keycloakRealm = get_config('local_edulution', 'keycloak_realm') ?: '';
$keycloakClientId = get_config('local_edulution', 'keycloak_client_id') ?: '';
$keycloakClientSecret = get_config('local_edulution', 'keycloak_client_secret');
$hasClientSecret = !empty($keycloakClientSecret);
$isConfigured = local_edulution_is_keycloak_configured();

// URLs.
$dashboardUrl = new moodle_url('/local/edulution/index.php');
$testUrl = new moodle_url('/local/edulution/ajax/keycloak_test.php');

// Output the page.
echo $OUTPUT->header();

// Render tabs.
$tabs = local_edulution_get_tabs('keycloak');
echo $OUTPUT->tabtree($tabs, 'keycloak');
?>

<div class="container-fluid">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h3><?php echo get_string('keycloak_title', 'local_edulution'); ?></h3>
            <p class="text-muted"><?php echo get_string('keycloak_description', 'local_edulution'); ?></p>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <h5>Errors</h5>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success">
            <?php foreach ($success as $msg): ?>
                <p class="mb-0"><?php echo htmlspecialchars($msg); ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Configuration Status -->
    <?php if ($isConfigured): ?>
        <div class="alert alert-success">
            <strong><?php echo get_string('keycloak_configured', 'local_edulution'); ?></strong>
            <p class="mb-0"><?php echo get_string('keycloak_configured_desc', 'local_edulution'); ?></p>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <strong><?php echo get_string('keycloak_not_configured', 'local_edulution'); ?></strong>
            <p class="mb-0"><?php echo get_string('keycloak_not_configured_desc', 'local_edulution'); ?></p>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Configuration Form -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><?php echo get_string('keycloak_setup_wizard', 'local_edulution'); ?></h5>
                </div>
                <div class="card-body">
                    <form method="post" action="" id="keycloak-config-form">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                        <!-- Step 1: Server Configuration -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2">
                                <span class="badge bg-primary me-2">1</span>
                                <?php echo get_string('step_server', 'local_edulution'); ?>
                            </h5>

                            <div class="mb-3">
                                <label for="keycloak_url" class="form-label">
                                    <?php echo get_string('keycloak_server_url', 'local_edulution'); ?>
                                    <span class="text-danger">*</span>
                                </label>
                                <input type="url" class="form-control" id="keycloak_url" name="keycloak_url"
                                    value="<?php echo htmlspecialchars($keycloakUrl); ?>"
                                    placeholder="https://keycloak.example.com" required>
                                <small class="text-muted">
                                    <?php echo get_string('keycloak_server_url_help', 'local_edulution'); ?>
                                </small>
                            </div>

                            <div class="mb-3">
                                <label for="keycloak_realm" class="form-label">
                                    <?php echo get_string('keycloak_realm', 'local_edulution'); ?>
                                    <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="keycloak_realm" name="keycloak_realm"
                                    value="<?php echo htmlspecialchars($keycloakRealm); ?>" placeholder="master"
                                    required>
                                <small class="text-muted">
                                    <?php echo get_string('keycloak_realm_help', 'local_edulution'); ?>
                                </small>
                            </div>
                        </div>

                        <!-- Step 2: Client Configuration -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2">
                                <span class="badge bg-primary me-2">2</span>
                                <?php echo get_string('step_client', 'local_edulution'); ?>
                            </h5>

                            <div class="mb-3">
                                <label for="keycloak_client_id" class="form-label">
                                    <?php echo get_string('keycloak_client_id', 'local_edulution'); ?>
                                    <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="keycloak_client_id"
                                    name="keycloak_client_id" value="<?php echo htmlspecialchars($keycloakClientId); ?>"
                                    placeholder="moodle-client" required>
                                <small class="text-muted">
                                    <?php echo get_string('keycloak_client_id_help', 'local_edulution'); ?>
                                </small>
                            </div>

                            <div class="mb-3">
                                <label for="keycloak_client_secret" class="form-label">
                                    <?php echo get_string('keycloak_client_secret', 'local_edulution'); ?>
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="keycloak_client_secret"
                                        name="keycloak_client_secret"
                                        value="<?php echo $hasClientSecret ? '********' : ''; ?>"
                                        placeholder="<?php echo $hasClientSecret ? get_string('leave_blank_keep', 'local_edulution') : ''; ?>">
                                    <button class="btn btn-outline-secondary" type="button" id="toggle-secret">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                </div>
                                <small class="text-muted">
                                    <?php echo get_string('keycloak_client_secret_help', 'local_edulution'); ?>
                                </small>
                                <?php if ($hasClientSecret): ?>
                                    <small class="text-success d-block mt-1">
                                        <i class="fa fa-check"></i>
                                        <?php echo get_string('client_secret_set', 'local_edulution'); ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Step 3: Test Connection -->
                        <div class="mb-4">
                            <h5 class="border-bottom pb-2">
                                <span class="badge bg-primary me-2">3</span>
                                <?php echo get_string('step_test', 'local_edulution'); ?>
                            </h5>

                            <div id="connection-test-area">
                                <button type="button" class="btn btn-info" id="test-connection-btn"
                                    data-testurl="<?php echo $testUrl->out(false); ?>">
                                    <i class="fa fa-plug"></i>
                                    <?php echo get_string('test_connection', 'local_edulution'); ?>
                                </button>
                                <div id="connection-result" class="mt-3" style="display: none;"></div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fa fa-save"></i>
                                <?php echo get_string('save_configuration', 'local_edulution'); ?>
                            </button>
                            <a href="<?php echo $dashboardUrl; ?>" class="btn btn-secondary btn-lg">
                                <?php echo get_string('cancel', 'local_edulution'); ?>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Current Configuration Status -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo get_string('current_configuration', 'local_edulution'); ?></h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td><strong><?php echo get_string('status', 'local_edulution'); ?></strong></td>
                            <td>
                                <?php if ($isConfigured): ?>
                                    <span
                                        class="badge bg-success"><?php echo get_string('configured', 'local_edulution'); ?></span>
                                <?php else: ?>
                                    <span
                                        class="badge bg-warning"><?php echo get_string('not_configured', 'local_edulution'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php echo get_string('server_url', 'local_edulution'); ?></strong></td>
                            <td><?php echo $keycloakUrl ? htmlspecialchars($keycloakUrl) : '-'; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php echo get_string('realm', 'local_edulution'); ?></strong></td>
                            <td><?php echo $keycloakRealm ? htmlspecialchars($keycloakRealm) : '-'; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php echo get_string('client_id', 'local_edulution'); ?></strong></td>
                            <td><?php echo $keycloakClientId ? htmlspecialchars($keycloakClientId) : '-'; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php echo get_string('client_secret', 'local_edulution'); ?></strong></td>
                            <td>
                                <?php if ($hasClientSecret): ?>
                                    <span class="text-success"><i class="fa fa-check"></i>
                                        <?php echo get_string('set', 'local_edulution'); ?></span>
                                <?php else: ?>
                                    <span class="text-muted"><?php echo get_string('not_set', 'local_edulution'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Help -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo get_string('help', 'local_edulution'); ?></h5>
                </div>
                <div class="card-body">
                    <p><?php echo get_string('keycloak_help_intro', 'local_edulution'); ?></p>
                    <ol class="small">
                        <li><?php echo get_string('keycloak_help_step1', 'local_edulution'); ?></li>
                        <li><?php echo get_string('keycloak_help_step2', 'local_edulution'); ?></li>
                        <li><?php echo get_string('keycloak_help_step3', 'local_edulution'); ?></li>
                        <li><?php echo get_string('keycloak_help_step4', 'local_edulution'); ?></li>
                    </ol>
                    <a href="https://www.keycloak.org/documentation" target="_blank"
                        class="btn btn-outline-info btn-sm">
                        <i class="fa fa-external-link"></i>
                        <?php echo get_string('keycloak_documentation', 'local_edulution'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Toggle password visibility.
        var toggleBtn = document.getElementById('toggle-secret');
        var secretInput = document.getElementById('keycloak_client_secret');
        if (toggleBtn && secretInput) {
            toggleBtn.addEventListener('click', function () {
                if (secretInput.type === 'password') {
                    secretInput.type = 'text';
                    toggleBtn.innerHTML = '<i class="fa fa-eye-slash"></i>';
                } else {
                    secretInput.type = 'password';
                    toggleBtn.innerHTML = '<i class="fa fa-eye"></i>';
                }
            });
        }

        // Test connection button.
        var testBtn = document.getElementById('test-connection-btn');
        var resultDiv = document.getElementById('connection-result');
        if (testBtn && resultDiv) {
            testBtn.addEventListener('click', function () {
                var testUrl = testBtn.getAttribute('data-testurl');
                var url = document.getElementById('keycloak_url').value;
                var realm = document.getElementById('keycloak_realm').value;
                var clientId = document.getElementById('keycloak_client_id').value;
                var clientSecret = document.getElementById('keycloak_client_secret').value;

                if (!url || !realm || !clientId) {
                    resultDiv.innerHTML = '<div class="alert alert-warning">Please fill in all required fields first.</div>';
                    resultDiv.style.display = 'block';
                    return;
                }

                testBtn.disabled = true;
                testBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Testing...';
                resultDiv.innerHTML = '<div class="alert alert-info">Testing connection...</div>';
                resultDiv.style.display = 'block';

                var formData = new FormData();
                formData.append('sesskey', '<?php echo sesskey(); ?>');
                formData.append('keycloak_url', url);
                formData.append('keycloak_realm', realm);
                formData.append('keycloak_client_id', clientId);
                formData.append('keycloak_client_secret', clientSecret);

                fetch(testUrl, {
                    method: 'POST',
                    body: formData
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data.success) {
                            resultDiv.innerHTML = '<div class="alert alert-success"><i class="fa fa-check"></i> ' + (data.message || 'Connection successful!') + '</div>';
                        } else {
                            resultDiv.innerHTML = '<div class="alert alert-danger"><i class="fa fa-times"></i> ' + (data.message || 'Connection failed.') + '</div>';
                        }
                    })
                    .catch(function (error) {
                        resultDiv.innerHTML = '<div class="alert alert-danger"><i class="fa fa-times"></i> Connection test failed: ' + error.message + '</div>';
                    })
                    .finally(function () {
                        testBtn.disabled = false;
                        testBtn.innerHTML = '<i class="fa fa-plug"></i> <?php echo get_string('test_connection', 'local_edulution'); ?>';
                    });
            });
        }
    });
</script>

<?php
echo $OUTPUT->footer();
