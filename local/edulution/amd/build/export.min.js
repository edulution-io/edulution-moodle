/**
 * Export page functionality for edulution plugin.
 *
 * Handles form validation, AJAX export start, progress polling,
 * download triggers, and export cancellation.
 *
 * @module     local_edulution/export
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
        pollInterval: 2000, // 2 seconds
        maxPollAttempts: 300, // 10 minutes max (300 * 2 seconds)
        exportTypes: ['users', 'courses', 'enrolments', 'grades', 'all']
    };

    /**
     * Current export state.
     * @type {object}
     */
    var state = {
        exportId: null,
        polling: false,
        pollTimer: null,
        pollAttempts: 0,
        progressBar: null
    };

    /**
     * DOM element references.
     * @type {object}
     */
    var elements = {
        form: null,
        submitBtn: null,
        cancelBtn: null,
        progressContainer: null,
        resultsContainer: null,
        typeSelect: null,
        formatSelect: null,
        dateRange: null
    };

    /**
     * Initialize the export module.
     *
     * @param {object} options - Configuration options.
     */
    var init = function(options) {
        config = $.extend({}, config, options);

        // Cache element references
        elements.form = $('#edulution-export-form');
        elements.submitBtn = $('#export-submit-btn');
        elements.cancelBtn = $('#export-cancel-btn');
        elements.progressContainer = $('#export-progress-container');
        elements.resultsContainer = $('#export-results-container');
        elements.typeSelect = $('#export-type');
        elements.formatSelect = $('#export-format');
        elements.dateRange = $('#export-date-range');

        // Initialize components
        initForm();
        initTypeSelect();
        initDateRange();

        // Check for ongoing export
        checkOngoingExport();
    };

    /**
     * Initialize form handling.
     */
    var initForm = function() {
        // Form submission
        elements.form.on('submit', function(e) {
            e.preventDefault();
            if (validateForm()) {
                startExport();
            }
        });

        // Cancel button
        elements.cancelBtn.on('click', function(e) {
            e.preventDefault();
            cancelExport();
        });

        // Real-time validation
        elements.form.find('input, select').on('change blur', function() {
            validateField($(this));
        });
    };

    /**
     * Initialize export type select.
     */
    var initTypeSelect = function() {
        elements.typeSelect.on('change', function() {
            var type = $(this).val();
            updateFormForType(type);
        });

        // Trigger initial update
        elements.typeSelect.trigger('change');
    };

    /**
     * Initialize date range picker.
     */
    var initDateRange = function() {
        var $startDate = $('#export-start-date');
        var $endDate = $('#export-end-date');

        // Set default dates
        var today = new Date();
        var monthAgo = new Date();
        monthAgo.setMonth(monthAgo.getMonth() - 1);

        if (!$startDate.val()) {
            $startDate.val(formatDateForInput(monthAgo));
        }
        if (!$endDate.val()) {
            $endDate.val(formatDateForInput(today));
        }

        // Validate date range
        $startDate.add($endDate).on('change', function() {
            validateDateRange();
        });
    };

    /**
     * Format a date for input field.
     *
     * @param {Date} date - Date to format.
     * @returns {string} Formatted date (YYYY-MM-DD).
     */
    var formatDateForInput = function(date) {
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    };

    /**
     * Update form fields based on export type.
     *
     * @param {string} type - Selected export type.
     */
    var updateFormForType = function(type) {
        var $courseField = $('#export-course-field');
        var $userField = $('#export-user-field');
        var $dateField = $('#export-date-field');

        // Show/hide fields based on type
        switch (type) {
            case 'users':
                $courseField.hide();
                $userField.show();
                $dateField.show();
                break;
            case 'courses':
                $courseField.show();
                $userField.hide();
                $dateField.hide();
                break;
            case 'enrolments':
                $courseField.show();
                $userField.show();
                $dateField.show();
                break;
            case 'grades':
                $courseField.show();
                $userField.show();
                $dateField.show();
                break;
            case 'all':
                $courseField.hide();
                $userField.hide();
                $dateField.show();
                break;
            default:
                $courseField.show();
                $userField.show();
                $dateField.show();
        }
    };

    /**
     * Validate the entire form.
     *
     * @returns {boolean} Whether form is valid.
     */
    var validateForm = function() {
        var isValid = true;

        // Validate export type
        if (!elements.typeSelect.val()) {
            showFieldError(elements.typeSelect, 'Please select an export type');
            isValid = false;
        }

        // Validate format
        if (!elements.formatSelect.val()) {
            showFieldError(elements.formatSelect, 'Please select an export format');
            isValid = false;
        }

        // Validate date range
        if (!validateDateRange()) {
            isValid = false;
        }

        return isValid;
    };

    /**
     * Validate a single field.
     *
     * @param {jQuery} $field - Field to validate.
     * @returns {boolean} Whether field is valid.
     */
    var validateField = function($field) {
        var isValid = true;
        var value = $field.val();
        var required = $field.prop('required');

        clearFieldError($field);

        if (required && !value) {
            showFieldError($field, 'This field is required');
            isValid = false;
        }

        return isValid;
    };

    /**
     * Validate date range.
     *
     * @returns {boolean} Whether date range is valid.
     */
    var validateDateRange = function() {
        var $startDate = $('#export-start-date');
        var $endDate = $('#export-end-date');
        var start = new Date($startDate.val());
        var end = new Date($endDate.val());

        clearFieldError($startDate);
        clearFieldError($endDate);

        if ($startDate.val() && $endDate.val()) {
            if (start > end) {
                showFieldError($endDate, 'End date must be after start date');
                return false;
            }

            // Check if range is too large (e.g., more than 1 year)
            var diffTime = Math.abs(end - start);
            var diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            if (diffDays > 365) {
                showFieldError($endDate, 'Date range cannot exceed 1 year');
                return false;
            }
        }

        return true;
    };

    /**
     * Show a field error message.
     *
     * @param {jQuery} $field - Field to show error for.
     * @param {string} message - Error message.
     */
    var showFieldError = function($field, message) {
        $field.addClass('is-invalid');
        var $feedback = $field.siblings('.invalid-feedback');
        if (!$feedback.length) {
            $feedback = $('<div class="invalid-feedback"></div>');
            $field.after($feedback);
        }
        $feedback.text(message);
    };

    /**
     * Clear a field error.
     *
     * @param {jQuery} $field - Field to clear error for.
     */
    var clearFieldError = function($field) {
        $field.removeClass('is-invalid');
        $field.siblings('.invalid-feedback').remove();
    };

    /**
     * Start the export process.
     */
    var startExport = function() {
        var formData = getFormData();

        // Disable form and show progress
        elements.form.find('input, select, button').prop('disabled', true);
        elements.submitBtn.hide();
        elements.cancelBtn.show();
        elements.progressContainer.show();
        elements.resultsContainer.hide();

        // Create progress bar
        state.progressBar = Common.progressBar(elements.progressContainer.find('.progress-wrapper'), {
            value: 0,
            label: 'Starting export...',
            type: 'info'
        });

        // Start export via AJAX
        Common.ajax('local_edulution_start_export', formData)
            .then(function(response) {
                if (response.success) {
                    state.exportId = response.exportId;
                    state.progressBar.update(5, 'Export started, processing...');
                    startPolling();
                } else {
                    handleExportError(response.message || 'Failed to start export');
                }
            })
            .catch(function(error) {
                handleExportError(error.message || 'Failed to start export');
            });
    };

    /**
     * Get form data as object.
     *
     * @returns {object} Form data.
     */
    var getFormData = function() {
        var data = {
            type: elements.typeSelect.val(),
            format: elements.formatSelect.val(),
            startDate: $('#export-start-date').val(),
            endDate: $('#export-end-date').val()
        };

        // Add optional fields
        var courseId = $('#export-course-id').val();
        if (courseId) {
            data.courseId = parseInt(courseId, 10);
        }

        var userId = $('#export-user-id').val();
        if (userId) {
            data.userId = parseInt(userId, 10);
        }

        // Include deleted option
        var includeDeleted = $('#export-include-deleted').is(':checked');
        data.includeDeleted = includeDeleted;

        return data;
    };

    /**
     * Start polling for export progress.
     */
    var startPolling = function() {
        state.polling = true;
        state.pollAttempts = 0;
        poll();
    };

    /**
     * Stop polling for export progress.
     */
    var stopPolling = function() {
        state.polling = false;
        if (state.pollTimer) {
            clearTimeout(state.pollTimer);
            state.pollTimer = null;
        }
    };

    /**
     * Poll for export status.
     */
    var poll = function() {
        if (!state.polling || !state.exportId) {
            return;
        }

        state.pollAttempts++;

        if (state.pollAttempts > config.maxPollAttempts) {
            handleExportError('Export timed out. Please try again or contact support.');
            return;
        }

        Common.ajax('local_edulution_get_export_status', {
            exportId: state.exportId
        }).then(function(response) {
            handlePollResponse(response);
        }).catch(function(error) {
            // Continue polling on error (might be temporary)
            if (state.pollAttempts < 3) {
                schedulePoll();
            } else {
                handleExportError('Failed to get export status: ' + error.message);
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
                handleExportComplete(response);
                break;
            case 'failed':
                handleExportError(response.message || 'Export failed');
                break;
            case 'cancelled':
                handleExportCancelled();
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
            message = 'Processing ' + Common.formatNumber(response.processed) +
                ' of ' + Common.formatNumber(response.total) + ' records...';
        }

        state.progressBar.update(progress, message);

        // Update detailed progress if available
        if (response.details) {
            updateProgressDetails(response.details);
        }
    };

    /**
     * Update detailed progress information.
     *
     * @param {object} details - Progress details.
     */
    var updateProgressDetails = function(details) {
        var $details = elements.progressContainer.find('.progress-details');
        if (!$details.length) {
            $details = $('<div class="progress-details small text-muted mt-2"></div>');
            elements.progressContainer.find('.progress-wrapper').after($details);
        }

        var html = [];
        if (details.currentStep) {
            html.push('<span>Step: ' + Common.escapeHtml(details.currentStep) + '</span>');
        }
        if (details.elapsedTime) {
            html.push('<span>Elapsed: ' + Common.formatDuration(details.elapsedTime) + '</span>');
        }
        if (details.estimatedRemaining) {
            html.push('<span>Remaining: ~' + Common.formatDuration(details.estimatedRemaining) + '</span>');
        }

        $details.html(html.join(' | '));
    };

    /**
     * Handle export completion.
     *
     * @param {object} response - Completion response data.
     */
    var handleExportComplete = function(response) {
        stopPolling();
        state.progressBar.complete();
        state.progressBar.update(100, 'Export complete!');

        // Show results
        showResults(response);

        // Trigger download
        if (response.downloadUrl) {
            triggerDownload(response.downloadUrl, response.filename);
        }

        // Reset form state
        resetFormState();

        Common.notifySuccess('Export completed successfully');
    };

    /**
     * Show export results.
     *
     * @param {object} response - Export results data.
     */
    var showResults = function(response) {
        var context = {
            success: true,
            recordCount: Common.formatNumber(response.recordCount || 0),
            fileSize: Common.formatFileSize(response.fileSize || 0),
            duration: Common.formatDuration(response.duration || 0),
            downloadUrl: response.downloadUrl,
            filename: response.filename,
            exportType: elements.typeSelect.find('option:selected').text(),
            exportFormat: elements.formatSelect.find('option:selected').text().toUpperCase()
        };

        Common.renderReplace('local_edulution/export_results', context, elements.resultsContainer)
            .then(function() {
                elements.resultsContainer.show();
                initResultsActions();
            });
    };

    /**
     * Initialize results action buttons.
     */
    var initResultsActions = function() {
        // Download again button
        elements.resultsContainer.on('click', '.download-again', function(e) {
            e.preventDefault();
            var url = $(this).data('url');
            var filename = $(this).data('filename');
            triggerDownload(url, filename);
        });

        // New export button
        elements.resultsContainer.on('click', '.new-export', function(e) {
            e.preventDefault();
            resetExport();
        });
    };

    /**
     * Trigger file download.
     *
     * @param {string} url - Download URL.
     * @param {string} filename - Suggested filename.
     */
    var triggerDownload = function(url, filename) {
        var link = document.createElement('a');
        link.href = url;
        link.download = filename || 'export';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    /**
     * Handle export error.
     *
     * @param {string} message - Error message.
     */
    var handleExportError = function(message) {
        stopPolling();

        if (state.progressBar) {
            state.progressBar.error(message);
        }

        Common.notifyError(message);
        resetFormState();

        // Show error in results container
        var context = {
            success: false,
            errorMessage: message
        };

        Common.renderReplace('local_edulution/export_results', context, elements.resultsContainer)
            .then(function() {
                elements.resultsContainer.show();
            });
    };

    /**
     * Handle export cancellation.
     */
    var handleExportCancelled = function() {
        stopPolling();

        if (state.progressBar) {
            state.progressBar.update(0, 'Export cancelled');
            state.progressBar.setType('warning');
        }

        Common.notifyInfo('Export was cancelled');
        resetFormState();
    };

    /**
     * Cancel the current export.
     */
    var cancelExport = function() {
        if (!state.exportId) {
            resetExport();
            return;
        }

        Common.confirm(
            'Cancel Export',
            'Are you sure you want to cancel this export?',
            'Yes, Cancel',
            'No, Continue'
        ).then(function() {
            var loader = Common.buttonLoading(elements.cancelBtn, 'Cancelling...');

            Common.ajax('local_edulution_cancel_export', {
                exportId: state.exportId
            }).then(function(response) {
                loader.reset();
                handleExportCancelled();
            }).catch(function(error) {
                loader.reset();
                Common.notifyError('Failed to cancel export: ' + error.message);
            });
        }).catch(function() {
            // User chose to continue
        });
    };

    /**
     * Reset form state after export.
     */
    var resetFormState = function() {
        elements.form.find('input, select, button').prop('disabled', false);
        elements.submitBtn.show();
        elements.cancelBtn.hide();
    };

    /**
     * Reset export to initial state.
     */
    var resetExport = function() {
        stopPolling();
        state.exportId = null;

        if (state.progressBar) {
            state.progressBar.destroy();
            state.progressBar = null;
        }

        elements.progressContainer.hide();
        elements.resultsContainer.hide();
        resetFormState();
    };

    /**
     * Check for ongoing export.
     */
    var checkOngoingExport = function() {
        Common.ajax('local_edulution_get_ongoing_export', {})
            .then(function(response) {
                if (response.exportId && response.status === 'processing') {
                    state.exportId = response.exportId;

                    // Show progress container
                    elements.form.find('input, select, button').prop('disabled', true);
                    elements.submitBtn.hide();
                    elements.cancelBtn.show();
                    elements.progressContainer.show();

                    // Create progress bar and start polling
                    state.progressBar = Common.progressBar(
                        elements.progressContainer.find('.progress-wrapper'),
                        {
                            value: response.progress || 0,
                            label: 'Resuming export...',
                            type: 'info'
                        }
                    );

                    startPolling();
                }
            })
            .catch(function() {
                // No ongoing export, that's fine
            });
    };

    // Public API
    return {
        init: init,
        startExport: startExport,
        cancelExport: cancelExport,
        resetExport: resetExport
    };
});
