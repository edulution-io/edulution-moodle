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
 * Import page for local_edulution.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

// Require login and capability.
require_login();
$context = context_system::instance();
require_capability('local/edulution:import', $context);

// Set up the page.
$PAGE->set_url(new moodle_url('/local/edulution/import.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_edulution') . ' - Import');
$PAGE->set_heading(get_string('pluginname', 'local_edulution'));
$PAGE->set_pagelayout('admin');

// Constants.
$maxfilesize = 500 * 1024 * 1024; // 500MB

// Handle file upload.
$errors = [];
$success = [];
$packageinfo = null;
$uploadedfile = null;

if (!empty($_FILES['importfile']['name'])) {
    require_sesskey();

    $file = $_FILES['importfile'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload failed. Error code: ' . $file['error'];
    } else if ($file['size'] > $maxfilesize) {
        $errors[] = 'File is too large. Maximum size is ' . local_edulution_format_filesize($maxfilesize);
    } else if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'zip') {
        $errors[] = 'Invalid file type. Please upload a .zip file.';
    } else {
        // Move to import directory.
        $importdir = $CFG->dataroot . '/edulution/imports';
        if (!is_dir($importdir)) {
            mkdir($importdir, 0755, true);
        }

        $destpath = $importdir . '/' . time() . '_' . clean_filename($file['name']);
        if (move_uploaded_file($file['tmp_name'], $destpath)) {
            $uploadedfile = $destpath;
            $SESSION->edulution_import_file = $destpath;

            // Read package info.
            $packageinfo = read_package_info_full($destpath);
            $success[] = 'File uploaded successfully: ' . basename($file['name']);
        } else {
            $errors[] = 'Failed to move uploaded file.';
        }
    }
}

// Check for previously uploaded file.
if (!$uploadedfile && !empty($SESSION->edulution_import_file) && file_exists($SESSION->edulution_import_file)) {
    $uploadedfile = $SESSION->edulution_import_file;
    $packageinfo = read_package_info_full($uploadedfile);
}

// Handle clear action.
if (optional_param('clear', 0, PARAM_INT)) {
    require_sesskey();
    if (!empty($SESSION->edulution_import_file) && file_exists($SESSION->edulution_import_file)) {
        unlink($SESSION->edulution_import_file);
    }
    unset($SESSION->edulution_import_file);
    redirect(new moodle_url('/local/edulution/import.php'));
}

// URLs.
$dashboardurl = new moodle_url('/local/edulution/index.php');

// Output the page.
echo $OUTPUT->header();

// Navigation bar.
echo local_edulution_render_nav('import');
?>

<div class="container-fluid">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h3>Import Data</h3>
            <p class="text-muted">Upload an Edulution export package (.zip) to import data into this Moodle instance.</p>
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

    <?php if (!$uploadedfile): ?>
    <!-- Upload Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Upload Export Package</h5>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo $maxfilesize; ?>">

                <div class="mb-3">
                    <label for="importfile" class="form-label">Select Export File (.zip)</label>
                    <input type="file" class="form-control" id="importfile" name="importfile" accept=".zip" required>
                    <small class="text-muted">Maximum file size: <?php echo local_edulution_format_filesize($maxfilesize); ?></small>
                </div>

                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fa fa-upload"></i> Upload and Preview
                </button>
            </form>
        </div>
    </div>

    <!-- CLI Note -->
    <div class="alert alert-info">
        <strong>Tip:</strong> For full database imports (complete migration), use the CLI tool:
        <pre class="mt-2 mb-0">php local/edulution/cli/full_import.php --file=/path/to/export.zip --wwwroot=https://your-site.com</pre>
    </div>

    <?php else: ?>
    <!-- Package Analysis -->
    <div class="row">
        <div class="col-lg-8">
            <!-- Package Info -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fa fa-check-circle"></i> Package Uploaded Successfully</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>File:</strong> <?php echo htmlspecialchars(basename($uploadedfile)); ?></p>
                            <p><strong>Size:</strong> <?php echo local_edulution_format_filesize(filesize($uploadedfile)); ?></p>
                        </div>
                        <div class="col-md-6">
                            <?php if ($packageinfo && $packageinfo['manifest']): ?>
                            <p><strong>Export Type:</strong> <?php echo htmlspecialchars($packageinfo['manifest']['export_type'] ?? 'Unknown'); ?></p>
                            <p><strong>Export Date:</strong> <?php echo htmlspecialchars($packageinfo['manifest']['export_timestamp'] ?? 'Unknown'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($packageinfo): ?>
            <!-- Package Contents -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Package Contents</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 text-center mb-3">
                            <div class="card <?php echo $packageinfo['has_database'] ? 'border-success' : 'border-secondary'; ?>">
                                <div class="card-body py-3">
                                    <i class="fa fa-database fa-2x <?php echo $packageinfo['has_database'] ? 'text-success' : 'text-muted'; ?>"></i>
                                    <p class="mb-0 mt-2"><strong>Database</strong></p>
                                    <span class="badge <?php echo $packageinfo['has_database'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $packageinfo['has_database'] ? 'Included' : 'Not included'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 text-center mb-3">
                            <div class="card <?php echo $packageinfo['has_moodledata'] ? 'border-success' : 'border-secondary'; ?>">
                                <div class="card-body py-3">
                                    <i class="fa fa-folder fa-2x <?php echo $packageinfo['has_moodledata'] ? 'text-success' : 'text-muted'; ?>"></i>
                                    <p class="mb-0 mt-2"><strong>Moodledata</strong></p>
                                    <span class="badge <?php echo $packageinfo['has_moodledata'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $packageinfo['has_moodledata'] ? 'Included' : 'Not included'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 text-center mb-3">
                            <div class="card <?php echo $packageinfo['has_plugins'] ? 'border-success' : 'border-secondary'; ?>">
                                <div class="card-body py-3">
                                    <i class="fa fa-plug fa-2x <?php echo $packageinfo['has_plugins'] ? 'text-success' : 'text-muted'; ?>"></i>
                                    <p class="mb-0 mt-2"><strong>Plugins</strong></p>
                                    <span class="badge <?php echo $packageinfo['has_plugins'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $packageinfo['has_plugins'] ? 'Included' : 'Not included'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 text-center mb-3">
                            <div class="card <?php echo $packageinfo['has_manifest'] ? 'border-success' : 'border-warning'; ?>">
                                <div class="card-body py-3">
                                    <i class="fa fa-file-text fa-2x <?php echo $packageinfo['has_manifest'] ? 'text-success' : 'text-warning'; ?>"></i>
                                    <p class="mb-0 mt-2"><strong>Manifest</strong></p>
                                    <span class="badge <?php echo $packageinfo['has_manifest'] ? 'bg-success' : 'bg-warning'; ?>">
                                        <?php echo $packageinfo['has_manifest'] ? 'Included' : 'Missing'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($packageinfo['manifest']): ?>
            <!-- Source System Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Source System Information</h5>
                </div>
                <div class="card-body">
                    <?php $source = $packageinfo['manifest']['source_moodle'] ?? []; ?>
                    <table class="table table-bordered mb-0">
                        <tr>
                            <td width="30%"><strong>Site Name</strong></td>
                            <td><?php echo htmlspecialchars($source['site_name'] ?? 'Unknown'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Site URL</strong></td>
                            <td><?php echo htmlspecialchars($source['wwwroot'] ?? 'Unknown'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Moodle Version</strong></td>
                            <td><?php echo htmlspecialchars($source['release'] ?? $source['version'] ?? 'Unknown'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Database Type</strong></td>
                            <td><?php echo htmlspecialchars($source['dbtype'] ?? 'Unknown'); ?></td>
                        </tr>
                    </table>

                    <?php if (!empty($packageinfo['manifest']['statistics'])): ?>
                    <h6 class="mt-4 mb-3">Export Statistics</h6>
                    <?php $stats = $packageinfo['manifest']['statistics']; ?>
                    <div class="row text-center">
                        <?php if (isset($stats['database_tables'])): ?>
                        <div class="col-md-3">
                            <div class="border rounded p-2">
                                <h4 class="text-primary mb-0"><?php echo number_format($stats['database_tables']); ?></h4>
                                <small>Database Tables</small>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($stats['database_size_formatted'])): ?>
                        <div class="col-md-3">
                            <div class="border rounded p-2">
                                <h4 class="text-info mb-0"><?php echo htmlspecialchars($stats['database_size_formatted']); ?></h4>
                                <small>Database Size</small>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($stats['plugins_total'])): ?>
                        <div class="col-md-3">
                            <div class="border rounded p-2">
                                <h4 class="text-success mb-0"><?php echo number_format($stats['plugins_total']); ?></h4>
                                <small>Total Plugins</small>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($stats['plugins_additional'])): ?>
                        <div class="col-md-3">
                            <div class="border rounded p-2">
                                <h4 class="text-warning mb-0"><?php echo number_format($stats['plugins_additional']); ?></h4>
                                <small>Additional Plugins</small>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($packageinfo['plugins']): ?>
            <!-- Plugin Comparison -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Plugin Comparison</h5>
                </div>
                <div class="card-body">
                    <?php
                    // Get local plugins for comparison
                    $pluginManager = core_plugin_manager::instance();
                    $localPlugins = [];
                    foreach ($pluginManager->get_plugins() as $type => $plugins) {
                        foreach ($plugins as $name => $info) {
                            $localPlugins[$type . '_' . $name] = $info->versiondb ?? $info->versiondisk ?? null;
                        }
                    }

                    // Filter to show only additional (non-core) plugins
                    $additionalPlugins = array_filter($packageinfo['plugins']['plugins'] ?? [], function($p) {
                        return !($p['is_core'] ?? true);
                    });

                    $missing = [];
                    $different = [];
                    $same = [];

                    foreach ($additionalPlugins as $plugin) {
                        $component = $plugin['component'];
                        if (!isset($localPlugins[$component])) {
                            $missing[] = $plugin;
                        } else if ($localPlugins[$component] != $plugin['version']) {
                            $different[] = [
                                'plugin' => $plugin,
                                'local_version' => $localPlugins[$component],
                            ];
                        } else {
                            $same[] = $plugin;
                        }
                    }
                    ?>

                    <p>Comparing <strong><?php echo count($additionalPlugins); ?></strong> additional plugins from export with your current installation:</p>

                    <?php if (!empty($missing)): ?>
                    <h6 class="text-danger"><i class="fa fa-exclamation-triangle"></i> Missing Plugins (<?php echo count($missing); ?>)</h6>
                    <p class="small text-muted">These plugins are in the export but NOT installed here:</p>
                    <table class="table table-sm table-bordered mb-4">
                        <thead class="table-danger">
                            <tr>
                                <th>Plugin</th>
                                <th>Type</th>
                                <th>Export Version</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($missing, 0, 10) as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['component']); ?></td>
                                <td><?php echo htmlspecialchars($p['type']); ?></td>
                                <td><?php echo htmlspecialchars($p['version'] ?? 'Unknown'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (count($missing) > 10): ?>
                            <tr><td colspan="3" class="text-muted">... and <?php echo count($missing) - 10; ?> more</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <?php if (!empty($different)): ?>
                    <h6 class="text-warning"><i class="fa fa-refresh"></i> Version Differences (<?php echo count($different); ?>)</h6>
                    <p class="small text-muted">These plugins have different versions:</p>
                    <table class="table table-sm table-bordered mb-4">
                        <thead class="table-warning">
                            <tr>
                                <th>Plugin</th>
                                <th>Export Version</th>
                                <th>Local Version</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($different, 0, 10) as $d): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($d['plugin']['component']); ?></td>
                                <td><?php echo htmlspecialchars($d['plugin']['version'] ?? 'Unknown'); ?></td>
                                <td><?php echo htmlspecialchars($d['local_version'] ?? 'Unknown'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (count($different) > 10): ?>
                            <tr><td colspan="3" class="text-muted">... and <?php echo count($different) - 10; ?> more</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>

                    <?php if (!empty($same)): ?>
                    <h6 class="text-success"><i class="fa fa-check"></i> Matching Plugins (<?php echo count($same); ?>)</h6>
                    <p class="small text-muted">These plugins match your local installation.</p>
                    <?php endif; ?>

                    <?php if (empty($missing) && empty($different)): ?>
                    <div class="alert alert-success mb-0">
                        <i class="fa fa-check-circle"></i> All additional plugins match your local installation!
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Import Options -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Import Options</h5>
                </div>
                <div class="card-body">
                    <?php if ($packageinfo['has_database']): ?>
                    <div class="alert alert-warning mb-4">
                        <h5><i class="fa fa-exclamation-triangle"></i> Full Database Import</h5>
                        <p>This package contains a full database dump. Importing will <strong>replace your entire database</strong>.</p>
                        <p class="mb-0"><strong>Important:</strong> You will be logged out after import. Log in again with credentials from the imported database.</p>
                    </div>

                    <!-- Import Form -->
                    <form id="import-form">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="file" value="<?php echo htmlspecialchars($uploadedfile); ?>">

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="import_database" name="import_database" checked>
                                    <label class="form-check-label" for="import_database">
                                        <strong>Import Database</strong>
                                        <small class="text-muted d-block">Restore full database from export</small>
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="import_moodledata" name="import_moodledata" <?php echo $packageinfo['has_moodledata'] ? 'checked' : 'disabled'; ?>>
                                    <label class="form-check-label" for="import_moodledata">
                                        <strong>Import Moodledata</strong>
                                        <small class="text-muted d-block">Restore uploaded files <?php echo !$packageinfo['has_moodledata'] ? '(not in package)' : ''; ?></small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="skip_plugins" name="skip_plugins">
                                    <label class="form-check-label" for="skip_plugins">
                                        <strong>Skip Plugin Tables</strong>
                                        <small class="text-muted d-block">Don't import plugin-specific data</small>
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="dry_run" name="dry_run">
                                    <label class="form-check-label" for="dry_run">
                                        <strong>Dry Run (Test Only)</strong>
                                        <small class="text-muted d-block">Validate without making changes</small>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <div class="d-flex gap-2">
                            <button type="button" id="btn-start-import" class="btn btn-danger btn-lg">
                                <i class="fa fa-play"></i> Start Import
                            </button>
                            <button type="button" id="btn-dry-run" class="btn btn-warning btn-lg">
                                <i class="fa fa-search"></i> Test Import (Dry Run)
                            </button>
                        </div>
                    </form>

                    <!-- Progress Section (hidden initially) -->
                    <div id="import-progress" class="mt-4" style="display: none;">
                        <h5><i class="fa fa-spinner fa-spin"></i> Import in Progress...</h5>
                        <div class="progress mb-3" style="height: 25px;">
                            <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;">0%</div>
                        </div>
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span>Import Log</span>
                                <span id="import-status" class="badge bg-info">Running...</span>
                            </div>
                            <div class="card-body bg-dark text-light" style="max-height: 400px; overflow-y: auto;">
                                <pre id="import-log" class="mb-0" style="white-space: pre-wrap; font-size: 12px;"></pre>
                            </div>
                        </div>
                    </div>

                    <?php else: ?>
                    <div class="alert alert-info">
                        <h5><i class="fa fa-info-circle"></i> Metadata-Only Package</h5>
                        <p class="mb-0">This package contains only metadata (plugins list, manifest). It's useful for:</p>
                        <ul class="mb-0 mt-2">
                            <li>Comparing plugin versions between Moodle instances</li>
                            <li>Checking compatibility before a full migration</li>
                            <li>Documenting a Moodle installation's configuration</li>
                        </ul>
                    </div>
                    <p>To create a full export with database, use:</p>
                    <pre class="bg-dark text-light p-3 rounded">docker exec -it moodle-test php /var/www/html/moodle/local/edulution/cli/full_export.php \
    --full-db \
    --include-moodledata \
    --output=/var/moodledata/full_export.zip</pre>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Confirmation Modal -->
            <div class="modal fade" id="confirmImportModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title"><i class="fa fa-exclamation-triangle"></i> Confirm Import</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p><strong>Warning:</strong> This action will:</p>
                            <ul>
                                <li>Replace your entire Moodle database</li>
                                <li>Log you out of this session</li>
                                <li>Overwrite all existing courses, users, and content</li>
                            </ul>
                            <p class="text-danger"><strong>This cannot be undone!</strong></p>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="confirm-checkbox">
                                <label class="form-check-label" for="confirm-checkbox">
                                    I understand and want to proceed with the import
                                </label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" id="btn-confirm-import" class="btn btn-danger" disabled>
                                <i class="fa fa-play"></i> Start Import
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="d-flex gap-2 mb-4">
                <a href="<?php echo new moodle_url('/local/edulution/import.php', ['clear' => 1, 'sesskey' => sesskey()]); ?>"
                   class="btn btn-secondary btn-lg">
                    <i class="fa fa-times"></i> Clear and Upload New File
                </a>
                <a href="<?php echo $dashboardurl; ?>" class="btn btn-outline-primary btn-lg">
                    <i class="fa fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- All Files in Package -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Files in Package</h5>
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <?php if (!empty($packageinfo['files'])): ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($packageinfo['files'] as $file): ?>
                        <li>
                            <i class="fa fa-file-o text-muted"></i>
                            <?php echo htmlspecialchars($file); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <p class="text-muted mb-0">No files found.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Help -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Need Help?</h5>
                </div>
                <div class="card-body">
                    <p class="small">For full migrations including database and files, always use the CLI tools.</p>
                    <h6>CLI Commands:</h6>
                    <p class="small mb-1"><strong>Export:</strong></p>
                    <code class="small">php cli/full_export.php --help</code>
                    <p class="small mb-1 mt-2"><strong>Import:</strong></p>
                    <code class="small">php cli/full_import.php --help</code>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
// Add JavaScript for import functionality.
if ($uploadedfile && $packageinfo && $packageinfo['has_database']):
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Import page JS loaded');

    const btnStartImport = document.getElementById('btn-start-import');
    const btnDryRun = document.getElementById('btn-dry-run');
    const btnConfirmImport = document.getElementById('btn-confirm-import');
    const confirmCheckbox = document.getElementById('confirm-checkbox');
    const importProgress = document.getElementById('import-progress');
    const importForm = document.getElementById('import-form');
    const progressBar = document.getElementById('progress-bar');
    const importLog = document.getElementById('import-log');
    const importStatus = document.getElementById('import-status');
    const confirmModal = document.getElementById('confirmImportModal');

    let isDryRun = false;
    let currentJobId = null;
    let pollInterval = null;

    // Helper to show modal (works with different Bootstrap versions)
    function showModal(modalEl) {
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            return;
        }
        if (typeof jQuery !== 'undefined' && jQuery.fn.modal) {
            jQuery(modalEl).modal('show');
            return;
        }
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        document.body.classList.add('modal-open');
    }

    // Helper to hide modal
    function hideModal(modalEl) {
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            return;
        }
        if (typeof jQuery !== 'undefined' && jQuery.fn.modal) {
            jQuery(modalEl).modal('hide');
            return;
        }
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        document.body.classList.remove('modal-open');
    }

    // Enable confirm button when checkbox is checked
    confirmCheckbox.addEventListener('change', function() {
        btnConfirmImport.disabled = !this.checked;
    });

    // Dry run button - skip confirmation
    btnDryRun.addEventListener('click', function() {
        console.log('Dry run button clicked');
        isDryRun = true;
        document.getElementById('dry_run').checked = true;
        startImport();
    });

    // Start import button - show confirmation modal or use confirm()
    btnStartImport.addEventListener('click', function() {
        console.log('Start import button clicked');
        isDryRun = document.getElementById('dry_run').checked;
        if (isDryRun) {
            startImport();
        } else {
            // Try modal, fallback to confirm()
            if (confirmModal && (typeof bootstrap !== 'undefined' || typeof jQuery !== 'undefined')) {
                showModal(confirmModal);
            } else {
                if (confirm('WARNING: This will replace your entire database!\n\nThis cannot be undone. Continue?')) {
                    startImport();
                }
            }
        }
    });

    // Confirm import button
    btnConfirmImport.addEventListener('click', function() {
        console.log('Confirm import button clicked');
        hideModal(confirmModal);
        startImport();
    });

    function startImport() {
        // Hide form, show progress
        importForm.style.display = 'none';
        importProgress.style.display = 'block';
        importLog.textContent = '';

        // Collect form data
        const formData = new FormData();
        formData.append('sesskey', '<?php echo sesskey(); ?>');
        formData.append('file', '<?php echo addslashes($uploadedfile); ?>');
        formData.append('wwwroot', '<?php echo addslashes($CFG->wwwroot); ?>');
        formData.append('import_database', document.getElementById('import_database').checked ? '1' : '0');
        formData.append('import_moodledata', document.getElementById('import_moodledata').checked ? '1' : '0');
        formData.append('skip_plugins', document.getElementById('skip_plugins').checked ? '1' : '0');
        formData.append('dry_run', document.getElementById('dry_run').checked ? '1' : '0');

        // Start import via AJAX
        appendLog('Starting import process...\n');
        updateProgress(5);

        fetch('<?php echo new moodle_url('/local/edulution/ajax/import_handler.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.jobid) {
                currentJobId = data.jobid;
                appendLog('Import job started: ' + currentJobId + '\n');
                appendLog('Processing import package...\n\n');
                updateProgress(10);
                importStatus.textContent = 'Running';

                // Start polling for progress with the jobid
                pollProgress(currentJobId);
            } else {
                appendLog('\nERROR: ' + (data.error || 'Failed to start import'));
                importStatus.textContent = 'Failed';
                importStatus.className = 'badge bg-danger';
            }
        })
        .catch(error => {
            appendLog('\nNetwork error: ' + error.message);
            importStatus.textContent = 'Error';
            importStatus.className = 'badge bg-danger';
        });
    }

    function appendLog(text) {
        importLog.textContent += text;
        importLog.parentElement.scrollTop = importLog.parentElement.scrollHeight;
    }

    function updateProgress(percent) {
        progressBar.style.width = percent + '%';
        progressBar.textContent = percent + '%';
    }

    function pollProgress(jobId) {
        if (!jobId) return;

        // Poll for progress file every 2 seconds
        pollInterval = setInterval(function() {
            fetch('<?php echo new moodle_url('/local/edulution/ajax/import_progress.php'); ?>?jobid=' + jobId)
            .then(response => response.json())
            .then(data => {
                if (data.percentage !== undefined) {
                    updateProgress(data.percentage);
                }
                if (data.message) {
                    // Update status text
                }
                if (data.log) {
                    importLog.textContent = data.log;
                    importLog.parentElement.scrollTop = importLog.parentElement.scrollHeight;
                }

                if (data.completed || data.complete) {
                    clearInterval(pollInterval);
                    pollInterval = null;

                    if (data.success) {
                        // Import completed successfully
                        importStatus.textContent = isDryRun ? 'Dry Run Complete' : 'Import Complete';
                        importStatus.className = 'badge bg-success';
                        progressBar.className = 'progress-bar bg-success';
                        updateProgress(100);

                        if (!isDryRun && data.redirect) {
                            appendLog('\n\nImport complete! Redirecting to login page in 5 seconds...');
                            setTimeout(function() {
                                window.location.href = data.redirect;
                            }, 5000);
                        } else if (isDryRun) {
                            appendLog('\n\nDry run complete. No changes were made to the database.');
                        }
                    } else {
                        // Import failed
                        importStatus.textContent = 'Failed';
                        importStatus.className = 'badge bg-danger';
                        appendLog('\n\nImport failed: ' + (data.message || data.error || 'Unknown error'));
                    }
                }
            })
            .catch((error) => {
                // Silently handle polling errors
                console.log('Polling error:', error);
            });
        }, 2000);

        // Stop polling after 30 minutes
        setTimeout(function() {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
                appendLog('\n\nPolling timeout. Check progress manually.');
            }
        }, 1800000);
    }
});
</script>
<?php
endif;

echo $OUTPUT->footer();

/**
 * Read full package information from a ZIP file including plugins.json content.
 *
 * @param string $filepath Path to the ZIP file.
 * @return array Package info.
 */
function read_package_info_full($filepath) {
    $info = [
        'has_database' => false,
        'has_moodledata' => false,
        'has_plugins' => false,
        'has_manifest' => false,
        'manifest' => null,
        'plugins' => null,
        'files' => [],
    ];

    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return $info;
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $info['files'][] = $name;

        if (strpos($name, 'database') !== false || strpos($name, '.sql') !== false) {
            $info['has_database'] = true;
        }
        if (strpos($name, 'moodledata') !== false || strpos($name, 'filedir') !== false) {
            $info['has_moodledata'] = true;
        }
        if ($name === 'plugins.json' || strpos($name, 'plugins.json') !== false) {
            $info['has_plugins'] = true;
            $content = $zip->getFromIndex($i);
            $info['plugins'] = json_decode($content, true);
        }
        if ($name === 'manifest.json') {
            $info['has_manifest'] = true;
            $content = $zip->getFromIndex($i);
            $info['manifest'] = json_decode($content, true);
        }
    }

    $zip->close();
    return $info;
}
