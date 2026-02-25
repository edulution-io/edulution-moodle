/**
 * Dashboard functionality for edulution plugin.
 *
 * Handles auto-refresh of status cards, quick action handlers,
 * and activity log updates.
 *
 * @module     local_edulution/dashboard
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
        refreshInterval: 30000, // 30 seconds
        activityLogLimit: 20,
        autoRefresh: true
    };

    /**
     * Refresh timer reference.
     * @type {number}
     */
    var refreshTimer = null;

    /**
     * Container elements.
     * @type {object}
     */
    var elements = {
        statusCards: null,
        activityLog: null,
        quickActions: null
    };

    /**
     * Initialize the dashboard module.
     *
     * @param {object} options - Configuration options.
     * @param {number} options.refreshInterval - Auto-refresh interval in ms.
     * @param {boolean} options.autoRefresh - Enable auto-refresh.
     * @param {number} options.activityLogLimit - Max activity log entries.
     */
    var init = function(options) {
        config = $.extend({}, config, options);

        // Cache element references
        elements.statusCards = $('#edulution-status-cards');
        elements.activityLog = $('#edulution-activity-log');
        elements.quickActions = $('#edulution-quick-actions');

        // Initialize components
        initStatusCards();
        initQuickActions();
        initActivityLog();

        // Start auto-refresh if enabled
        if (config.autoRefresh) {
            startAutoRefresh();
        }

        // Handle visibility change to pause/resume refresh
        $(document).on('visibilitychange', handleVisibilityChange);
    };

    /**
     * Initialize status cards.
     */
    var initStatusCards = function() {
        // Add refresh button handler
        elements.statusCards.on('click', '.refresh-status', function(e) {
            e.preventDefault();
            refreshStatusCards();
        });

        // Initial load
        refreshStatusCards();
    };

    /**
     * Refresh all status cards via AJAX.
     *
     * @returns {Promise} Promise resolving when refresh is complete.
     */
    var refreshStatusCards = function() {
        var $cards = elements.statusCards;
        var loader = Common.showLoading($cards.find('.card-body').first());

        return Common.ajax('local_edulution_get_dashboard_status', {})
            .then(function(response) {
                loader.hide();
                return updateStatusCards(response);
            })
            .catch(function(error) {
                loader.hide();
                Common.notifyError('Failed to refresh status: ' + error.message);
            });
    };

    /**
     * Update status cards with new data.
     *
     * @param {object} data - Status data from server.
     * @returns {Promise} Promise resolving when update is complete.
     */
    var updateStatusCards = function(data) {
        // Update sync status card
        if (data.syncStatus) {
            updateSyncStatusCard(data.syncStatus);
        }

        // Update statistics card
        if (data.statistics) {
            updateStatisticsCard(data.statistics);
        }

        // Update Keycloak status card
        if (data.keycloakStatus) {
            updateKeycloakStatusCard(data.keycloakStatus);
        }

        // Update last activity card
        if (data.lastActivity) {
            updateLastActivityCard(data.lastActivity);
        }

        // Trigger event for other modules
        $(document).trigger('edulution:statusUpdated', [data]);

        return Promise.resolve();
    };

    /**
     * Update the sync status card.
     *
     * @param {object} status - Sync status data.
     */
    var updateSyncStatusCard = function(status) {
        var $card = elements.statusCards.find('[data-card="sync-status"]');
        if (!$card.length) {
            return;
        }

        var statusClass = 'text-' + (status.healthy ? 'success' : 'danger');
        var statusIcon = status.healthy ? 'fa-check-circle' : 'fa-exclamation-circle';

        $card.find('.status-indicator')
            .removeClass('text-success text-danger text-warning')
            .addClass(statusClass);

        $card.find('.status-icon')
            .removeClass('fa-check-circle fa-exclamation-circle fa-spinner')
            .addClass(statusIcon);

        $card.find('.status-text').text(status.message || (status.healthy ? 'Healthy' : 'Issues detected'));
        $card.find('.last-sync').text(status.lastSync ? Common.formatDate(status.lastSync, true) : 'Never');

        if (status.pendingCount !== undefined) {
            $card.find('.pending-count').text(Common.formatNumber(status.pendingCount));
        }
    };

    /**
     * Update the statistics card.
     *
     * @param {object} stats - Statistics data.
     */
    var updateStatisticsCard = function(stats) {
        var $card = elements.statusCards.find('[data-card="statistics"]');
        if (!$card.length) {
            return;
        }

        // Update each stat
        $.each(stats, function(key, value) {
            var $stat = $card.find('[data-stat="' + key + '"]');
            if ($stat.length) {
                $stat.find('.stat-value').text(Common.formatNumber(value));
            }
        });
    };

    /**
     * Update the Keycloak status card.
     *
     * @param {object} status - Keycloak status data.
     */
    var updateKeycloakStatusCard = function(status) {
        var $card = elements.statusCards.find('[data-card="keycloak-status"]');
        if (!$card.length) {
            return;
        }

        var statusClass = status.connected ? 'success' : 'danger';
        var statusText = status.connected ? 'Connected' : 'Disconnected';

        $card.find('.connection-status')
            .removeClass('badge-success badge-danger badge-warning')
            .addClass('badge-' + statusClass)
            .text(statusText);

        if (status.serverUrl) {
            $card.find('.server-url').text(status.serverUrl);
        }

        if (status.realm) {
            $card.find('.realm-name').text(status.realm);
        }

        if (status.lastCheck) {
            $card.find('.last-check').text(Common.formatDate(status.lastCheck, true));
        }
    };

    /**
     * Update the last activity card.
     *
     * @param {object} activity - Last activity data.
     */
    var updateLastActivityCard = function(activity) {
        var $card = elements.statusCards.find('[data-card="last-activity"]');
        if (!$card.length) {
            return;
        }

        $card.find('.activity-type').text(activity.type || 'None');
        $card.find('.activity-time').text(activity.time ? Common.formatDate(activity.time, true) : 'N/A');
        $card.find('.activity-user').text(activity.user || 'System');
        $card.find('.activity-details').text(activity.details || '');
    };

    /**
     * Initialize quick action buttons.
     */
    var initQuickActions = function() {
        // Sync now button
        elements.quickActions.on('click', '[data-action="sync-now"]', function(e) {
            e.preventDefault();
            handleSyncNow($(this));
        });

        // Export data button
        elements.quickActions.on('click', '[data-action="export-data"]', function(e) {
            e.preventDefault();
            handleExportData($(this));
        });

        // Import data button
        elements.quickActions.on('click', '[data-action="import-data"]', function(e) {
            e.preventDefault();
            handleImportData($(this));
        });

        // Test connection button
        elements.quickActions.on('click', '[data-action="test-connection"]', function(e) {
            e.preventDefault();
            handleTestConnection($(this));
        });

        // View logs button
        elements.quickActions.on('click', '[data-action="view-logs"]', function(e) {
            e.preventDefault();
            handleViewLogs($(this));
        });

        // Clear cache button
        elements.quickActions.on('click', '[data-action="clear-cache"]', function(e) {
            e.preventDefault();
            handleClearCache($(this));
        });
    };

    /**
     * Handle sync now action.
     *
     * @param {jQuery} $button - The clicked button.
     */
    var handleSyncNow = function($button) {
        Common.confirm(
            'Start Sync',
            'Are you sure you want to start a synchronization now?',
            'Sync Now',
            'Cancel'
        ).then(function() {
            var loader = Common.buttonLoading($button, 'Syncing...');

            return Common.ajax('local_edulution_start_sync', {
                fullSync: false
            }).then(function(response) {
                loader.success('Sync Started');
                Common.notifySuccess('Synchronization started successfully');
                refreshStatusCards();
                refreshActivityLog();

                // Reset button after delay
                setTimeout(function() {
                    loader.reset();
                }, 2000);
            }).catch(function(error) {
                loader.error('Failed');
                Common.notifyError('Failed to start sync: ' + error.message);
                setTimeout(function() {
                    loader.reset();
                }, 2000);
            });
        }).catch(function() {
            // User cancelled
        });
    };

    /**
     * Handle export data action.
     *
     * @param {jQuery} $button - The clicked button.
     */
    var handleExportData = function($button) {
        // Navigate to export page
        window.location.href = Common.getPluginUrl() + '/export.php';
    };

    /**
     * Handle import data action.
     *
     * @param {jQuery} $button - The clicked button.
     */
    var handleImportData = function($button) {
        // Navigate to import page
        window.location.href = Common.getPluginUrl() + '/import.php';
    };

    /**
     * Handle test connection action.
     *
     * @param {jQuery} $button - The clicked button.
     */
    var handleTestConnection = function($button) {
        var loader = Common.buttonLoading($button, 'Testing...');

        Common.ajax('local_edulution_test_keycloak_connection', {})
            .then(function(response) {
                if (response.success) {
                    loader.success('Connected');
                    Common.notifySuccess('Keycloak connection successful');
                } else {
                    loader.error('Failed');
                    Common.notifyError('Connection failed: ' + response.message);
                }
                refreshStatusCards();
                setTimeout(function() {
                    loader.reset();
                }, 2000);
            })
            .catch(function(error) {
                loader.error('Error');
                Common.notifyError('Connection test failed: ' + error.message);
                setTimeout(function() {
                    loader.reset();
                }, 2000);
            });
    };

    /**
     * Handle view logs action.
     *
     * @param {jQuery} $button - The clicked button.
     */
    var handleViewLogs = function($button) {
        // Navigate to logs page
        window.location.href = Common.getPluginUrl() + '/logs.php';
    };

    /**
     * Handle clear cache action.
     *
     * @param {jQuery} $button - The clicked button.
     */
    var handleClearCache = function($button) {
        Common.confirm(
            'Clear Cache',
            'Are you sure you want to clear the edulution cache? This may temporarily slow down operations.',
            'Clear Cache',
            'Cancel'
        ).then(function() {
            var loader = Common.buttonLoading($button, 'Clearing...');

            return Common.ajax('local_edulution_clear_cache', {})
                .then(function(response) {
                    loader.success('Cleared');
                    Common.notifySuccess('Cache cleared successfully');
                    refreshStatusCards();
                    setTimeout(function() {
                        loader.reset();
                    }, 2000);
                })
                .catch(function(error) {
                    loader.error('Failed');
                    Common.notifyError('Failed to clear cache: ' + error.message);
                    setTimeout(function() {
                        loader.reset();
                    }, 2000);
                });
        }).catch(function() {
            // User cancelled
        });
    };

    /**
     * Initialize activity log.
     */
    var initActivityLog = function() {
        // Load more button
        elements.activityLog.on('click', '.load-more', function(e) {
            e.preventDefault();
            loadMoreActivity($(this));
        });

        // Refresh button
        elements.activityLog.on('click', '.refresh-log', function(e) {
            e.preventDefault();
            refreshActivityLog();
        });

        // Initial load
        refreshActivityLog();
    };

    /**
     * Refresh the activity log.
     *
     * @returns {Promise} Promise resolving when refresh is complete.
     */
    var refreshActivityLog = function() {
        var $log = elements.activityLog.find('.activity-list');
        var loader = Common.showLoading($log);

        return Common.ajax('local_edulution_get_activity_log', {
            limit: config.activityLogLimit,
            offset: 0
        }).then(function(response) {
            loader.hide();
            return renderActivityLog(response.activities, response.hasMore);
        }).catch(function(error) {
            loader.hide();
            Common.notifyError('Failed to load activity log: ' + error.message);
        });
    };

    /**
     * Load more activity log entries.
     *
     * @param {jQuery} $button - The load more button.
     */
    var loadMoreActivity = function($button) {
        var currentCount = elements.activityLog.find('.activity-item').length;
        var loader = Common.buttonLoading($button, 'Loading...');

        Common.ajax('local_edulution_get_activity_log', {
            limit: config.activityLogLimit,
            offset: currentCount
        }).then(function(response) {
            loader.reset();
            appendActivityItems(response.activities, response.hasMore);
        }).catch(function(error) {
            loader.reset();
            Common.notifyError('Failed to load more activities: ' + error.message);
        });
    };

    /**
     * Render the activity log.
     *
     * @param {Array} activities - Activity entries.
     * @param {boolean} hasMore - Whether more entries exist.
     * @returns {Promise} Promise resolving when render is complete.
     */
    var renderActivityLog = function(activities, hasMore) {
        var context = {
            activities: activities.map(formatActivityItem),
            hasMore: hasMore,
            empty: activities.length === 0
        };

        return Common.renderReplace('local_edulution/activity_log', context,
            elements.activityLog.find('.activity-list'));
    };

    /**
     * Append activity items to the log.
     *
     * @param {Array} activities - Activity entries to append.
     * @param {boolean} hasMore - Whether more entries exist.
     */
    var appendActivityItems = function(activities, hasMore) {
        var $list = elements.activityLog.find('.activity-list ul');
        var $loadMore = elements.activityLog.find('.load-more');

        activities.forEach(function(activity) {
            var item = formatActivityItem(activity);
            var html = '<li class="activity-item list-group-item">' +
                '<div class="d-flex justify-content-between align-items-center">' +
                '<div>' +
                '<i class="fa ' + item.icon + ' mr-2 text-' + item.type + '"></i>' +
                '<strong>' + Common.escapeHtml(item.action) + '</strong>' +
                '<span class="text-muted ml-2">' + Common.escapeHtml(item.details) + '</span>' +
                '</div>' +
                '<small class="text-muted">' + item.timeFormatted + '</small>' +
                '</div>' +
                '</li>';
            $list.append(html);
        });

        if (!hasMore) {
            $loadMore.hide();
        }
    };

    /**
     * Format an activity item for display.
     *
     * @param {object} activity - Raw activity data.
     * @returns {object} Formatted activity data.
     */
    var formatActivityItem = function(activity) {
        var icons = {
            sync: 'fa-sync',
            export: 'fa-download',
            import: 'fa-upload',
            user: 'fa-user',
            error: 'fa-exclamation-triangle',
            success: 'fa-check',
            info: 'fa-info-circle'
        };

        var types = {
            sync: 'primary',
            export: 'success',
            import: 'info',
            user: 'secondary',
            error: 'danger',
            success: 'success',
            info: 'info'
        };

        return {
            id: activity.id,
            action: activity.action,
            details: activity.details,
            user: activity.user,
            time: activity.time,
            timeFormatted: Common.formatDate(activity.time, true),
            type: types[activity.type] || 'secondary',
            icon: icons[activity.type] || 'fa-circle'
        };
    };

    /**
     * Start auto-refresh timer.
     */
    var startAutoRefresh = function() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
        }

        refreshTimer = setInterval(function() {
            refreshStatusCards();
        }, config.refreshInterval);
    };

    /**
     * Stop auto-refresh timer.
     */
    var stopAutoRefresh = function() {
        if (refreshTimer) {
            clearInterval(refreshTimer);
            refreshTimer = null;
        }
    };

    /**
     * Handle visibility change to pause/resume auto-refresh.
     */
    var handleVisibilityChange = function() {
        if (document.hidden) {
            stopAutoRefresh();
        } else if (config.autoRefresh) {
            // Refresh immediately when becoming visible
            refreshStatusCards();
            startAutoRefresh();
        }
    };

    /**
     * Set auto-refresh interval.
     *
     * @param {number} interval - Interval in milliseconds.
     */
    var setRefreshInterval = function(interval) {
        config.refreshInterval = interval;
        if (config.autoRefresh && refreshTimer) {
            startAutoRefresh();
        }
    };

    /**
     * Enable or disable auto-refresh.
     *
     * @param {boolean} enabled - Whether auto-refresh should be enabled.
     */
    var setAutoRefresh = function(enabled) {
        config.autoRefresh = enabled;
        if (enabled) {
            startAutoRefresh();
        } else {
            stopAutoRefresh();
        }
    };

    // Public API
    return {
        init: init,
        refreshStatusCards: refreshStatusCards,
        refreshActivityLog: refreshActivityLog,
        startAutoRefresh: startAutoRefresh,
        stopAutoRefresh: stopAutoRefresh,
        setRefreshInterval: setRefreshInterval,
        setAutoRefresh: setAutoRefresh
    };
});
