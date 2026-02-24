/**
 * Keycloak setup functionality for Edulution plugin.
 *
 * Handles connection testing, form validation, wizard navigation,
 * and saving settings via AJAX.
 *
 * @module     local_edulution/keycloak
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
        wizardSteps: ['connection', 'realm', 'client', 'mapping', 'confirm'],
        autoTestOnChange: true,
        testTimeout: 30000 // 30 seconds
    };

    /**
     * Current state.
     * @type {object}
     */
    var state = {
        currentStep: 0,
        connectionValid: false,
        settings: {},
        testResults: {}
    };

    /**
     * DOM element references.
     * @type {object}
     */
    var elements = {
        wizard: null,
        steps: null,
        stepContents: null,
        prevBtn: null,
        nextBtn: null,
        saveBtn: null,
        testBtn: null,
        form: null,
        connectionStatus: null
    };

    /**
     * Initialize the Keycloak setup module.
     *
     * @param {object} options - Configuration options.
     */
    var init = function(options) {
        config = $.extend({}, config, options);

        // Cache element references
        elements.wizard = $('#keycloak-wizard');
        elements.steps = elements.wizard.find('.wizard-steps');
        elements.stepContents = elements.wizard.find('.wizard-content');
        elements.prevBtn = $('#wizard-prev-btn');
        elements.nextBtn = $('#wizard-next-btn');
        elements.saveBtn = $('#wizard-save-btn');
        elements.testBtn = $('#keycloak-test-btn');
        elements.form = $('#keycloak-settings-form');
        elements.connectionStatus = $('#keycloak-connection-status');

        // Initialize components
        initWizard();
        initForm();
        initTestButton();

        // Load existing settings if any
        loadExistingSettings();
    };

    /**
     * Initialize wizard navigation.
     */
    var initWizard = function() {
        // Previous button
        elements.prevBtn.on('click', function(e) {
            e.preventDefault();
            goToStep(state.currentStep - 1);
        });

        // Next button
        elements.nextBtn.on('click', function(e) {
            e.preventDefault();
            if (validateCurrentStep()) {
                goToStep(state.currentStep + 1);
            }
        });

        // Save button
        elements.saveBtn.on('click', function(e) {
            e.preventDefault();
            saveSettings();
        });

        // Step indicators
        elements.steps.on('click', '.step-indicator', function() {
            var step = $(this).data('step');
            if (canGoToStep(step)) {
                goToStep(step);
            }
        });

        // Initialize first step
        goToStep(0);
    };

    /**
     * Go to a specific wizard step.
     *
     * @param {number} step - Step index.
     */
    var goToStep = function(step) {
        if (step < 0 || step >= config.wizardSteps.length) {
            return;
        }

        // Save current step data
        if (state.currentStep !== step) {
            saveStepData(state.currentStep);
        }

        state.currentStep = step;

        // Update step indicators
        elements.steps.find('.step-indicator').removeClass('active completed');
        elements.steps.find('.step-indicator').each(function(index) {
            if (index < step) {
                $(this).addClass('completed');
            } else if (index === step) {
                $(this).addClass('active');
            }
        });

        // Show current step content
        elements.stepContents.find('.step-content').hide();
        elements.stepContents.find('[data-step="' + config.wizardSteps[step] + '"]').show();

        // Update navigation buttons
        updateNavigationButtons();

        // Trigger step-specific initialization
        initStep(step);
    };

    /**
     * Check if we can navigate to a specific step.
     *
     * @param {number} step - Step index.
     * @returns {boolean} Whether navigation is allowed.
     */
    var canGoToStep = function(step) {
        // Can always go back
        if (step < state.currentStep) {
            return true;
        }

        // Must validate all previous steps to go forward
        for (var i = state.currentStep; i < step; i++) {
            if (!validateStep(i)) {
                return false;
            }
        }

        return true;
    };

    /**
     * Update navigation button states.
     */
    var updateNavigationButtons = function() {
        // Previous button
        elements.prevBtn.prop('disabled', state.currentStep === 0);

        // Next button
        if (state.currentStep === config.wizardSteps.length - 1) {
            elements.nextBtn.hide();
            elements.saveBtn.show();
        } else {
            elements.nextBtn.show();
            elements.saveBtn.hide();
        }
    };

    /**
     * Initialize a specific step.
     *
     * @param {number} step - Step index.
     */
    var initStep = function(step) {
        var stepName = config.wizardSteps[step];

        switch (stepName) {
            case 'connection':
                initConnectionStep();
                break;
            case 'realm':
                initRealmStep();
                break;
            case 'client':
                initClientStep();
                break;
            case 'mapping':
                initMappingStep();
                break;
            case 'confirm':
                initConfirmStep();
                break;
        }
    };

    /**
     * Initialize connection step.
     */
    var initConnectionStep = function() {
        // Auto-test on URL change with debounce
        if (config.autoTestOnChange) {
            $('#keycloak-server-url').off('input.autotest').on('input.autotest',
                Common.debounce(function() {
                    if ($(this).val()) {
                        testConnection(true);
                    }
                }, 1000)
            );
        }
    };

    /**
     * Initialize realm step.
     */
    var initRealmStep = function() {
        // Load available realms if connected
        if (state.connectionValid) {
            loadAvailableRealms();
        }
    };

    /**
     * Initialize client step.
     */
    var initClientStep = function() {
        // Load available clients if realm is set
        var realm = state.settings.realm;
        if (state.connectionValid && realm) {
            loadAvailableClients(realm);
        }

        // Client secret visibility toggle
        $('#toggle-client-secret').off('click').on('click', function(e) {
            e.preventDefault();
            var $input = $('#keycloak-client-secret');
            var $icon = $(this).find('i');
            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $icon.removeClass('fa-eye').addClass('fa-eye-slash');
            } else {
                $input.attr('type', 'password');
                $icon.removeClass('fa-eye-slash').addClass('fa-eye');
            }
        });
    };

    /**
     * Initialize mapping step.
     */
    var initMappingStep = function() {
        // Load Moodle user fields for mapping
        loadMoodleFields();

        // Load Keycloak attributes
        if (state.connectionValid && state.settings.realm) {
            loadKeycloakAttributes();
        }
    };

    /**
     * Initialize confirm step.
     */
    var initConfirmStep = function() {
        // Render confirmation summary
        renderConfirmationSummary();
    };

    /**
     * Initialize form handling.
     */
    var initForm = function() {
        // Real-time validation
        elements.form.find('input, select').on('change blur', function() {
            validateField($(this));
        });

        // Prevent form submission
        elements.form.on('submit', function(e) {
            e.preventDefault();
        });
    };

    /**
     * Initialize test connection button.
     */
    var initTestButton = function() {
        elements.testBtn.on('click', function(e) {
            e.preventDefault();
            testConnection(false);
        });
    };

    /**
     * Test Keycloak connection.
     *
     * @param {boolean} silent - Whether to suppress notifications.
     * @returns {Promise} Promise resolving to test result.
     */
    var testConnection = function(silent) {
        var serverUrl = $('#keycloak-server-url').val();
        var adminUser = $('#keycloak-admin-user').val();
        var adminPassword = $('#keycloak-admin-password').val();

        if (!serverUrl) {
            if (!silent) {
                Common.notifyError('Please enter the Keycloak server URL');
            }
            return Promise.reject('Missing server URL');
        }

        // Update status
        updateConnectionStatus('testing', 'Testing connection...');

        var loader = silent ? null : Common.buttonLoading(elements.testBtn, 'Testing...');

        return Common.ajax('local_edulution_test_keycloak_connection', {
            serverUrl: serverUrl,
            adminUser: adminUser,
            adminPassword: adminPassword
        }).then(function(response) {
            if (loader) {
                loader.reset();
            }

            state.testResults.connection = response;

            if (response.success) {
                state.connectionValid = true;
                updateConnectionStatus('success', 'Connected to Keycloak ' + (response.version || ''));
                if (!silent) {
                    Common.notifySuccess('Connection successful');
                }
            } else {
                state.connectionValid = false;
                updateConnectionStatus('error', response.message || 'Connection failed');
                if (!silent) {
                    Common.notifyError('Connection failed: ' + response.message);
                }
            }

            return response;
        }).catch(function(error) {
            if (loader) {
                loader.reset();
            }

            state.connectionValid = false;
            updateConnectionStatus('error', error.message || 'Connection test failed');

            if (!silent) {
                Common.notifyError('Connection test failed: ' + error.message);
            }

            return Promise.reject(error);
        });
    };

    /**
     * Update connection status display.
     *
     * @param {string} status - Status type (testing, success, error).
     * @param {string} message - Status message.
     */
    var updateConnectionStatus = function(status, message) {
        var $status = elements.connectionStatus;
        var icons = {
            testing: '<i class="fa fa-spinner fa-spin mr-2"></i>',
            success: '<i class="fa fa-check-circle mr-2 text-success"></i>',
            error: '<i class="fa fa-times-circle mr-2 text-danger"></i>'
        };

        var classes = {
            testing: 'alert-info',
            success: 'alert-success',
            error: 'alert-danger'
        };

        $status
            .removeClass('alert-info alert-success alert-danger')
            .addClass(classes[status])
            .html(icons[status] + Common.escapeHtml(message))
            .show();
    };

    /**
     * Load available realms from Keycloak.
     */
    var loadAvailableRealms = function() {
        var $select = $('#keycloak-realm');
        var loader = Common.showLoading($select.parent(), 'Loading realms...');

        Common.ajax('local_edulution_get_keycloak_realms', {
            serverUrl: state.settings.serverUrl,
            adminUser: state.settings.adminUser,
            adminPassword: state.settings.adminPassword
        }).then(function(response) {
            loader.hide();

            if (response.realms && response.realms.length > 0) {
                $select.empty().append('<option value="">Select a realm...</option>');
                response.realms.forEach(function(realm) {
                    $select.append('<option value="' + Common.escapeHtml(realm.name) + '">' +
                        Common.escapeHtml(realm.name) + '</option>');
                });

                // Restore previous selection
                if (state.settings.realm) {
                    $select.val(state.settings.realm);
                }
            } else {
                Common.notifyWarning('No realms found');
            }
        }).catch(function(error) {
            loader.hide();
            Common.notifyError('Failed to load realms: ' + error.message);
        });
    };

    /**
     * Load available clients from Keycloak.
     *
     * @param {string} realm - Realm name.
     */
    var loadAvailableClients = function(realm) {
        var $select = $('#keycloak-client-id');
        var loader = Common.showLoading($select.parent(), 'Loading clients...');

        Common.ajax('local_edulution_get_keycloak_clients', {
            serverUrl: state.settings.serverUrl,
            adminUser: state.settings.adminUser,
            adminPassword: state.settings.adminPassword,
            realm: realm
        }).then(function(response) {
            loader.hide();

            if (response.clients && response.clients.length > 0) {
                $select.empty().append('<option value="">Select a client...</option>');
                response.clients.forEach(function(client) {
                    $select.append('<option value="' + Common.escapeHtml(client.clientId) + '">' +
                        Common.escapeHtml(client.clientId) +
                        (client.name ? ' (' + Common.escapeHtml(client.name) + ')' : '') +
                        '</option>');
                });

                // Restore previous selection
                if (state.settings.clientId) {
                    $select.val(state.settings.clientId);
                }
            } else {
                Common.notifyWarning('No clients found');
            }
        }).catch(function(error) {
            loader.hide();
            Common.notifyError('Failed to load clients: ' + error.message);
        });
    };

    /**
     * Load Moodle user fields for mapping.
     */
    var loadMoodleFields = function() {
        var $container = $('#moodle-field-mappings');

        Common.ajax('local_edulution_get_moodle_user_fields', {})
            .then(function(response) {
                if (response.fields) {
                    renderFieldMappings(response.fields);
                }
            })
            .catch(function(error) {
                Common.notifyError('Failed to load Moodle fields: ' + error.message);
            });
    };

    /**
     * Load Keycloak user attributes.
     */
    var loadKeycloakAttributes = function() {
        Common.ajax('local_edulution_get_keycloak_attributes', {
            serverUrl: state.settings.serverUrl,
            adminUser: state.settings.adminUser,
            adminPassword: state.settings.adminPassword,
            realm: state.settings.realm
        }).then(function(response) {
            if (response.attributes) {
                state.keycloakAttributes = response.attributes;
                updateMappingSelects(response.attributes);
            }
        }).catch(function(error) {
            Common.notifyError('Failed to load Keycloak attributes: ' + error.message);
        });
    };

    /**
     * Render field mapping UI.
     *
     * @param {Array} moodleFields - Moodle user fields.
     */
    var renderFieldMappings = function(moodleFields) {
        var $container = $('#field-mappings-container');
        var html = '<table class="table table-sm"><thead><tr>' +
            '<th>Moodle Field</th><th>Keycloak Attribute</th><th>Direction</th>' +
            '</tr></thead><tbody>';

        moodleFields.forEach(function(field) {
            html += '<tr data-field="' + Common.escapeHtml(field.name) + '">' +
                '<td><label>' + Common.escapeHtml(field.label) + '</label></td>' +
                '<td><select class="form-control form-control-sm keycloak-attr-select" ' +
                'data-field="' + Common.escapeHtml(field.name) + '">' +
                '<option value="">-- Not mapped --</option>' +
                '<option value="' + Common.escapeHtml(field.name) + '">' +
                Common.escapeHtml(field.name) + ' (same name)</option>' +
                '</select></td>' +
                '<td><select class="form-control form-control-sm mapping-direction" ' +
                'data-field="' + Common.escapeHtml(field.name) + '">' +
                '<option value="both">Bidirectional</option>' +
                '<option value="to_keycloak">To Keycloak only</option>' +
                '<option value="from_keycloak">From Keycloak only</option>' +
                '</select></td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $container.html(html);

        // Restore saved mappings
        if (state.settings.fieldMappings) {
            state.settings.fieldMappings.forEach(function(mapping) {
                var $row = $container.find('[data-field="' + mapping.moodleField + '"]');
                $row.find('.keycloak-attr-select').val(mapping.keycloakAttribute);
                $row.find('.mapping-direction').val(mapping.direction);
            });
        }
    };

    /**
     * Update mapping select options with Keycloak attributes.
     *
     * @param {Array} attributes - Keycloak attributes.
     */
    var updateMappingSelects = function(attributes) {
        var $selects = $('.keycloak-attr-select');
        $selects.each(function() {
            var $select = $(this);
            var currentVal = $select.val();

            // Add Keycloak attributes
            attributes.forEach(function(attr) {
                if ($select.find('option[value="' + attr + '"]').length === 0) {
                    $select.append('<option value="' + Common.escapeHtml(attr) + '">' +
                        Common.escapeHtml(attr) + '</option>');
                }
            });

            // Restore selection
            if (currentVal) {
                $select.val(currentVal);
            }
        });
    };

    /**
     * Render confirmation summary.
     */
    var renderConfirmationSummary = function() {
        // Collect all settings
        collectSettings();

        var context = {
            serverUrl: state.settings.serverUrl,
            realm: state.settings.realm,
            clientId: state.settings.clientId,
            hasClientSecret: !!state.settings.clientSecret,
            fieldMappings: state.settings.fieldMappings || [],
            syncUsers: state.settings.syncUsers,
            syncGroups: state.settings.syncGroups,
            autoSync: state.settings.autoSync
        };

        Common.renderReplace('local_edulution/keycloak_confirm', context, '#confirm-summary');
    };

    /**
     * Save step data to state.
     *
     * @param {number} step - Step index.
     */
    var saveStepData = function(step) {
        var stepName = config.wizardSteps[step];

        switch (stepName) {
            case 'connection':
                state.settings.serverUrl = $('#keycloak-server-url').val();
                state.settings.adminUser = $('#keycloak-admin-user').val();
                state.settings.adminPassword = $('#keycloak-admin-password').val();
                break;
            case 'realm':
                state.settings.realm = $('#keycloak-realm').val();
                break;
            case 'client':
                state.settings.clientId = $('#keycloak-client-id').val();
                state.settings.clientSecret = $('#keycloak-client-secret').val();
                break;
            case 'mapping':
                state.settings.fieldMappings = collectFieldMappings();
                break;
        }
    };

    /**
     * Collect field mappings from UI.
     *
     * @returns {Array} Field mappings.
     */
    var collectFieldMappings = function() {
        var mappings = [];

        $('#field-mappings-container tbody tr').each(function() {
            var $row = $(this);
            var moodleField = $row.data('field');
            var keycloakAttr = $row.find('.keycloak-attr-select').val();
            var direction = $row.find('.mapping-direction').val();

            if (keycloakAttr) {
                mappings.push({
                    moodleField: moodleField,
                    keycloakAttribute: keycloakAttr,
                    direction: direction
                });
            }
        });

        return mappings;
    };

    /**
     * Collect all settings from form.
     */
    var collectSettings = function() {
        for (var i = 0; i < config.wizardSteps.length; i++) {
            saveStepData(i);
        }

        // Additional settings
        state.settings.syncUsers = $('#keycloak-sync-users').is(':checked');
        state.settings.syncGroups = $('#keycloak-sync-groups').is(':checked');
        state.settings.autoSync = $('#keycloak-auto-sync').is(':checked');
        state.settings.autoSyncInterval = $('#keycloak-sync-interval').val();
    };

    /**
     * Validate current step.
     *
     * @returns {boolean} Whether step is valid.
     */
    var validateCurrentStep = function() {
        return validateStep(state.currentStep);
    };

    /**
     * Validate a specific step.
     *
     * @param {number} step - Step index.
     * @returns {boolean} Whether step is valid.
     */
    var validateStep = function(step) {
        var stepName = config.wizardSteps[step];
        var isValid = true;

        switch (stepName) {
            case 'connection':
                isValid = validateConnectionStep();
                break;
            case 'realm':
                isValid = validateRealmStep();
                break;
            case 'client':
                isValid = validateClientStep();
                break;
            case 'mapping':
                isValid = validateMappingStep();
                break;
            case 'confirm':
                isValid = true; // Always valid
                break;
        }

        return isValid;
    };

    /**
     * Validate connection step.
     *
     * @returns {boolean} Whether step is valid.
     */
    var validateConnectionStep = function() {
        var serverUrl = $('#keycloak-server-url').val();

        if (!serverUrl) {
            showFieldError($('#keycloak-server-url'), 'Server URL is required');
            return false;
        }

        // Validate URL format
        try {
            new URL(serverUrl);
        } catch (e) {
            showFieldError($('#keycloak-server-url'), 'Invalid URL format');
            return false;
        }

        if (!state.connectionValid) {
            Common.notifyWarning('Please test the connection before proceeding');
            return false;
        }

        return true;
    };

    /**
     * Validate realm step.
     *
     * @returns {boolean} Whether step is valid.
     */
    var validateRealmStep = function() {
        var realm = $('#keycloak-realm').val();

        if (!realm) {
            showFieldError($('#keycloak-realm'), 'Please select a realm');
            return false;
        }

        return true;
    };

    /**
     * Validate client step.
     *
     * @returns {boolean} Whether step is valid.
     */
    var validateClientStep = function() {
        var clientId = $('#keycloak-client-id').val();

        if (!clientId) {
            showFieldError($('#keycloak-client-id'), 'Please select a client');
            return false;
        }

        return true;
    };

    /**
     * Validate mapping step.
     *
     * @returns {boolean} Whether step is valid.
     */
    var validateMappingStep = function() {
        // At least username should be mapped
        var hasUserMapping = false;
        $('#field-mappings-container .keycloak-attr-select').each(function() {
            var field = $(this).data('field');
            var value = $(this).val();
            if (field === 'username' && value) {
                hasUserMapping = true;
            }
        });

        if (!hasUserMapping) {
            Common.notifyWarning('Username field must be mapped');
            return false;
        }

        return true;
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
     * Load existing settings from server.
     */
    var loadExistingSettings = function() {
        Common.ajax('local_edulution_get_keycloak_settings', {})
            .then(function(response) {
                if (response.settings) {
                    state.settings = response.settings;
                    populateForm(response.settings);

                    // If connection settings exist, test connection
                    if (response.settings.serverUrl) {
                        testConnection(true);
                    }
                }
            })
            .catch(function() {
                // No existing settings, that's fine
            });
    };

    /**
     * Populate form with settings.
     *
     * @param {object} settings - Settings object.
     */
    var populateForm = function(settings) {
        if (settings.serverUrl) {
            $('#keycloak-server-url').val(settings.serverUrl);
        }
        if (settings.adminUser) {
            $('#keycloak-admin-user').val(settings.adminUser);
        }
        if (settings.realm) {
            $('#keycloak-realm').val(settings.realm);
        }
        if (settings.clientId) {
            $('#keycloak-client-id').val(settings.clientId);
        }
        if (settings.syncUsers !== undefined) {
            $('#keycloak-sync-users').prop('checked', settings.syncUsers);
        }
        if (settings.syncGroups !== undefined) {
            $('#keycloak-sync-groups').prop('checked', settings.syncGroups);
        }
        if (settings.autoSync !== undefined) {
            $('#keycloak-auto-sync').prop('checked', settings.autoSync);
        }
        if (settings.autoSyncInterval) {
            $('#keycloak-sync-interval').val(settings.autoSyncInterval);
        }
    };

    /**
     * Save settings to server.
     */
    var saveSettings = function() {
        if (!validateCurrentStep()) {
            return;
        }

        // Collect all settings
        collectSettings();

        var loader = Common.buttonLoading(elements.saveBtn, 'Saving...');

        Common.ajax('local_edulution_save_keycloak_settings', {
            settings: JSON.stringify(state.settings)
        }).then(function(response) {
            if (response.success) {
                loader.success('Saved');
                Common.notifySuccess('Settings saved successfully');

                // Optionally redirect to dashboard
                setTimeout(function() {
                    window.location.href = Common.getPluginUrl() + '/dashboard.php';
                }, 1500);
            } else {
                loader.error('Failed');
                Common.notifyError(response.message || 'Failed to save settings');
            }
        }).catch(function(error) {
            loader.error('Error');
            Common.notifyError('Failed to save settings: ' + error.message);
        });
    };

    /**
     * Get current settings.
     *
     * @returns {object} Current settings.
     */
    var getSettings = function() {
        collectSettings();
        return state.settings;
    };

    // Public API
    return {
        init: init,
        testConnection: testConnection,
        goToStep: goToStep,
        saveSettings: saveSettings,
        getSettings: getSettings
    };
});
