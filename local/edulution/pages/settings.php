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
 * Settings page for local_edulution.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../lib.php');

// Require login and capability.
require_login();
require_capability('local/edulution:manage', context_system::instance());

// Set up the page.
$PAGE->set_url(new moodle_url('/local/edulution/pages/settings.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('settings_title', 'local_edulution'));
$PAGE->set_heading(get_string('settings_title', 'local_edulution'));
$PAGE->set_pagelayout('admin');

// Add JavaScript module.
$PAGE->requires->js_call_amd('local_edulution/settings', 'init');

// Handle form submission.
$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    // General settings.
    $enabled = optional_param('enabled', 0, PARAM_INT);
    set_config('enabled', $enabled, 'local_edulution');

    // Export settings.
    $exportPath = required_param('export_path', PARAM_PATH);
    $exportRetention = required_param('export_retention_days', PARAM_INT);

    // Validate export path.
    if (empty($exportPath)) {
        $errors[] = get_string('export_path_required', 'local_edulution');
    }
    if ($exportRetention < 1) {
        $errors[] = get_string('retention_days_invalid', 'local_edulution');
    }

    if (empty($errors)) {
        set_config('export_path', $exportPath, 'local_edulution');
        set_config('export_retention_days', max(1, $exportRetention), 'local_edulution');

        // Sync settings.
        $keycloakSyncEnabled = optional_param('keycloak_sync_enabled', 0, PARAM_INT);
        $userPattern = optional_param('user_pattern', '', PARAM_RAW);
        $emailPattern = optional_param('email_pattern', '', PARAM_RAW);
        set_config('keycloak_sync_enabled', $keycloakSyncEnabled, 'local_edulution');
        set_config('user_pattern', $userPattern, 'local_edulution');
        set_config('email_pattern', $emailPattern, 'local_edulution');

        // Category mappings.
        $categoryMappings = optional_param('category_mappings', '', PARAM_RAW);
        set_config('category_mappings', $categoryMappings, 'local_edulution');

        // Blacklist settings.
        $userBlacklist = optional_param('user_blacklist', '', PARAM_RAW);
        $emailBlacklist = optional_param('email_blacklist', '', PARAM_RAW);
        set_config('user_blacklist', $userBlacklist, 'local_edulution');
        set_config('email_blacklist', $emailBlacklist, 'local_edulution');

        // Log activity.
        local_edulution_log_activity_record('settings', get_string('activity_settings_updated', 'local_edulution'));

        redirect(
            new moodle_url('/local/edulution/pages/settings.php'),
            get_string('settings_saved', 'local_edulution'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// Get current configuration.
$enabled = get_config('local_edulution', 'enabled');
$exportPath = get_config('local_edulution', 'export_path') ?: $CFG->dataroot . '/edulution/exports';
$exportRetention = get_config('local_edulution', 'export_retention_days') ?: 30;
$keycloakSyncEnabled = get_config('local_edulution', 'keycloak_sync_enabled');
$userPattern = get_config('local_edulution', 'user_pattern') ?: '';
$emailPattern = get_config('local_edulution', 'email_pattern') ?: '';
$categoryMappings = get_config('local_edulution', 'category_mappings') ?: '';
$userBlacklist = get_config('local_edulution', 'user_blacklist') ?: '';
$emailBlacklist = get_config('local_edulution', 'email_blacklist') ?: '';

// Get categories for mapping.
$categories = local_edulution_get_categories_list();

// URLs.
$dashboardUrl = new moodle_url('/local/edulution/index.php');

// Output the page.
echo $OUTPUT->header();

// Render tabs.
$tabs = local_edulution_get_tabs('settings');
echo $OUTPUT->tabtree($tabs, 'settings');
?>

<div class="container-fluid">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h3><?php echo get_string('settings_title', 'local_edulution'); ?></h3>
            <p class="text-muted"><?php echo get_string('settings_description', 'local_edulution'); ?></p>
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

    <form method="post" action="" id="settings-form">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

        <div class="row">
            <div class="col-lg-8">
                <!-- General Settings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo get_string('general_settings', 'local_edulution'); ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="enabled" name="enabled" value="1"
                                   <?php echo $enabled ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="enabled">
                                <?php echo get_string('enable_plugin', 'local_edulution'); ?>
                            </label>
                            <small class="text-muted d-block">
                                <?php echo get_string('enable_plugin_help', 'local_edulution'); ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Export Settings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo get_string('export_settings', 'local_edulution'); ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="export_path" class="form-label">
                                <?php echo get_string('export_directory', 'local_edulution'); ?>
                                <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="export_path" name="export_path"
                                   value="<?php echo htmlspecialchars($exportPath); ?>" required>
                            <small class="text-muted">
                                <?php echo get_string('export_directory_help', 'local_edulution'); ?>
                            </small>
                        </div>

                        <div class="mb-3">
                            <label for="export_retention_days" class="form-label">
                                <?php echo get_string('export_retention', 'local_edulution'); ?>
                                <span class="text-danger">*</span>
                            </label>
                            <div class="input-group" style="max-width: 200px;">
                                <input type="number" class="form-control" id="export_retention_days" name="export_retention_days"
                                       value="<?php echo (int)$exportRetention; ?>" min="1" max="365" required>
                                <span class="input-group-text"><?php echo get_string('days', 'local_edulution'); ?></span>
                            </div>
                            <small class="text-muted">
                                <?php echo get_string('export_retention_help', 'local_edulution'); ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Sync Settings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo get_string('sync_settings', 'local_edulution'); ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" id="keycloak_sync_enabled" name="keycloak_sync_enabled" value="1"
                                   <?php echo $keycloakSyncEnabled ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="keycloak_sync_enabled">
                                <?php echo get_string('enable_keycloak_sync', 'local_edulution'); ?>
                            </label>
                            <small class="text-muted d-block">
                                <?php echo get_string('enable_keycloak_sync_help', 'local_edulution'); ?>
                            </small>
                        </div>

                        <hr>

                        <h6><?php echo get_string('sync_patterns', 'local_edulution'); ?></h6>
                        <p class="text-muted small"><?php echo get_string('sync_patterns_help', 'local_edulution'); ?></p>

                        <div class="mb-3">
                            <label for="user_pattern" class="form-label">
                                <?php echo get_string('user_pattern', 'local_edulution'); ?>
                            </label>
                            <input type="text" class="form-control" id="user_pattern" name="user_pattern"
                                   value="<?php echo htmlspecialchars($userPattern); ?>"
                                   placeholder="^[a-z]+[0-9]*$">
                            <small class="text-muted">
                                <?php echo get_string('user_pattern_help', 'local_edulution'); ?>
                            </small>
                        </div>

                        <div class="mb-3">
                            <label for="email_pattern" class="form-label">
                                <?php echo get_string('email_pattern', 'local_edulution'); ?>
                            </label>
                            <input type="text" class="form-control" id="email_pattern" name="email_pattern"
                                   value="<?php echo htmlspecialchars($emailPattern); ?>"
                                   placeholder="@example\.com$">
                            <small class="text-muted">
                                <?php echo get_string('email_pattern_help', 'local_edulution'); ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Category Mappings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo get_string('category_mappings', 'local_edulution'); ?></h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted"><?php echo get_string('category_mappings_help', 'local_edulution'); ?></p>

                        <div id="category-mappings-container">
                            <?php
                            $mappingsArray = [];
                            if (!empty($categoryMappings)) {
                                $mappingsArray = json_decode($categoryMappings, true) ?: [];
                            }

                            if (empty($mappingsArray)) {
                                $mappingsArray[] = ['group' => '', 'category' => ''];
                            }

                            foreach ($mappingsArray as $index => $mapping):
                            ?>
                            <div class="row mb-2 mapping-row">
                                <div class="col-md-5">
                                    <input type="text" class="form-control mapping-group"
                                           placeholder="<?php echo get_string('keycloak_group', 'local_edulution'); ?>"
                                           value="<?php echo htmlspecialchars($mapping['group'] ?? ''); ?>">
                                </div>
                                <div class="col-md-5">
                                    <select class="form-select mapping-category">
                                        <option value=""><?php echo get_string('select_category', 'local_edulution'); ?></option>
                                        <?php foreach ($categories as $catId => $catName): ?>
                                        <option value="<?php echo $catId; ?>" <?php echo (($mapping['category'] ?? '') == $catId) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($catName); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-outline-danger remove-mapping-btn" title="<?php echo get_string('remove_mapping', 'local_edulution'); ?>">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <input type="hidden" id="category_mappings" name="category_mappings" value="<?php echo htmlspecialchars($categoryMappings); ?>">

                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="add-mapping-btn">
                            <i class="fa fa-plus"></i> <?php echo get_string('add_mapping', 'local_edulution'); ?>
                        </button>
                    </div>
                </div>

                <!-- Blacklist Settings -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo get_string('blacklist_settings', 'local_edulution'); ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="user_blacklist" class="form-label">
                                <?php echo get_string('user_blacklist', 'local_edulution'); ?>
                            </label>
                            <textarea class="form-control" id="user_blacklist" name="user_blacklist" rows="3"
                                      placeholder="admin&#10;guest&#10;support"><?php echo htmlspecialchars($userBlacklist); ?></textarea>
                            <small class="text-muted">
                                <?php echo get_string('user_blacklist_help', 'local_edulution'); ?>
                            </small>
                        </div>

                        <div class="mb-3">
                            <label for="email_blacklist" class="form-label">
                                <?php echo get_string('email_blacklist', 'local_edulution'); ?>
                            </label>
                            <textarea class="form-control" id="email_blacklist" name="email_blacklist" rows="3"
                                      placeholder="admin@example.com&#10;noreply@example.com"><?php echo htmlspecialchars($emailBlacklist); ?></textarea>
                            <small class="text-muted">
                                <?php echo get_string('email_blacklist_help', 'local_edulution'); ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="d-flex gap-2 mb-4">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="fa fa-save"></i> <?php echo get_string('save_settings', 'local_edulution'); ?>
                    </button>
                    <a href="<?php echo $dashboardUrl; ?>" class="btn btn-secondary btn-lg">
                        <?php echo get_string('cancel', 'local_edulution'); ?>
                    </a>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Current Status -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo get_string('current_status', 'local_edulution'); ?></h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <td><strong><?php echo get_string('plugin_enabled', 'local_edulution'); ?></strong></td>
                                <td>
                                    <?php if ($enabled): ?>
                                    <span class="badge bg-success"><?php echo get_string('yes', 'local_edulution'); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo get_string('no', 'local_edulution'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php echo get_string('keycloak_sync', 'local_edulution'); ?></strong></td>
                                <td>
                                    <?php if ($keycloakSyncEnabled): ?>
                                    <span class="badge bg-success"><?php echo get_string('enabled', 'local_edulution'); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo get_string('disabled', 'local_edulution'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php echo get_string('export_path', 'local_edulution'); ?></strong></td>
                                <td class="text-break small"><?php echo htmlspecialchars($exportPath); ?></td>
                            </tr>
                            <tr>
                                <td><strong><?php echo get_string('retention', 'local_edulution'); ?></strong></td>
                                <td><?php echo (int)$exportRetention; ?> <?php echo get_string('days', 'local_edulution'); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Export Path Status -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo get_string('directory_status', 'local_edulution'); ?></h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $pathExists = is_dir($exportPath);
                        $pathWritable = $pathExists && is_writable($exportPath);
                        ?>
                        <table class="table table-sm">
                            <tr>
                                <td><strong><?php echo get_string('exists', 'local_edulution'); ?></strong></td>
                                <td>
                                    <?php if ($pathExists): ?>
                                    <span class="badge bg-success"><?php echo get_string('yes', 'local_edulution'); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-warning"><?php echo get_string('no', 'local_edulution'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong><?php echo get_string('writable', 'local_edulution'); ?></strong></td>
                                <td>
                                    <?php if ($pathWritable): ?>
                                    <span class="badge bg-success"><?php echo get_string('yes', 'local_edulution'); ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-danger"><?php echo get_string('no', 'local_edulution'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                        <?php if (!$pathExists || !$pathWritable): ?>
                        <div class="alert alert-warning small mb-0">
                            <?php echo get_string('directory_warning', 'local_edulution'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo get_string('quick_links', 'local_edulution'); ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="<?php echo new moodle_url('/local/edulution/pages/keycloak.php'); ?>" class="btn btn-outline-primary">
                                <i class="fa fa-key"></i> <?php echo get_string('keycloak_config', 'local_edulution'); ?>
                            </a>
                            <a href="<?php echo new moodle_url('/local/edulution/pages/reports.php'); ?>" class="btn btn-outline-info">
                                <i class="fa fa-chart-bar"></i> <?php echo get_string('view_reports', 'local_edulution'); ?>
                            </a>
                            <a href="<?php echo $dashboardUrl; ?>" class="btn btn-outline-secondary">
                                <i class="fa fa-home"></i> <?php echo get_string('dashboard', 'local_edulution'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var container = document.getElementById('category-mappings-container');
    var addBtn = document.getElementById('add-mapping-btn');
    var hiddenInput = document.getElementById('category_mappings');

    // Categories data for new rows.
    var categories = <?php echo json_encode(array_map(function($id, $name) {
        return ['id' => $id, 'name' => $name];
    }, array_keys($categories), array_values($categories))); ?>;

    // Update hidden input with current mappings.
    function updateMappings() {
        var mappings = [];
        var rows = container.querySelectorAll('.mapping-row');
        rows.forEach(function(row) {
            var group = row.querySelector('.mapping-group').value.trim();
            var category = row.querySelector('.mapping-category').value;
            if (group || category) {
                mappings.push({group: group, category: category});
            }
        });
        hiddenInput.value = JSON.stringify(mappings);
    }

    // Add new mapping row.
    addBtn.addEventListener('click', function() {
        var row = document.createElement('div');
        row.className = 'row mb-2 mapping-row';

        var optionsHtml = '<option value=""><?php echo get_string('select_category', 'local_edulution'); ?></option>';
        categories.forEach(function(cat) {
            optionsHtml += '<option value="' + cat.id + '">' + cat.name.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</option>';
        });

        row.innerHTML =
            '<div class="col-md-5">' +
                '<input type="text" class="form-control mapping-group" placeholder="<?php echo get_string('keycloak_group', 'local_edulution'); ?>">' +
            '</div>' +
            '<div class="col-md-5">' +
                '<select class="form-select mapping-category">' + optionsHtml + '</select>' +
            '</div>' +
            '<div class="col-md-2">' +
                '<button type="button" class="btn btn-outline-danger remove-mapping-btn" title="<?php echo get_string('remove_mapping', 'local_edulution'); ?>">' +
                    '<i class="fa fa-trash"></i>' +
                '</button>' +
            '</div>';

        container.appendChild(row);
        attachRemoveHandler(row.querySelector('.remove-mapping-btn'));
        attachChangeHandlers(row);
    });

    // Remove mapping row.
    function attachRemoveHandler(btn) {
        btn.addEventListener('click', function() {
            var row = btn.closest('.mapping-row');
            if (container.querySelectorAll('.mapping-row').length > 1) {
                row.remove();
                updateMappings();
            } else {
                // Clear the last row instead of removing it.
                row.querySelector('.mapping-group').value = '';
                row.querySelector('.mapping-category').value = '';
                updateMappings();
            }
        });
    }

    // Attach change handlers for updating hidden input.
    function attachChangeHandlers(row) {
        row.querySelector('.mapping-group').addEventListener('change', updateMappings);
        row.querySelector('.mapping-category').addEventListener('change', updateMappings);
    }

    // Initialize existing rows.
    container.querySelectorAll('.remove-mapping-btn').forEach(attachRemoveHandler);
    container.querySelectorAll('.mapping-row').forEach(attachChangeHandlers);

    // Update mappings on form submit.
    document.getElementById('settings-form').addEventListener('submit', function() {
        updateMappings();
    });
});
</script>

<?php
echo $OUTPUT->footer();
