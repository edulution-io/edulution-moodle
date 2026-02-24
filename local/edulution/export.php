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
 * Export page for local_edulution.
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
require_capability('local/edulution:export', $context);

// Set up the page.
$PAGE->set_url(new moodle_url('/local/edulution/export.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_edulution') . ' - Export');
$PAGE->set_heading(get_string('pluginname', 'local_edulution'));
$PAGE->set_pagelayout('admin');

// URLs.
$dashboardurl = new moodle_url('/local/edulution/index.php');

// Get existing exports.
$exportdir = $CFG->dataroot . '/edulution/exports';
$existingexports = [];
if (is_dir($exportdir)) {
    $files = glob($exportdir . '/*.zip');
    foreach ($files as $file) {
        $existingexports[] = [
            'name' => basename($file),
            'size' => local_edulution_format_filesize(filesize($file)),
            'date' => userdate(filemtime($file)),
            'path' => $file,
            'timestamp' => filemtime($file),
        ];
    }
    // Sort by date descending.
    usort($existingexports, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
}

// Handle delete action.
if (optional_param('delete', '', PARAM_FILE) && confirm_sesskey()) {
    $deleteFile = optional_param('delete', '', PARAM_FILE);
    $deletePath = $exportdir . '/' . $deleteFile;
    if (file_exists($deletePath) && strpos(realpath($deletePath), realpath($exportdir)) === 0) {
        unlink($deletePath);
        redirect(new moodle_url('/local/edulution/export.php'), 'Export file deleted successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Output the page.
echo $OUTPUT->header();

// Navigation bar.
echo local_edulution_render_nav('export');
?>

<div class="container-fluid">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <h3>Export Data</h3>
            <p class="text-muted">Export your Moodle data for backup or migration purposes.</p>
        </div>
    </div>

    <div class="row">
        <!-- Export Options -->
        <div class="col-lg-8">
            <!-- Export Options Form -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fa fa-download"></i> Create New Export</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-4">
                        <strong><i class="fa fa-exclamation-triangle"></i> Security Notice:</strong>
                        The export will contain sensitive data including password hashes. Handle the export file securely.
                    </div>

                    <form id="export-form">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

                        <h6 class="mb-3">Export Options</h6>

                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="full_db_export" name="full_db_export" checked>
                                    <label class="form-check-label" for="full_db_export">
                                        <strong>Full Database Export</strong>
                                        <small class="text-muted d-block">Complete MySQL database dump (required for migration)</small>
                                    </label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="include_moodledata" name="include_moodledata">
                                    <label class="form-check-label" for="include_moodledata">
                                        <strong>Include Moodledata</strong>
                                        <small class="text-muted d-block">Include uploaded files and moodledata directory</small>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="include_plugins" name="include_plugins" checked>
                                    <label class="form-check-label" for="include_plugins">
                                        <strong>Include Plugins List</strong>
                                        <small class="text-muted d-block">Export list of installed plugins with versions</small>
                                    </label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" id="include_plugin_code" name="include_plugin_code">
                                    <label class="form-check-label" for="include_plugin_code">
                                        <strong>Include Plugin Code</strong>
                                        <small class="text-muted d-block">Export additional (non-core) plugin files for auto-install</small>
                                    </label>
                                </div>
                                <div class="mb-3">
                                    <label for="compression_level" class="form-label">
                                        <strong>Compression Level</strong>
                                    </label>
                                    <select class="form-select" id="compression_level" name="compression_level">
                                        <option value="1">1 - Fastest (larger file)</option>
                                        <option value="3">3 - Fast</option>
                                        <option value="6" selected>6 - Balanced (recommended)</option>
                                        <option value="9">9 - Maximum (slower)</option>
                                    </select>
                                    <small class="text-muted">Higher compression = smaller file but slower</small>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="exclude_tables" class="form-label">
                                <strong>Exclude Tables (optional)</strong>
                            </label>
                            <input type="text" class="form-control" id="exclude_tables" name="exclude_tables"
                                   placeholder="e.g., sessions,log,task_log">
                            <small class="text-muted">Comma-separated list of tables to exclude (without prefix)</small>
                        </div>

                        <hr>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="button" id="btn-start-export" class="btn btn-primary btn-lg">
                                <i class="fa fa-play"></i> Start Export
                            </button>
                            <button type="button" id="btn-preview-export" class="btn btn-outline-secondary btn-lg">
                                <i class="fa fa-eye"></i> Preview Options
                            </button>
                        </div>
                    </form>

                    <!-- Progress Section (hidden initially) -->
                    <div id="export-progress" class="mt-4" style="display: none;">
                        <hr>
                        <h5><i class="fa fa-spinner fa-spin" id="export-spinner"></i> <span id="export-title">Export in Progress...</span></h5>
                        <div class="progress mb-3" style="height: 25px;">
                            <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;">0%</div>
                        </div>
                        <p id="progress-phase" class="text-muted mb-3">Initializing...</p>

                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <span>Export Log</span>
                                <span id="export-status" class="badge bg-info">Running...</span>
                            </div>
                            <div class="card-body bg-dark text-light" style="max-height: 400px; overflow-y: auto;">
                                <pre id="export-log" class="mb-0" style="white-space: pre-wrap; font-size: 12px;"></pre>
                            </div>
                        </div>

                        <!-- Download Section (hidden initially) -->
                        <div id="download-section" class="mt-4" style="display: none;">
                            <div class="alert alert-success">
                                <h5><i class="fa fa-check-circle"></i> Export Complete!</h5>
                                <p id="export-summary" class="mb-3"></p>
                                <a id="download-link" href="#" class="btn btn-success btn-lg">
                                    <i class="fa fa-download"></i> Download Export File
                                </a>
                                <button type="button" id="btn-new-export" class="btn btn-outline-primary btn-lg ms-2">
                                    <i class="fa fa-plus"></i> Create Another Export
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CLI Reference -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fa fa-terminal"></i> CLI Export (Advanced)</h5>
                </div>
                <div class="card-body">
                    <p>For large exports or automation, use the CLI tool:</p>
                    <pre class="bg-dark text-light p-3 rounded">php local/edulution/cli/full_export.php --full-db --output=/path/to/export.zip</pre>

                    <h6 class="mt-3">Available Options:</h6>
                    <table class="table table-sm">
                        <tr>
                            <td><code>--full-db, -f</code></td>
                            <td>Enable full database export mode</td>
                        </tr>
                        <tr>
                            <td><code>--output=PATH, -o</code></td>
                            <td>Output file path</td>
                        </tr>
                        <tr>
                            <td><code>--include-moodledata, -m</code></td>
                            <td>Include moodledata directory</td>
                        </tr>
                        <tr>
                            <td><code>--exclude-tables=LIST</code></td>
                            <td>Comma-separated tables to exclude</td>
                        </tr>
                        <tr>
                            <td><code>--compression=0-9</code></td>
                            <td>Compression level (default: 6)</td>
                        </tr>
                        <tr>
                            <td><code>--quiet, -q</code></td>
                            <td>Minimal output</td>
                        </tr>
                        <tr>
                            <td><code>--verbose, -v</code></td>
                            <td>Verbose output</td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Previous Exports Sidebar -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fa fa-history"></i> Previous Exports</h5>
                    <span class="badge bg-secondary"><?php echo count($existingexports); ?> files</span>
                </div>
                <div class="card-body">
                    <?php if (empty($existingexports)): ?>
                    <p class="text-muted">No previous exports found.</p>
                    <p class="small">Exports created via this page or CLI will appear here.</p>
                    <?php else: ?>
                    <div class="list-group list-group-flush" style="max-height: 500px; overflow-y: auto;">
                        <?php foreach (array_slice($existingexports, 0, 20) as $export): ?>
                        <div class="list-group-item px-0">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="me-2" style="min-width: 0; flex: 1;">
                                    <strong class="small text-truncate d-block" title="<?php echo htmlspecialchars($export['name']); ?>">
                                        <?php echo htmlspecialchars($export['name']); ?>
                                    </strong>
                                    <small class="text-muted">
                                        <?php echo $export['size']; ?><br>
                                        <?php echo $export['date']; ?>
                                    </small>
                                </div>
                                <div class="btn-group btn-group-sm flex-shrink-0">
                                    <a href="<?php echo new moodle_url('/local/edulution/ajax/download.php', ['file' => $export['name'], 'sesskey' => sesskey()]); ?>"
                                       class="btn btn-outline-primary" title="Download">
                                        <i class="fa fa-download"></i>
                                    </a>
                                    <button type="button" class="btn btn-outline-danger btn-delete-export"
                                            data-filename="<?php echo htmlspecialchars($export['name']); ?>"
                                            title="Delete">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($existingexports) > 20): ?>
                    <p class="text-muted small mt-3">Showing 20 of <?php echo count($existingexports); ?> exports.</p>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Export Info Card -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fa fa-info-circle"></i> Export Contents</h5>
                </div>
                <div class="card-body small">
                    <p>A full export package includes:</p>
                    <ul class="mb-0">
                        <li><strong>manifest.json</strong> - Export metadata</li>
                        <li><strong>database.sql.gz</strong> - Compressed database dump</li>
                        <li><strong>plugins.json</strong> - Plugin versions</li>
                        <li><strong>config_backup.json</strong> - Site configuration</li>
                        <li><strong>moodledata/</strong> - Files (if included)</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmExportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fa fa-download"></i> Confirm Export</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><strong>You are about to create an export with the following options:</strong></p>
                <ul id="export-options-summary" class="mb-3"></ul>

                <div class="alert alert-warning mb-0">
                    <strong><i class="fa fa-exclamation-triangle"></i> Important:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Large exports may take several minutes</li>
                        <li>Keep this page open during the export</li>
                        <li>The export file contains sensitive data</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="btn-confirm-export" class="btn btn-primary">
                    <i class="fa fa-play"></i> Start Export
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteExportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fa fa-trash"></i> Delete Export</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete this export file?</p>
                <p class="text-danger"><strong id="delete-filename"></strong></p>
                <p class="text-muted small mb-0">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="btn-confirm-delete" class="btn btn-danger">
                    <i class="fa fa-trash"></i> Delete
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Export page JS loaded');

    // Elements
    const btnStartExport = document.getElementById('btn-start-export');
    const btnPreviewExport = document.getElementById('btn-preview-export');
    const btnConfirmExport = document.getElementById('btn-confirm-export');
    const btnNewExport = document.getElementById('btn-new-export');
    const exportForm = document.getElementById('export-form');
    const exportProgress = document.getElementById('export-progress');
    const exportSpinner = document.getElementById('export-spinner');
    const exportTitle = document.getElementById('export-title');
    const progressBar = document.getElementById('progress-bar');
    const progressPhase = document.getElementById('progress-phase');
    const exportLog = document.getElementById('export-log');
    const exportStatus = document.getElementById('export-status');
    const downloadSection = document.getElementById('download-section');
    const downloadLink = document.getElementById('download-link');
    const exportSummary = document.getElementById('export-summary');
    const exportOptionsSummary = document.getElementById('export-options-summary');
    const confirmModal = document.getElementById('confirmExportModal');

    let currentJobId = null;
    let pollInterval = null;

    // Get form data
    function getFormData() {
        return {
            full_db_export: document.getElementById('full_db_export').checked,
            include_moodledata: document.getElementById('include_moodledata').checked,
            include_plugins: document.getElementById('include_plugins').checked,
            include_plugin_code: document.getElementById('include_plugin_code').checked,
            compression_level: document.getElementById('compression_level').value,
            exclude_tables: document.getElementById('exclude_tables').value,
        };
    }

    // Helper to show modal (works with different Bootstrap versions)
    function showModal(modalEl) {
        // Try Bootstrap 5
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            return;
        }
        // Try jQuery Bootstrap 4
        if (typeof jQuery !== 'undefined' && jQuery.fn.modal) {
            jQuery(modalEl).modal('show');
            return;
        }
        // Fallback: just show the element
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

    // Show confirmation - use simple confirm() as fallback
    btnStartExport.addEventListener('click', function() {
        console.log('Start export button clicked');
        const options = getFormData();

        // Build summary text
        let summaryText = 'You are about to create an export with:\n\n';
        if (options.full_db_export) summaryText += '- Full database export\n';
        if (options.include_moodledata) summaryText += '- Include moodledata files\n';
        if (options.include_plugins) summaryText += '- Include plugins list\n';
        summaryText += '- Compression level: ' + options.compression_level + '\n';
        if (options.exclude_tables) summaryText += '- Excluding tables: ' + options.exclude_tables + '\n';
        summaryText += '\nThis may take several minutes. Continue?';

        // Try modal first, fallback to confirm()
        if (confirmModal && (typeof bootstrap !== 'undefined' || typeof jQuery !== 'undefined')) {
            // Build HTML summary
            let summary = '';
            if (options.full_db_export) summary += '<li>Full database export</li>';
            if (options.include_moodledata) summary += '<li>Include moodledata files</li>';
            if (options.include_plugins) summary += '<li>Include plugins list</li>';
            summary += '<li>Compression level: ' + options.compression_level + '</li>';
            if (options.exclude_tables) summary += '<li>Excluding tables: ' + options.exclude_tables + '</li>';
            exportOptionsSummary.innerHTML = summary;

            showModal(confirmModal);
        } else {
            // Fallback to simple confirm
            if (confirm(summaryText)) {
                startExport();
            }
        }
    });

    // Preview options
    btnPreviewExport.addEventListener('click', function() {
        const options = getFormData();
        let preview = 'Export Configuration:\n\n';
        preview += 'Full Database: ' + (options.full_db_export ? 'Yes' : 'No') + '\n';
        preview += 'Include Moodledata: ' + (options.include_moodledata ? 'Yes' : 'No') + '\n';
        preview += 'Include Plugins List: ' + (options.include_plugins ? 'Yes' : 'No') + '\n';
        preview += 'Compression Level: ' + options.compression_level + '\n';
        if (options.exclude_tables) {
            preview += 'Exclude Tables: ' + options.exclude_tables + '\n';
        }
        preview += '\nCLI equivalent:\n';
        preview += 'php local/edulution/cli/full_export.php';
        if (options.full_db_export) preview += ' --full-db';
        if (options.include_moodledata) preview += ' --include-moodledata';
        preview += ' --compression=' + options.compression_level;
        if (options.exclude_tables) preview += ' --exclude-tables=' + options.exclude_tables;

        alert(preview);
    });

    // Confirm and start export
    btnConfirmExport.addEventListener('click', function() {
        console.log('Confirm export button clicked');
        hideModal(confirmModal);
        startExport();
    });

    // Start new export
    if (btnNewExport) {
        btnNewExport.addEventListener('click', function() {
            exportProgress.style.display = 'none';
            downloadSection.style.display = 'none';
            exportForm.style.display = 'block';
            btnStartExport.disabled = false;
            exportLog.textContent = '';
            progressBar.style.width = '0%';
            progressBar.textContent = '0%';
        });
    }

    function startExport() {
        // Show progress, hide form buttons
        exportProgress.style.display = 'block';
        downloadSection.style.display = 'none';
        btnStartExport.disabled = true;
        exportLog.textContent = '';
        exportSpinner.className = 'fa fa-spinner fa-spin';
        exportTitle.textContent = 'Export in Progress...';
        exportStatus.textContent = 'Starting...';
        exportStatus.className = 'badge bg-info';
        progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated';

        const options = getFormData();
        const formData = new FormData();
        formData.append('sesskey', '<?php echo sesskey(); ?>');
        formData.append('full_db_export', options.full_db_export ? '1' : '0');
        formData.append('include_moodledata', options.include_moodledata ? '1' : '0');
        formData.append('include_plugins', options.include_plugins ? '1' : '0');
        formData.append('include_plugin_code', options.include_plugin_code ? '1' : '0');
        formData.append('compression_level', options.compression_level);
        formData.append('exclude_tables', options.exclude_tables);

        appendLog('Starting export process...\n');
        appendLog('Options:\n');
        appendLog('  - Full database: ' + (options.full_db_export ? 'Yes' : 'No') + '\n');
        appendLog('  - Include moodledata: ' + (options.include_moodledata ? 'Yes' : 'No') + '\n');
        appendLog('  - Include plugin code: ' + (options.include_plugin_code ? 'Yes' : 'No') + '\n');
        appendLog('  - Compression level: ' + options.compression_level + '\n');
        if (options.exclude_tables) {
            appendLog('  - Excluding tables: ' + options.exclude_tables + '\n');
        }
        appendLog('\n');
        updateProgress(5, 'Initializing export...');

        // Start export via AJAX
        fetch('<?php echo new moodle_url('/local/edulution/ajax/export_handler.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.jobid) {
                currentJobId = data.jobid;
                appendLog('Export job started: ' + currentJobId + '\n');
                appendLog('Waiting for export to complete...\n\n');
                updateProgress(10, 'Export job started');
                exportStatus.textContent = 'Running';

                // Start polling for progress
                pollProgress();
            } else {
                appendLog('ERROR: ' + (data.error || 'Failed to start export') + '\n');
                exportStatus.textContent = 'Failed';
                exportStatus.className = 'badge bg-danger';
                exportSpinner.className = 'fa fa-times-circle text-danger';
                exportTitle.textContent = 'Export Failed';
                btnStartExport.disabled = false;
            }
        })
        .catch(error => {
            appendLog('Network error: ' + error.message + '\n');
            exportStatus.textContent = 'Error';
            exportStatus.className = 'badge bg-danger';
            exportSpinner.className = 'fa fa-times-circle text-danger';
            exportTitle.textContent = 'Export Failed';
            btnStartExport.disabled = false;
        });
    }

    function pollProgress() {
        if (!currentJobId) return;

        pollInterval = setInterval(function() {
            fetch('<?php echo new moodle_url('/local/edulution/ajax/export_progress.php'); ?>?sesskey=<?php echo sesskey(); ?>&jobid=' + currentJobId)
            .then(response => response.json())
            .then(data => {
                if (data.percentage !== undefined) {
                    updateProgress(data.percentage, data.phase || '');
                }
                if (data.message) {
                    progressPhase.textContent = data.message;
                }
                if (data.log) {
                    exportLog.textContent = data.log;
                    exportLog.parentElement.scrollTop = exportLog.parentElement.scrollHeight;
                }

                if (data.completed) {
                    clearInterval(pollInterval);
                    pollInterval = null;

                    if (data.success) {
                        // Export completed successfully
                        exportStatus.textContent = 'Complete';
                        exportStatus.className = 'badge bg-success';
                        exportSpinner.className = 'fa fa-check-circle text-success';
                        exportTitle.textContent = 'Export Complete!';
                        progressBar.className = 'progress-bar bg-success';
                        updateProgress(100, 'Export complete');

                        // Show download section
                        if (data.download_url) {
                            downloadLink.href = data.download_url;
                            let summary = 'File: ' + (data.filename || 'export.zip');
                            if (data.filesize) {
                                summary += ' (' + formatFileSize(data.filesize) + ')';
                            }
                            exportSummary.textContent = summary;
                            downloadSection.style.display = 'block';
                        }

                        // Refresh the page after a delay to update the exports list
                        setTimeout(function() {
                            // Only reload if user hasn't started a new action
                            if (downloadSection.style.display === 'block') {
                                location.reload();
                            }
                        }, 30000);
                    } else {
                        // Export failed
                        exportStatus.textContent = 'Failed';
                        exportStatus.className = 'badge bg-danger';
                        exportSpinner.className = 'fa fa-times-circle text-danger';
                        exportTitle.textContent = 'Export Failed';
                        appendLog('\nExport failed: ' + (data.message || data.error || 'Unknown error') + '\n');
                        btnStartExport.disabled = false;
                    }
                }
            })
            .catch(error => {
                // Silently handle polling errors, don't stop polling
                console.log('Polling error:', error);
            });
        }, 2000);

        // Stop polling after 30 minutes
        setTimeout(function() {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
                appendLog('\nPolling timeout. Check CLI output for status.\n');
            }
        }, 1800000);
    }

    function appendLog(text) {
        exportLog.textContent += text;
        exportLog.parentElement.scrollTop = exportLog.parentElement.scrollHeight;
    }

    function updateProgress(percent, phase) {
        progressBar.style.width = percent + '%';
        progressBar.textContent = percent + '%';
        if (phase) {
            progressPhase.textContent = phase;
        }
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // Delete export handling
    const deleteModal = document.getElementById('deleteExportModal');
    document.querySelectorAll('.btn-delete-export').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const filename = this.dataset.filename;
            document.getElementById('delete-filename').textContent = filename;
            document.getElementById('btn-confirm-delete').href =
                '<?php echo new moodle_url('/local/edulution/export.php'); ?>?delete=' + encodeURIComponent(filename) + '&sesskey=<?php echo sesskey(); ?>';

            showModal(deleteModal);
        });
    });

    console.log('Export page JS setup complete');
});
</script>

<?php
echo $OUTPUT->footer();
