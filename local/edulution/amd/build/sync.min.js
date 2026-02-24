/**
 * Sync page functionality for Edulution plugin.
 *
 * Handles sync preview, sync start, real-time progress updates,
 * results display, and error handling.
 *
 * @module     local_edulution/sync
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification', 'core/str', 'core/templates', 'local_edulution/common'],
function($, Ajax, Notification, Str, Templates, Common) {

    /**
     * Module configuration.
     * @type {object}
     */
    var config = {
        pollInterval: 1500, // 1.5 seconds for more responsive updates
        maxPollAttempts: 600, // 15 minutes max
        useSSE: false, // Server-Sent Events (if supported)
        sseUrl: null
    };

    /**
     * Current sync state.
     * @type {object}
     */
    var state = {
        syncId: null,
        polling: false,
        pollTimer: null,
        pollAttempts: 0,
        progressBar: null,
        eventSource: null,
        direction: 'both', // 'to_keycloak', 'from_keycloak', 'both'
        syncStarting: false, // Prevent double-clicks
        previewStats: null // Store stats from preview for confirmation message
    };

    /**
     * DOM element references.
     * @type {object}
     */
    var elements = {
        previewBtn: null,
        startBtn: null,
        cancelBtn: null,
        resetBtn: null,
        previewContainer: null,
        progressContainer: null,
        resultsContainer: null,
        optionsForm: null,
        directionSelect: null,
        logContainer: null
    };

    /**
     * Initialize the sync module.
     *
     * @param {object} options - Configuration options.
     */
    var init = function(options) {
        config = $.extend({}, config, options);

        // Cache element references
        elements.previewBtn = $('#sync-preview-btn');
        elements.startBtn = $('#sync-start-btn');
        elements.cancelBtn = $('#sync-cancel-btn');
        elements.resetBtn = $('#sync-reset-btn');
        elements.previewContainer = $('#sync-preview-container');
        elements.progressContainer = $('#sync-progress-container');
        elements.resultsContainer = $('#sync-results-container');
        elements.optionsForm = $('#sync-options-form');
        elements.directionSelect = $('#sync-direction');
        elements.logContainer = $('#sync-log-container');

        // Initialize components
        initButtons();
        initOptions();

        // Check for SSE support
        if (config.useSSE && config.sseUrl && typeof EventSource !== 'undefined') {
            initSSE();
        }

        // Check for ongoing sync
        checkOngoingSync();
    };

    /**
     * Initialize button handlers.
     */
    var initButtons = function() {
        // Preview button
        elements.previewBtn.on('click', function(e) {
            e.preventDefault();
            loadPreview();
        });

        // Start sync button
        elements.startBtn.on('click', function(e) {
            e.preventDefault();
            confirmAndStartSync();
        });

        // Cancel button
        elements.cancelBtn.on('click', function(e) {
            e.preventDefault();
            cancelSync();
        });

        // Reset button
        elements.resetBtn.on('click', function(e) {
            e.preventDefault();
            resetSync();
        });
    };

    /**
     * Initialize sync options.
     */
    var initOptions = function() {
        // Direction change
        elements.directionSelect.on('change', function() {
            state.direction = $(this).val();
            updateUIForDirection();
        });

        // Initial direction
        state.direction = elements.directionSelect.val() || 'both';
    };

    /**
     * Update UI based on sync direction.
     */
    var updateUIForDirection = function() {
        var $keycloakOptions = $('.keycloak-sync-options');
        var $moodleOptions = $('.moodle-sync-options');

        switch (state.direction) {
            case 'to_keycloak':
                $keycloakOptions.show();
                $moodleOptions.hide();
                break;
            case 'from_keycloak':
                $keycloakOptions.hide();
                $moodleOptions.show();
                break;
            case 'both':
            default:
                $keycloakOptions.show();
                $moodleOptions.show();
        }
    };

    /**
     * Load sync preview.
     */
    var loadPreview = function() {
        var loader = Common.buttonLoading(elements.previewBtn, 'Loading preview...');
        elements.previewContainer.show();

        var options = getSyncOptions();

        Common.ajax('local_edulution_get_sync_preview', {
            direction: state.direction,
            options: JSON.stringify(options)
        }).then(function(response) {
            loader.reset();
            if (response.success) {
                renderPreview(response);
            } else {
                Common.notifyError(response.message || 'Failed to load preview');
            }
        }).catch(function(error) {
            loader.reset();
            Common.notifyError('Failed to load preview: ' + error.message);
        });
    };

    /**
     * Render sync preview.
     *
     * @param {object} data - Preview data from server.
     */
    var renderPreview = function(data) {
        // Store stats from server for confirmation message.
        state.previewStats = data.stats || null;

        // Use actual counts from stats if available, otherwise count array items.
        var usersToCreate = (data.stats && data.stats.usersToCreate) || 0;
        var usersToUpdate = (data.stats && data.stats.usersToUpdate) || 0;
        var coursesToCreate = (data.stats && data.stats.coursesToCreate) || 0;
        var enrollmentsToCreate = (data.stats && data.stats.enrollmentsToCreate) || 0;

        var context = {
            direction: state.direction,
            directionLabel: getDirectionLabel(state.direction),
            toCreate: data.toCreate || [],
            toUpdate: data.toUpdate || [],
            toDelete: data.toDelete || [],
            toSkip: data.toSkip || [],
            // Use actual server counts, not array length (array is truncated for display).
            createCount: usersToCreate + coursesToCreate + enrollmentsToCreate,
            updateCount: usersToUpdate,
            deleteCount: (data.toDelete || []).length,
            skipCount: (data.toSkip || []).length,
            // Add detailed counts for display.
            usersToCreate: usersToCreate,
            usersToUpdate: usersToUpdate,
            coursesToCreate: coursesToCreate,
            enrollmentsToCreate: enrollmentsToCreate,
            totalChanges: usersToCreate + usersToUpdate + coursesToCreate + enrollmentsToCreate,
            // Always allow sync - don't block based on preview counts.
            hasChanges: true,
            warnings: data.warnings || [],
            hasWarnings: (data.warnings || []).length > 0
        };

        Common.renderReplace('local_edulution/sync_preview', context, elements.previewContainer)
            .then(function() {
                initPreviewHandlers();
                // Always show start button - sync runs full process regardless of preview.
                elements.startBtn.show().prop('disabled', false);
            });
    };

    /**
     * Get direction label for display.
     *
     * @param {string} direction - Sync direction.
     * @returns {string} Human-readable label.
     */
    var getDirectionLabel = function(direction) {
        switch (direction) {
            case 'to_keycloak':
                return 'Moodle to Keycloak';
            case 'from_keycloak':
                return 'Keycloak to Moodle';
            case 'both':
            default:
                return 'Bidirectional';
        }
    };

    /**
     * Initialize preview interaction handlers.
     */
    var initPreviewHandlers = function() {
        // Toggle details
        elements.previewContainer.on('click', '.toggle-details', function(e) {
            e.preventDefault();
            $(this).closest('.preview-section').find('.details-list').slideToggle();
            $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
        });

        // Select all/none for items
        elements.previewContainer.on('change', '.select-all-items', function() {
            var checked = $(this).is(':checked');
            $(this).closest('.preview-section').find('.item-checkbox').prop('checked', checked);
        });

        // Individual item selection
        elements.previewContainer.on('change', '.item-checkbox', function() {
            var $section = $(this).closest('.preview-section');
            var total = $section.find('.item-checkbox').length;
            var checked = $section.find('.item-checkbox:checked').length;
            $section.find('.select-all-items').prop('checked', total === checked);
        });
    };

    /**
     * Get selected items from preview.
     *
     * @returns {object} Selected items by action type.
     */
    var getSelectedItems = function() {
        var selected = {
            create: [],
            update: [],
            delete: []
        };

        elements.previewContainer.find('.item-checkbox:checked').each(function() {
            var action = $(this).data('action');
            var id = $(this).data('id');
            if (selected[action]) {
                selected[action].push(id);
            }
        });

        return selected;
    };

    /**
     * Get sync options from form.
     *
     * @returns {object} Sync options.
     */
    var getSyncOptions = function() {
        var options = {
            syncUsers: $('#sync-users').is(':checked'),
            syncGroups: $('#sync-groups').is(':checked'),
            syncRoles: $('#sync-roles').is(':checked'),
            createMissing: $('#sync-create-missing').is(':checked'),
            updateExisting: $('#sync-update-existing').is(':checked'),
            deleteRemoved: $('#sync-delete-removed').is(':checked'),
            dryRun: $('#sync-dry-run').is(':checked')
        };

        return options;
    };

    /**
     * Confirm and start sync.
     */
    var confirmAndStartSync = function() {
        var selectedItems = getSelectedItems();

        // Use actual stats from server preview, not just visible checkbox count.
        var stats = state.previewStats || {};
        var usersToCreate = stats.usersToCreate || 0;
        var usersToUpdate = stats.usersToUpdate || 0;
        var coursesToCreate = stats.coursesToCreate || 0;
        var enrollmentsToCreate = stats.enrollmentsToCreate || 0;
        var totalItems = usersToCreate + usersToUpdate + coursesToCreate + enrollmentsToCreate;

        var message = 'This will synchronize ALL items from Keycloak:\n\n';
        if (usersToCreate > 0) {
            message += '• ' + Common.formatNumber(usersToCreate) + ' users to create\n';
        }
        if (usersToUpdate > 0) {
            message += '• ' + Common.formatNumber(usersToUpdate) + ' users to update\n';
        }
        if (coursesToCreate > 0) {
            message += '• ' + Common.formatNumber(coursesToCreate) + ' courses to create\n';
        }
        if (enrollmentsToCreate > 0) {
            message += '• ' + Common.formatNumber(enrollmentsToCreate) + ' enrollments to create\n';
        }
        message += '\nTotal: ' + Common.formatNumber(totalItems) + ' items';

        if (selectedItems.delete.length > 0) {
            message += '\n\nWarning: ' + selectedItems.delete.length + ' items will be deleted.';
        }

        Common.confirm(
            'Start Synchronization',
            message,
            'Start Sync',
            'Cancel'
        ).then(function() {
            startSync(selectedItems);
        }).catch(function() {
            // User cancelled
        });
    };

    /**
     * Start the synchronization.
     *
     * @param {object} selectedItems - Items to sync.
     */
    var startSync = function(selectedItems) {
        // Prevent double-clicks - disable button immediately.
        if (state.syncStarting) {
            return;
        }
        state.syncStarting = true;

        var options = getSyncOptions();

        // Show progress UI
        elements.previewContainer.hide();
        elements.progressContainer.show();
        elements.startBtn.hide().prop('disabled', true);
        elements.previewBtn.hide();
        elements.cancelBtn.show();

        // Create progress bar
        state.progressBar = Common.progressBar(elements.progressContainer.find('.progress-wrapper'), {
            value: 0,
            label: 'Starting synchronization...',
            type: 'info'
        });

        // Initialize log container
        elements.logContainer.show().find('.log-entries').empty();

        // Start sync
        Common.ajax('local_edulution_start_sync', {
            direction: state.direction,
            selectedItems: JSON.stringify(selectedItems),
            options: JSON.stringify(options)
        }).then(function(response) {
            if (response.success) {
                state.syncId = response.syncId;
                state.progressBar.update(5, 'Sync started...');
                addLogEntry('info', 'Synchronization started');

                // Start progress updates
                if (config.useSSE && state.eventSource) {
                    startSSEUpdates();
                } else {
                    startPolling();
                }
            } else {
                handleSyncError(response.message || 'Failed to start sync');
            }
        }).catch(function(error) {
            handleSyncError(error.message || 'Failed to start sync');
        });
    };

    /**
     * Start polling for sync progress.
     */
    var startPolling = function() {
        state.polling = true;
        state.pollAttempts = 0;
        poll();
    };

    /**
     * Stop polling.
     */
    var stopPolling = function() {
        state.polling = false;
        if (state.pollTimer) {
            clearTimeout(state.pollTimer);
            state.pollTimer = null;
        }
    };

    /**
     * Poll for sync status.
     */
    var poll = function() {
        if (!state.polling || !state.syncId) {
            return;
        }

        state.pollAttempts++;

        if (state.pollAttempts > config.maxPollAttempts) {
            handleSyncError('Sync timed out. Please check the logs.');
            return;
        }

        Common.ajax('local_edulution_get_sync_status', {
            syncId: state.syncId
        }).then(function(response) {
            handlePollResponse(response);
        }).catch(function(error) {
            if (state.pollAttempts < 3) {
                schedulePoll();
            } else {
                handleSyncError('Failed to get sync status: ' + error.message);
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

        // Update progress
        updateProgress(response);

        // Add log entries
        if (response.newLogEntries) {
            response.newLogEntries.forEach(function(entry) {
                addLogEntry(entry.type, entry.message);
            });
        }

        // Handle status
        switch (response.status) {
            case 'pending':
            case 'processing':
                schedulePoll();
                break;
            case 'completed':
                handleSyncComplete(response);
                break;
            case 'failed':
                handleSyncError(response.message || 'Sync failed');
                break;
            case 'cancelled':
                handleSyncCancelled();
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
            message = 'Processed ' + Common.formatNumber(response.processed) +
                ' of ' + Common.formatNumber(response.total);
            if (response.errors > 0) {
                message += ' (' + response.errors + ' errors)';
            }
        }

        state.progressBar.update(progress, message);

        // Update statistics
        if (response.stats) {
            updateStatistics(response.stats);
        }
    };

    /**
     * Update statistics display.
     *
     * @param {object} stats - Statistics data.
     */
    var updateStatistics = function(stats) {
        var $stats = elements.progressContainer.find('.sync-statistics');
        if (!$stats.length) {
            $stats = $('<div class="sync-statistics row mt-3"></div>');
            elements.progressContainer.find('.progress-wrapper').after($stats);
        }

        var html = '';
        if (stats.created !== undefined) {
            html += '<div class="col-3 text-center"><span class="badge badge-success">' +
                    stats.created + '</span><br><small>Created</small></div>';
        }
        if (stats.updated !== undefined) {
            html += '<div class="col-3 text-center"><span class="badge badge-info">' +
                    stats.updated + '</span><br><small>Updated</small></div>';
        }
        if (stats.deleted !== undefined) {
            html += '<div class="col-3 text-center"><span class="badge badge-warning">' +
                    stats.deleted + '</span><br><small>Deleted</small></div>';
        }
        if (stats.errors !== undefined) {
            html += '<div class="col-3 text-center"><span class="badge badge-danger">' +
                    stats.errors + '</span><br><small>Errors</small></div>';
        }

        $stats.html(html);
    };

    /**
     * Add a log entry.
     *
     * @param {string} type - Entry type (info, success, warning, error).
     * @param {string} message - Log message.
     */
    var addLogEntry = function(type, message) {
        var $log = elements.logContainer.find('.log-entries');
        var timestamp = new Date().toLocaleTimeString();
        var iconClass = {
            info: 'fa-info-circle text-info',
            success: 'fa-check-circle text-success',
            warning: 'fa-exclamation-triangle text-warning',
            error: 'fa-times-circle text-danger'
        };

        var html = '<div class="log-entry">' +
            '<small class="text-muted">[' + timestamp + ']</small> ' +
            '<i class="fa ' + (iconClass[type] || iconClass.info) + '"></i> ' +
            Common.escapeHtml(message) +
            '</div>';

        $log.append(html);

        // Auto-scroll to bottom
        $log.scrollTop($log[0].scrollHeight);
    };

    /**
     * Initialize Server-Sent Events.
     */
    var initSSE = function() {
        // SSE will be connected when sync starts
    };

    /**
     * Start SSE updates.
     */
    var startSSEUpdates = function() {
        if (!config.sseUrl || !state.syncId) {
            // Fall back to polling
            startPolling();
            return;
        }

        try {
            state.eventSource = new EventSource(
                config.sseUrl + '?syncId=' + state.syncId + '&sesskey=' + Common.getSesskey()
            );

            state.eventSource.onmessage = function(event) {
                var data = JSON.parse(event.data);
                handleSSEMessage(data);
            };

            state.eventSource.onerror = function() {
                // Fall back to polling on error
                closeSSE();
                startPolling();
            };

        } catch (e) {
            // Fall back to polling
            startPolling();
        }
    };

    /**
     * Handle SSE message.
     *
     * @param {object} data - Message data.
     */
    var handleSSEMessage = function(data) {
        switch (data.type) {
            case 'progress':
                updateProgress(data);
                break;
            case 'log':
                addLogEntry(data.level, data.message);
                break;
            case 'complete':
                closeSSE();
                handleSyncComplete(data);
                break;
            case 'error':
                closeSSE();
                handleSyncError(data.message);
                break;
            case 'cancelled':
                closeSSE();
                handleSyncCancelled();
                break;
        }
    };

    /**
     * Close SSE connection.
     */
    var closeSSE = function() {
        if (state.eventSource) {
            state.eventSource.close();
            state.eventSource = null;
        }
    };

    /**
     * Handle sync completion.
     *
     * @param {object} response - Completion response data.
     */
    var handleSyncComplete = function(response) {
        stopPolling();
        closeSSE();

        state.progressBar.complete();
        state.progressBar.update(100, 'Synchronization complete!');
        addLogEntry('success', 'Synchronization completed successfully');

        // Show results
        showResults(response);

        Common.notifySuccess('Synchronization completed successfully');
    };

    /**
     * Show sync results.
     *
     * @param {object} response - Sync results data.
     */
    var showResults = function(response) {
        var context = {
            success: true,
            direction: state.direction,
            directionLabel: getDirectionLabel(state.direction),
            created: Common.formatNumber(response.created || 0),
            updated: Common.formatNumber(response.updated || 0),
            deleted: Common.formatNumber(response.deleted || 0),
            skipped: Common.formatNumber(response.skipped || 0),
            errors: Common.formatNumber(response.errorCount || 0),
            duration: Common.formatDuration(response.duration || 0),
            errorDetails: response.errorDetails || [],
            hasErrors: (response.errorCount || 0) > 0,
            isDryRun: response.isDryRun
        };

        Common.renderReplace('local_edulution/sync_results', context, elements.resultsContainer)
            .then(function() {
                elements.progressContainer.hide();
                elements.resultsContainer.show();
                elements.cancelBtn.hide();
                elements.resetBtn.show();
            });
    };

    /**
     * Handle sync error.
     *
     * @param {string} message - Error message.
     */
    var handleSyncError = function(message) {
        stopPolling();
        closeSSE();

        if (state.progressBar) {
            state.progressBar.error(message);
        }

        addLogEntry('error', message);
        Common.notifyError(message);

        // Show error in results
        var context = {
            success: false,
            errorMessage: message
        };

        Common.renderReplace('local_edulution/sync_results', context, elements.resultsContainer)
            .then(function() {
                elements.progressContainer.hide();
                elements.resultsContainer.show();
                elements.cancelBtn.hide();
                elements.resetBtn.show();
            });
    };

    /**
     * Handle sync cancellation.
     */
    var handleSyncCancelled = function() {
        stopPolling();
        closeSSE();

        if (state.progressBar) {
            state.progressBar.update(0, 'Synchronization cancelled');
            state.progressBar.setType('warning');
        }

        addLogEntry('warning', 'Synchronization cancelled by user');
        Common.notifyInfo('Synchronization was cancelled');

        elements.cancelBtn.hide();
        elements.resetBtn.show();
    };

    /**
     * Cancel the current sync.
     */
    var cancelSync = function() {
        if (!state.syncId) {
            resetSync();
            return;
        }

        Common.confirm(
            'Cancel Synchronization',
            'Are you sure you want to cancel the synchronization? Changes already made will not be rolled back.',
            'Yes, Cancel',
            'No, Continue'
        ).then(function() {
            var loader = Common.buttonLoading(elements.cancelBtn, 'Cancelling...');

            Common.ajax('local_edulution_cancel_sync', {
                syncId: state.syncId
            }).then(function(response) {
                loader.reset();
                handleSyncCancelled();
            }).catch(function(error) {
                loader.reset();
                Common.notifyError('Failed to cancel sync: ' + error.message);
            });
        }).catch(function() {
            // User chose to continue
        });
    };

    /**
     * Reset sync to initial state.
     */
    var resetSync = function() {
        stopPolling();
        closeSSE();

        // Clear state
        state.syncId = null;
        state.syncStarting = false;
        state.previewStats = null;

        if (state.progressBar) {
            state.progressBar.destroy();
            state.progressBar = null;
        }

        // Reset UI
        elements.previewContainer.hide().empty();
        elements.progressContainer.hide();
        elements.resultsContainer.hide().empty();
        elements.logContainer.hide().find('.log-entries').empty();
        elements.previewBtn.show();
        elements.startBtn.hide();
        elements.cancelBtn.hide();
        elements.resetBtn.hide();
    };

    /**
     * Check for ongoing sync.
     */
    var checkOngoingSync = function() {
        Common.ajax('local_edulution_get_ongoing_sync', {})
            .then(function(response) {
                if (response.syncId && response.status === 'processing') {
                    state.syncId = response.syncId;
                    state.direction = response.direction || 'both';

                    // Show progress UI
                    elements.previewContainer.hide();
                    elements.progressContainer.show();
                    elements.previewBtn.hide();
                    elements.startBtn.hide();
                    elements.cancelBtn.show();
                    elements.logContainer.show();

                    // Create progress bar
                    state.progressBar = Common.progressBar(
                        elements.progressContainer.find('.progress-wrapper'),
                        {
                            value: response.progress || 0,
                            label: 'Resuming sync...',
                            type: 'info'
                        }
                    );

                    // Start progress updates
                    if (config.useSSE && typeof EventSource !== 'undefined') {
                        startSSEUpdates();
                    } else {
                        startPolling();
                    }
                }
            })
            .catch(function() {
                // No ongoing sync, that's fine
            });
    };

    // Public API
    return {
        init: init,
        loadPreview: loadPreview,
        startSync: startSync,
        cancelSync: cancelSync,
        resetSync: resetSync
    };
});
