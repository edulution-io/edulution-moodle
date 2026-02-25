/**
 * Common utilities for edulution plugin.
 *
 * @module     local_edulution/common
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification', 'core/str', 'core/templates', 'core/modal_factory', 'core/modal_events'],
function($, Ajax, Notification, Str, Templates, ModalFactory, ModalEvents) {

    /**
     * CSRF token for AJAX requests.
     * @type {string}
     */
    var sesskey = M.cfg.sesskey;

    /**
     * Base URL for the plugin.
     * @type {string}
     */
    var pluginUrl = M.cfg.wwwroot + '/local/edulution';

    return {
        /**
         * Make an AJAX call to a Moodle webservice.
         *
         * @param {string} methodname - The webservice method name.
         * @param {object} args - Arguments to pass to the webservice.
         * @returns {Promise} Promise resolving to the response data.
         */
        ajax: function(methodname, args) {
            return Ajax.call([{
                methodname: methodname,
                args: args
            }])[0];
        },

        /**
         * Make a direct AJAX POST request with CSRF token.
         *
         * @param {string} url - The URL to post to.
         * @param {object} data - Data to send.
         * @returns {Promise} jQuery promise.
         */
        post: function(url, data) {
            data = data || {};
            data.sesskey = sesskey;

            return $.ajax({
                url: url,
                type: 'POST',
                data: data,
                dataType: 'json'
            });
        },

        /**
         * Make a direct AJAX GET request.
         *
         * @param {string} url - The URL to get.
         * @param {object} data - Query parameters.
         * @returns {Promise} jQuery promise.
         */
        get: function(url, data) {
            data = data || {};
            data.sesskey = sesskey;

            return $.ajax({
                url: url,
                type: 'GET',
                data: data,
                dataType: 'json'
            });
        },

        /**
         * Create and manage a progress bar.
         *
         * @param {string|jQuery} container - Container selector or element.
         * @param {object} options - Configuration options.
         * @returns {object} Progress bar controller.
         */
        progressBar: function(container, options) {
            var $container = $(container);
            var defaults = {
                min: 0,
                max: 100,
                value: 0,
                showPercent: true,
                showLabel: true,
                label: '',
                striped: true,
                animated: true,
                type: 'info' // info, success, warning, danger
            };
            var config = $.extend({}, defaults, options);

            var $wrapper = $('<div class="edulution-progress-wrapper mb-3"></div>');
            var $label = $('<div class="progress-label mb-1"></div>');
            var $progress = $('<div class="progress" style="height: 25px;"></div>');
            var $bar = $('<div class="progress-bar" role="progressbar"></div>');

            if (config.striped) {
                $bar.addClass('progress-bar-striped');
            }
            if (config.animated) {
                $bar.addClass('progress-bar-animated');
            }
            $bar.addClass('bg-' + config.type);

            $progress.append($bar);
            $wrapper.append($label).append($progress);
            $container.append($wrapper);

            var controller = {
                /**
                 * Update the progress bar value.
                 *
                 * @param {number} value - Current value.
                 * @param {string} label - Optional label text.
                 */
                update: function(value, label) {
                    var percent = Math.round((value - config.min) / (config.max - config.min) * 100);
                    percent = Math.max(0, Math.min(100, percent));

                    $bar.css('width', percent + '%');
                    $bar.attr('aria-valuenow', value);

                    if (config.showPercent) {
                        $bar.text(percent + '%');
                    }

                    if (config.showLabel && label) {
                        $label.text(label);
                    }
                },

                /**
                 * Set progress bar type/color.
                 *
                 * @param {string} type - Bootstrap color type.
                 */
                setType: function(type) {
                    $bar.removeClass('bg-info bg-success bg-warning bg-danger');
                    $bar.addClass('bg-' + type);
                },

                /**
                 * Show the progress bar.
                 */
                show: function() {
                    $wrapper.show();
                },

                /**
                 * Hide the progress bar.
                 */
                hide: function() {
                    $wrapper.hide();
                },

                /**
                 * Reset the progress bar.
                 */
                reset: function() {
                    this.update(config.min, '');
                    this.setType('info');
                },

                /**
                 * Mark as complete.
                 */
                complete: function() {
                    this.update(config.max, '');
                    this.setType('success');
                    $bar.removeClass('progress-bar-animated');
                },

                /**
                 * Mark as error.
                 *
                 * @param {string} message - Error message.
                 */
                error: function(message) {
                    this.setType('danger');
                    $bar.removeClass('progress-bar-animated');
                    if (message) {
                        $label.text(message);
                    }
                },

                /**
                 * Destroy the progress bar.
                 */
                destroy: function() {
                    $wrapper.remove();
                }
            };

            controller.update(config.value, config.label);
            return controller;
        },

        /**
         * Show a success notification.
         *
         * @param {string} message - The message to display.
         */
        notifySuccess: function(message) {
            Notification.addNotification({
                message: message,
                type: 'success'
            });
        },

        /**
         * Show an error notification.
         *
         * @param {string} message - The error message.
         */
        notifyError: function(message) {
            Notification.addNotification({
                message: message,
                type: 'error'
            });
        },

        /**
         * Show an info notification.
         *
         * @param {string} message - The message to display.
         */
        notifyInfo: function(message) {
            Notification.addNotification({
                message: message,
                type: 'info'
            });
        },

        /**
         * Show a warning notification.
         *
         * @param {string} message - The warning message.
         */
        notifyWarning: function(message) {
            Notification.addNotification({
                message: message,
                type: 'warning'
            });
        },

        /**
         * Show a confirmation dialog.
         *
         * @param {string} title - Dialog title.
         * @param {string} message - Confirmation message.
         * @param {string} confirmText - Text for confirm button.
         * @param {string} cancelText - Text for cancel button.
         * @returns {Promise} Resolves if confirmed, rejects if cancelled.
         */
        confirm: function(title, message, confirmText, cancelText) {
            return new Promise(function(resolve, reject) {
                Notification.confirm(
                    title,
                    message,
                    confirmText || 'Confirm',
                    cancelText || 'Cancel',
                    function() {
                        resolve(true);
                    },
                    function() {
                        reject(false);
                    }
                );
            });
        },

        /**
         * Show a modal dialog.
         *
         * @param {object} options - Modal options.
         * @returns {Promise} Promise resolving to the modal instance.
         */
        modal: function(options) {
            var defaults = {
                type: ModalFactory.types.DEFAULT,
                title: '',
                body: '',
                footer: '',
                large: false
            };
            var config = $.extend({}, defaults, options);

            return ModalFactory.create({
                type: config.type,
                title: config.title,
                body: config.body,
                footer: config.footer,
                large: config.large
            });
        },

        /**
         * Show a loading spinner in a container.
         *
         * @param {string|jQuery} container - Container selector or element.
         * @param {string} message - Optional loading message.
         * @returns {object} Loader controller with hide method.
         */
        showLoading: function(container, message) {
            var $container = $(container);
            var loadingHtml = '<div class="edulution-loading text-center p-4">' +
                '<div class="spinner-border text-primary" role="status">' +
                '<span class="sr-only">Loading...</span>' +
                '</div>' +
                (message ? '<p class="mt-2 mb-0">' + message + '</p>' : '') +
                '</div>';

            var $loading = $(loadingHtml);
            $container.append($loading);

            return {
                hide: function() {
                    $loading.remove();
                },
                updateMessage: function(newMessage) {
                    $loading.find('p').text(newMessage);
                }
            };
        },

        /**
         * Add loading state to a button.
         *
         * @param {string|jQuery} button - Button selector or element.
         * @param {string} loadingText - Text to show while loading.
         * @returns {object} Controller with reset method.
         */
        buttonLoading: function(button, loadingText) {
            var $button = $(button);
            var originalText = $button.html();
            var originalDisabled = $button.prop('disabled');

            $button.prop('disabled', true);
            $button.html('<span class="spinner-border spinner-border-sm mr-1" role="status"></span> ' +
                (loadingText || 'Loading...'));

            return {
                reset: function() {
                    $button.prop('disabled', originalDisabled);
                    $button.html(originalText);
                },
                success: function(text) {
                    $button.prop('disabled', originalDisabled);
                    $button.html('<i class="fa fa-check mr-1"></i> ' + (text || 'Done'));
                },
                error: function(text) {
                    $button.prop('disabled', originalDisabled);
                    $button.html('<i class="fa fa-times mr-1"></i> ' + (text || 'Error'));
                }
            };
        },

        /**
         * Format a date for display.
         *
         * @param {Date|number|string} date - Date to format.
         * @param {boolean} includeTime - Whether to include time.
         * @returns {string} Formatted date string.
         */
        formatDate: function(date, includeTime) {
            if (!(date instanceof Date)) {
                date = new Date(date);
            }

            var options = {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            };

            if (includeTime) {
                options.hour = '2-digit';
                options.minute = '2-digit';
            }

            return date.toLocaleDateString(undefined, options);
        },

        /**
         * Format a number with thousands separators.
         *
         * @param {number} number - Number to format.
         * @returns {string} Formatted number.
         */
        formatNumber: function(number) {
            return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        },

        /**
         * Format file size in human readable form.
         *
         * @param {number} bytes - Size in bytes.
         * @returns {string} Formatted size string.
         */
        formatFileSize: function(bytes) {
            if (bytes === 0) {
                return '0 Bytes';
            }
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        /**
         * Format duration in human readable form.
         *
         * @param {number} seconds - Duration in seconds.
         * @returns {string} Formatted duration string.
         */
        formatDuration: function(seconds) {
            if (seconds < 60) {
                return seconds + 's';
            }
            if (seconds < 3600) {
                var mins = Math.floor(seconds / 60);
                var secs = seconds % 60;
                return mins + 'm ' + secs + 's';
            }
            var hours = Math.floor(seconds / 3600);
            var mins = Math.floor((seconds % 3600) / 60);
            return hours + 'h ' + mins + 'm';
        },

        /**
         * Escape HTML special characters.
         *
         * @param {string} text - Text to escape.
         * @returns {string} Escaped text.
         */
        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        },

        /**
         * Debounce a function.
         *
         * @param {Function} func - Function to debounce.
         * @param {number} wait - Wait time in milliseconds.
         * @returns {Function} Debounced function.
         */
        debounce: function(func, wait) {
            var timeout;
            return function() {
                var context = this;
                var args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    func.apply(context, args);
                }, wait);
            };
        },

        /**
         * Throttle a function.
         *
         * @param {Function} func - Function to throttle.
         * @param {number} limit - Minimum time between calls in milliseconds.
         * @returns {Function} Throttled function.
         */
        throttle: function(func, limit) {
            var inThrottle;
            return function() {
                var context = this;
                var args = arguments;
                if (!inThrottle) {
                    func.apply(context, args);
                    inThrottle = true;
                    setTimeout(function() {
                        inThrottle = false;
                    }, limit);
                }
            };
        },

        /**
         * Get plugin base URL.
         *
         * @returns {string} Plugin URL.
         */
        getPluginUrl: function() {
            return pluginUrl;
        },

        /**
         * Get session key.
         *
         * @returns {string} Session key for CSRF protection.
         */
        getSesskey: function() {
            return sesskey;
        },

        /**
         * Render a Mustache template.
         *
         * @param {string} template - Template name (e.g., 'local_edulution/progress').
         * @param {object} context - Template context data.
         * @returns {Promise} Promise resolving to rendered HTML.
         */
        render: function(template, context) {
            return Templates.render(template, context);
        },

        /**
         * Render a template and replace container content.
         *
         * @param {string} template - Template name.
         * @param {object} context - Template context data.
         * @param {string|jQuery} container - Container to update.
         * @returns {Promise} Promise resolving when complete.
         */
        renderReplace: function(template, context, container) {
            return Templates.render(template, context).then(function(html, js) {
                return Templates.replaceNodeContents($(container), html, js);
            });
        },

        /**
         * Get a language string.
         *
         * @param {string} key - String key.
         * @param {string} component - Component name (default: local_edulution).
         * @param {string} param - Optional parameter.
         * @returns {Promise} Promise resolving to the string.
         */
        getString: function(key, component, param) {
            return Str.get_string(key, component || 'local_edulution', param);
        },

        /**
         * Get multiple language strings.
         *
         * @param {Array} keys - Array of {key, component, param} objects.
         * @returns {Promise} Promise resolving to array of strings.
         */
        getStrings: function(keys) {
            return Str.get_strings(keys);
        },

        /**
         * Copy text to clipboard.
         *
         * @param {string} text - Text to copy.
         * @returns {Promise} Promise resolving when copied.
         */
        copyToClipboard: function(text) {
            var self = this;
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text).then(function() {
                    self.notifySuccess('Copied to clipboard');
                });
            } else {
                // Fallback for older browsers
                var textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    self.notifySuccess('Copied to clipboard');
                } catch (err) {
                    self.notifyError('Failed to copy to clipboard');
                }
                document.body.removeChild(textArea);
                return Promise.resolve();
            }
        }
    };
});
