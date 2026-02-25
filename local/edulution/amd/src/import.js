/**
 * Import page functionality for edulution plugin.
 *
 * Handles drag & drop file upload, file validation, upload progress,
 * preview display, and import progress tracking.
 *
 * @module     local_edulution/import
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification', 'core/str', 'core/templates', 'local_edulution/common'],
function($, Ajax, Notification, Str, Templates, Common) {

    /**
     * Module configuration.
     * @type {object}
     */
    var config = {
        maxFileSize: 50 * 1024 * 1024, // 50MB
        allowedTypes: ['text/csv', 'application/json', 'application/xml', 'text/xml'],
        allowedExtensions: ['.csv', '.json', '.xml'],
        pollInterval: 2000,
        maxPollAttempts: 300
    };

    /**
     * Current import state.
     * @type {object}
     */
    var state = {
        file: null,
        uploadId: null,
        importId: null,
        polling: false,
        pollTimer: null,
        pollAttempts: 0,
        progressBar: null,
        previewData: null
    };

    /**
     * DOM element references.
     * @type {object}
     */
    var elements = {
        dropZone: null,
        fileInput: null,
        fileInfo: null,
        uploadProgress: null,
        previewContainer: null,
        importProgress: null,
        resultsContainer: null,
        uploadBtn: null,
        importBtn: null,
        cancelBtn: null,
        resetBtn: null
    };

    /**
     * Initialize the import module.
     *
     * @param {object} options - Configuration options.
     */
    var init = function(options) {
        config = $.extend({}, config, options);

        // Cache element references
        elements.dropZone = $('#import-drop-zone');
        elements.fileInput = $('#import-file-input');
        elements.fileInfo = $('#import-file-info');
        elements.uploadProgress = $('#import-upload-progress');
        elements.previewContainer = $('#import-preview-container');
        elements.importProgress = $('#import-progress-container');
        elements.resultsContainer = $('#import-results-container');
        elements.uploadBtn = $('#import-upload-btn');
        elements.importBtn = $('#import-start-btn');
        elements.cancelBtn = $('#import-cancel-btn');
        elements.resetBtn = $('#import-reset-btn');

        // Initialize components
        initDropZone();
        initFileInput();
        initButtons();
    };

    /**
     * Initialize drag and drop zone.
     */
    var initDropZone = function() {
        var $zone = elements.dropZone;

        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function(eventName) {
            $zone.on(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
            });
        });

        // Highlight drop zone when dragging over
        ['dragenter', 'dragover'].forEach(function(eventName) {
            $zone.on(eventName, function() {
                $zone.addClass('drag-over');
            });
        });

        // Remove highlight when leaving
        ['dragleave', 'drop'].forEach(function(eventName) {
            $zone.on(eventName, function() {
                $zone.removeClass('drag-over');
            });
        });

        // Handle dropped files
        $zone.on('drop', function(e) {
            var files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                handleFileSelect(files[0]);
            }
        });

        // Click to browse
        $zone.on('click', function() {
            elements.fileInput.trigger('click');
        });
    };

    /**
     * Initialize file input.
     */
    var initFileInput = function() {
        elements.fileInput.on('change', function() {
            var files = this.files;
            if (files.length > 0) {
                handleFileSelect(files[0]);
            }
        });
    };

    /**
     * Initialize button handlers.
     */
    var initButtons = function() {
        // Upload button
        elements.uploadBtn.on('click', function(e) {
            e.preventDefault();
            uploadFile();
        });

        // Import button
        elements.importBtn.on('click', function(e) {
            e.preventDefault();
            startImport();
        });

        // Cancel button
        elements.cancelBtn.on('click', function(e) {
            e.preventDefault();
            cancelImport();
        });

        // Reset button
        elements.resetBtn.on('click', function(e) {
            e.preventDefault();
            resetImport();
        });
    };

    /**
     * Handle file selection.
     *
     * @param {File} file - Selected file.
     */
    var handleFileSelect = function(file) {
        // Validate file
        var validation = validateFile(file);
        if (!validation.valid) {
            Common.notifyError(validation.message);
            return;
        }

        state.file = file;
        showFileInfo(file);
        elements.uploadBtn.show().prop('disabled', false);
    };

    /**
     * Validate selected file.
     *
     * @param {File} file - File to validate.
     * @returns {object} Validation result with valid boolean and message.
     */
    var validateFile = function(file) {
        // Check file size
        if (file.size > config.maxFileSize) {
            return {
                valid: false,
                message: 'File is too large. Maximum size is ' + Common.formatFileSize(config.maxFileSize)
            };
        }

        // Check file type
        var extension = '.' + file.name.split('.').pop().toLowerCase();
        var typeValid = config.allowedTypes.includes(file.type) ||
                        config.allowedExtensions.includes(extension);

        if (!typeValid) {
            return {
                valid: false,
                message: 'Invalid file type. Allowed types: ' + config.allowedExtensions.join(', ')
            };
        }

        // Check if file is empty
        if (file.size === 0) {
            return {
                valid: false,
                message: 'File is empty'
            };
        }

        return { valid: true };
    };

    /**
     * Show file information.
     *
     * @param {File} file - Selected file.
     */
    var showFileInfo = function(file) {
        var html = '<div class="file-info-card card">' +
            '<div class="card-body">' +
            '<div class="d-flex align-items-center">' +
            '<i class="fa fa-file-o fa-2x mr-3 text-primary"></i>' +
            '<div class="flex-grow-1">' +
            '<h5 class="mb-1">' + Common.escapeHtml(file.name) + '</h5>' +
            '<small class="text-muted">' +
            Common.formatFileSize(file.size) + ' | ' +
            (file.type || 'Unknown type') +
            '</small>' +
            '</div>' +
            '<button type="button" class="btn btn-sm btn-outline-danger remove-file">' +
            '<i class="fa fa-times"></i>' +
            '</button>' +
            '</div>' +
            '</div>' +
            '</div>';

        elements.fileInfo.html(html).show();
        elements.dropZone.hide();

        // Handle remove file button
        elements.fileInfo.find('.remove-file').on('click', function(e) {
            e.stopPropagation();
            clearFileSelection();
        });
    };

    /**
     * Clear file selection.
     */
    var clearFileSelection = function() {
        state.file = null;
        elements.fileInput.val('');
        elements.fileInfo.hide().empty();
        elements.dropZone.show();
        elements.uploadBtn.hide();
    };

    /**
     * Upload the selected file.
     */
    var uploadFile = function() {
        if (!state.file) {
            Common.notifyError('No file selected');
            return;
        }

        // Show upload progress
        elements.uploadProgress.show();
        elements.uploadBtn.prop('disabled', true);

        // Create progress bar
        var progressBar = Common.progressBar(elements.uploadProgress.find('.progress-wrapper'), {
            value: 0,
            label: 'Uploading file...',
            type: 'info'
        });

        // Create FormData
        var formData = new FormData();
        formData.append('importfile', state.file);
        formData.append('sesskey', Common.getSesskey());

        // Upload via AJAX
        $.ajax({
            url: Common.getPluginUrl() + '/ajax/upload.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        var percent = Math.round((e.loaded / e.total) * 100);
                        progressBar.update(percent, 'Uploading... ' + percent + '%');
                    }
                }, false);
                return xhr;
            }
        }).done(function(response) {
            if (response.success) {
                progressBar.complete();
                progressBar.update(100, 'Upload complete!');
                state.uploadId = response.uploadId;
                setTimeout(function() {
                    elements.uploadProgress.hide();
                    progressBar.destroy();
                    loadPreview();
                }, 1000);
            } else {
                progressBar.error(response.message || 'Upload failed');
                Common.notifyError(response.message || 'Upload failed');
                elements.uploadBtn.prop('disabled', false);
            }
        }).fail(function(xhr, status, error) {
            progressBar.error('Upload failed: ' + error);
            Common.notifyError('Upload failed: ' + error);
            elements.uploadBtn.prop('disabled', false);
        });
    };

    /**
     * Load and display import preview.
     */
    var loadPreview = function() {
        if (!state.uploadId) {
            Common.notifyError('No upload ID');
            return;
        }

        var loader = Common.showLoading(elements.previewContainer, 'Analyzing file...');
        elements.previewContainer.show();

        Common.ajax('local_edulution_get_import_preview', {
            uploadId: state.uploadId
        }).then(function(response) {
            loader.hide();
            if (response.success) {
                state.previewData = response;
                renderPreview(response);
            } else {
                Common.notifyError(response.message || 'Failed to analyze file');
            }
        }).catch(function(error) {
            loader.hide();
            Common.notifyError('Failed to analyze file: ' + error.message);
        });
    };

    /**
     * Render import preview.
     *
     * @param {object} data - Preview data from server.
     */
    var renderPreview = function(data) {
        var context = {
            filename: state.file ? state.file.name : 'Unknown',
            recordCount: Common.formatNumber(data.recordCount || 0),
            columns: data.columns || [],
            sampleRows: data.sampleRows || [],
            warnings: data.warnings || [],
            errors: data.errors || [],
            hasWarnings: (data.warnings && data.warnings.length > 0),
            hasErrors: (data.errors && data.errors.length > 0),
            canImport: !data.errors || data.errors.length === 0,
            mappings: data.suggestedMappings || []
        };

        Common.renderReplace('local_edulution/import_preview', context, elements.previewContainer)
            .then(function() {
                initPreviewHandlers();
                if (context.canImport) {
                    elements.importBtn.show().prop('disabled', false);
                }
            });
    };

    /**
     * Initialize preview interaction handlers.
     */
    var initPreviewHandlers = function() {
        // Column mapping changes
        elements.previewContainer.on('change', '.column-mapping', function() {
            var column = $(this).data('column');
            var mapping = $(this).val();
            updateMapping(column, mapping);
        });

        // Skip column checkbox
        elements.previewContainer.on('change', '.skip-column', function() {
            var column = $(this).data('column');
            var skip = $(this).is(':checked');
            updateColumnSkip(column, skip);
        });

        // Show more sample data
        elements.previewContainer.on('click', '.show-more-samples', function(e) {
            e.preventDefault();
            loadMoreSamples();
        });
    };

    /**
     * Update column mapping.
     *
     * @param {string} column - Column name.
     * @param {string} mapping - Target field mapping.
     */
    var updateMapping = function(column, mapping) {
        if (!state.previewData || !state.previewData.suggestedMappings) {
            return;
        }

        var found = false;
        state.previewData.suggestedMappings.forEach(function(m) {
            if (m.column === column) {
                m.target = mapping;
                found = true;
            }
        });

        if (!found) {
            state.previewData.suggestedMappings.push({
                column: column,
                target: mapping
            });
        }
    };

    /**
     * Update column skip status.
     *
     * @param {string} column - Column name.
     * @param {boolean} skip - Whether to skip this column.
     */
    var updateColumnSkip = function(column, skip) {
        if (!state.previewData || !state.previewData.suggestedMappings) {
            return;
        }

        state.previewData.suggestedMappings.forEach(function(m) {
            if (m.column === column) {
                m.skip = skip;
            }
        });
    };

    /**
     * Load more sample data.
     */
    var loadMoreSamples = function() {
        var currentCount = elements.previewContainer.find('.sample-row').length;

        Common.ajax('local_edulution_get_import_samples', {
            uploadId: state.uploadId,
            offset: currentCount,
            limit: 10
        }).then(function(response) {
            if (response.rows && response.rows.length > 0) {
                appendSampleRows(response.rows);
                if (!response.hasMore) {
                    elements.previewContainer.find('.show-more-samples').hide();
                }
            }
        }).catch(function(error) {
            Common.notifyError('Failed to load more samples: ' + error.message);
        });
    };

    /**
     * Append sample rows to preview table.
     *
     * @param {Array} rows - Sample rows to append.
     */
    var appendSampleRows = function(rows) {
        var $tbody = elements.previewContainer.find('.sample-table tbody');
        rows.forEach(function(row) {
            var html = '<tr class="sample-row">';
            row.forEach(function(cell) {
                html += '<td>' + Common.escapeHtml(cell) + '</td>';
            });
            html += '</tr>';
            $tbody.append(html);
        });
    };

    /**
     * Start the import process.
     */
    var startImport = function() {
        if (!state.uploadId) {
            Common.notifyError('No file uploaded');
            return;
        }

        // Collect import options
        var options = getImportOptions();

        // Show import progress
        elements.previewContainer.hide();
        elements.importProgress.show();
        elements.importBtn.hide();
        elements.cancelBtn.show();

        // Create progress bar
        state.progressBar = Common.progressBar(elements.importProgress.find('.progress-wrapper'), {
            value: 0,
            label: 'Starting import...',
            type: 'info'
        });

        // Start import
        Common.ajax('local_edulution_start_import', {
            uploadId: state.uploadId,
            options: JSON.stringify(options)
        }).then(function(response) {
            if (response.success) {
                state.importId = response.importId;
                state.progressBar.update(5, 'Import started, processing...');
                startPolling();
            } else {
                handleImportError(response.message || 'Failed to start import');
            }
        }).catch(function(error) {
            handleImportError(error.message || 'Failed to start import');
        });
    };

    /**
     * Get import options from UI.
     *
     * @returns {object} Import options.
     */
    var getImportOptions = function() {
        var options = {
            mappings: state.previewData ? state.previewData.suggestedMappings : [],
            updateExisting: $('#import-update-existing').is(':checked'),
            skipErrors: $('#import-skip-errors').is(':checked'),
            sendNotifications: $('#import-send-notifications').is(':checked'),
            dryRun: $('#import-dry-run').is(':checked')
        };

        return options;
    };

    /**
     * Start polling for import progress.
     */
    var startPolling = function() {
        state.polling = true;
        state.pollAttempts = 0;
        poll();
    };

    /**
     * Stop polling for import progress.
     */
    var stopPolling = function() {
        state.polling = false;
        if (state.pollTimer) {
            clearTimeout(state.pollTimer);
            state.pollTimer = null;
        }
    };

    /**
     * Poll for import status.
     */
    var poll = function() {
        if (!state.polling || !state.importId) {
            return;
        }

        state.pollAttempts++;

        if (state.pollAttempts > config.maxPollAttempts) {
            handleImportError('Import timed out. Please check the logs.');
            return;
        }

        Common.ajax('local_edulution_get_import_status', {
            importId: state.importId
        }).then(function(response) {
            handlePollResponse(response);
        }).catch(function(error) {
            if (state.pollAttempts < 3) {
                schedulePoll();
            } else {
                handleImportError('Failed to get import status: ' + error.message);
            }
        });
    };

    /**
     * Schedule next poll.
     */
    var schedulePoll = function() {
        if (state.polling) {
            state.pollTimer = setTimeout(poll, config.pollInterval);
        }
    };

    /**
     * Handle poll response.
     *
     * @param {object} response - Poll response data.
     */
    var handlePollResponse = function(response) {
        if (!state.polling) {
            return;
        }

        switch (response.status) {
            case 'pending':
            case 'processing':
                updateProgress(response);
                schedulePoll();
                break;
            case 'completed':
                handleImportComplete(response);
                break;
            case 'failed':
                handleImportError(response.message || 'Import failed');
                break;
            case 'cancelled':
                handleImportCancelled();
                break;
            default:
                schedulePoll();
        }
    };

    /**
     * Update progress display.
     *
     * @param {object} response - Progress response data.
     */
    var updateProgress = function(response) {
        var progress = response.progress || 0;
        var message = response.message || 'Processing...';

        if (response.processed !== undefined && response.total !== undefined) {
            message = 'Imported ' + Common.formatNumber(response.processed) +
                ' of ' + Common.formatNumber(response.total) + ' records';
            if (response.errors > 0) {
                message += ' (' + response.errors + ' errors)';
            }
        }

        state.progressBar.update(progress, message);
    };

    /**
     * Handle import completion.
     *
     * @param {object} response - Completion response data.
     */
    var handleImportComplete = function(response) {
        stopPolling();
        state.progressBar.complete();
        state.progressBar.update(100, 'Import complete!');

        // Show results
        showResults(response);

        Common.notifySuccess('Import completed successfully');
    };

    /**
     * Show import results.
     *
     * @param {object} response - Import results data.
     */
    var showResults = function(response) {
        var context = {
            success: true,
            totalRecords: Common.formatNumber(response.totalRecords || 0),
            imported: Common.formatNumber(response.imported || 0),
            updated: Common.formatNumber(response.updated || 0),
            skipped: Common.formatNumber(response.skipped || 0),
            errors: Common.formatNumber(response.errorCount || 0),
            duration: Common.formatDuration(response.duration || 0),
            errorDetails: response.errorDetails || [],
            hasErrors: response.errorCount > 0,
            isDryRun: response.isDryRun
        };

        Common.renderReplace('local_edulution/import_results', context, elements.resultsContainer)
            .then(function() {
                elements.importProgress.hide();
                elements.resultsContainer.show();
                elements.cancelBtn.hide();
                elements.resetBtn.show();
            });
    };

    /**
     * Handle import error.
     *
     * @param {string} message - Error message.
     */
    var handleImportError = function(message) {
        stopPolling();

        if (state.progressBar) {
            state.progressBar.error(message);
        }

        Common.notifyError(message);

        // Show error in results
        var context = {
            success: false,
            errorMessage: message
        };

        Common.renderReplace('local_edulution/import_results', context, elements.resultsContainer)
            .then(function() {
                elements.importProgress.hide();
                elements.resultsContainer.show();
                elements.cancelBtn.hide();
                elements.resetBtn.show();
            });
    };

    /**
     * Handle import cancellation.
     */
    var handleImportCancelled = function() {
        stopPolling();

        if (state.progressBar) {
            state.progressBar.update(0, 'Import cancelled');
            state.progressBar.setType('warning');
        }

        Common.notifyInfo('Import was cancelled');

        elements.cancelBtn.hide();
        elements.resetBtn.show();
    };

    /**
     * Cancel the current import.
     */
    var cancelImport = function() {
        if (!state.importId) {
            resetImport();
            return;
        }

        Common.confirm(
            'Cancel Import',
            'Are you sure you want to cancel this import? Records already imported will not be rolled back.',
            'Yes, Cancel',
            'No, Continue'
        ).then(function() {
            var loader = Common.buttonLoading(elements.cancelBtn, 'Cancelling...');

            Common.ajax('local_edulution_cancel_import', {
                importId: state.importId
            }).then(function(response) {
                loader.reset();
                handleImportCancelled();
            }).catch(function(error) {
                loader.reset();
                Common.notifyError('Failed to cancel import: ' + error.message);
            });
        }).catch(function() {
            // User chose to continue
        });
    };

    /**
     * Reset import to initial state.
     */
    var resetImport = function() {
        stopPolling();

        // Clear state
        state.file = null;
        state.uploadId = null;
        state.importId = null;
        state.previewData = null;

        if (state.progressBar) {
            state.progressBar.destroy();
            state.progressBar = null;
        }

        // Reset UI
        clearFileSelection();
        elements.uploadProgress.hide();
        elements.previewContainer.hide().empty();
        elements.importProgress.hide();
        elements.resultsContainer.hide().empty();
        elements.uploadBtn.hide();
        elements.importBtn.hide();
        elements.cancelBtn.hide();
        elements.resetBtn.hide();
    };

    // Public API
    return {
        init: init,
        uploadFile: uploadFile,
        startImport: startImport,
        cancelImport: cancelImport,
        resetImport: resetImport
    };
});
