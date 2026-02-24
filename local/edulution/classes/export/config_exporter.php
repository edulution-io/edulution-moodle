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
 * Configuration exporter.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

/**
 * Exporter for Moodle configuration settings.
 *
 * Exports admin settings to JSON, exports theme settings, and creates
 * a sanitized config backup (without passwords or sensitive data).
 */
class config_exporter extends base_exporter {

    /** @var array Settings that contain sensitive data and should be sanitized */
    protected const SENSITIVE_SETTINGS = [
        // Core sensitive settings.
        'smtppass',
        'cronremotepassword',
        'proxypassword',
        'jabberpassword',
        'naborpassword',
        // Authentication settings.
        'auth_ldap_bind_pw',
        'auth_db_pass',
        'auth_oauth2_clientsecret',
        // External database settings.
        'enrol_database_dbpass',
        // Other common sensitive settings.
        'secret',
        'apikey',
        'api_key',
        'privatekey',
        'private_key',
        'accesstoken',
        'access_token',
        'secretkey',
        'secret_key',
    ];

    /** @var array Patterns for sensitive setting names */
    protected const SENSITIVE_PATTERNS = [
        '/password/i',
        '/passwd/i',
        '/secret/i',
        '/apikey/i',
        '/api_key/i',
        '/token/i',
        '/private.*key/i',
    ];

    /**
     * Get the exporter name.
     *
     * @return string Human-readable name.
     */
    public function get_name(): string {
        return get_string('exporter_config', 'local_edulution');
    }

    /**
     * Get the language string key.
     *
     * @return string Language string key.
     */
    public function get_string_key(): string {
        return 'config';
    }

    /**
     * Get total count for progress tracking.
     *
     * @return int Number of steps.
     */
    public function get_total_count(): int {
        // Steps: Core config, plugin configs, theme settings, write files.
        return 4;
    }

    /**
     * Export configuration settings.
     *
     * @return array Exported configuration data.
     * @throws \moodle_exception On export failure.
     */
    public function export(): array {
        global $CFG, $DB;

        $this->log('info', 'Exporting configuration settings...');

        // Create config subdirectory.
        $configDir = $this->get_subdir('config');

        // Step 1: Export core configuration.
        $this->update_progress(1, 'Exporting core configuration...');
        $coreConfig = $this->export_core_config();

        // Step 2: Export plugin configurations.
        $this->update_progress(2, 'Exporting plugin configurations...');
        $pluginConfigs = $this->export_plugin_configs();

        // Step 3: Export theme settings.
        $this->update_progress(3, 'Exporting theme settings...');
        $themeSettings = [];
        if ($this->options->include_theme_settings) {
            $themeSettings = $this->export_theme_settings();
        }

        // Step 4: Write output files.
        $this->update_progress(4, 'Writing configuration files...');

        // Build main config data.
        $data = [
            'export_timestamp' => date('c'),
            'sanitized' => $this->options->sanitize_config,
            'moodle_version' => $CFG->version,
            'moodle_release' => $CFG->release,
            'core' => $coreConfig,
            'plugins' => $pluginConfigs,
            'theme' => $themeSettings,
            'summary' => [
                'core_settings_count' => count($coreConfig),
                'plugin_configs_count' => count($pluginConfigs),
                'theme_settings_exported' => !empty($themeSettings),
                'sensitive_values_hidden' => $this->options->sanitize_config ? count($this->get_hidden_settings()) : 0,
            ],
        ];

        // Write main config file.
        $this->write_json($data, 'config/settings.json');

        // Write core config separately.
        $this->write_json([
            'export_timestamp' => date('c'),
            'settings' => $coreConfig,
        ], 'config/core.json');

        // Write plugin configs.
        $this->write_json([
            'export_timestamp' => date('c'),
            'plugins' => $pluginConfigs,
        ], 'config/plugins.json');

        // Write theme settings if exported.
        if (!empty($themeSettings)) {
            $this->write_json([
                'export_timestamp' => date('c'),
                'theme' => $themeSettings,
            ], 'config/theme.json');
        }

        // Update statistics.
        $this->stats = [
            'core_settings' => count($coreConfig),
            'plugin_configs' => count($pluginConfigs),
            'theme_settings' => count($themeSettings),
            'sanitized' => $this->options->sanitize_config,
        ];

        $this->log('info', sprintf(
            'Configuration export complete: %d core settings, %d plugin configs',
            $this->stats['core_settings'],
            $this->stats['plugin_configs']
        ));

        return $data;
    }

    /**
     * Export core Moodle configuration.
     *
     * @return array Core configuration settings.
     */
    protected function export_core_config(): array {
        global $DB;

        // Get all config settings without plugin prefix.
        $records = $DB->get_records('config', null, 'name ASC');

        $settings = [];
        foreach ($records as $record) {
            $value = $record->value;

            // Sanitize sensitive values.
            if ($this->options->sanitize_config && $this->is_sensitive_setting($record->name)) {
                $value = '[HIDDEN]';
            }

            $settings[$record->name] = $value;
        }

        return $settings;
    }

    /**
     * Export plugin configurations.
     *
     * @return array Plugin configuration settings grouped by plugin.
     */
    protected function export_plugin_configs(): array {
        global $DB;

        // Get all config settings with plugin prefix.
        $records = $DB->get_records('config_plugins', null, 'plugin ASC, name ASC');

        $plugins = [];
        foreach ($records as $record) {
            $plugin = $record->plugin;
            $name = $record->name;
            $value = $record->value;

            // Sanitize sensitive values.
            if ($this->options->sanitize_config && $this->is_sensitive_setting($name, $plugin)) {
                $value = '[HIDDEN]';
            }

            if (!isset($plugins[$plugin])) {
                $plugins[$plugin] = [];
            }

            $plugins[$plugin][$name] = $value;
        }

        return $plugins;
    }

    /**
     * Export theme settings.
     *
     * @return array Theme settings.
     */
    protected function export_theme_settings(): array {
        global $CFG, $DB;

        $themeSettings = [];

        // Get current theme.
        $currentTheme = $CFG->theme ?? 'boost';
        $themeSettings['current_theme'] = $currentTheme;

        // Get theme config from config_plugins.
        $themeConfigs = $DB->get_records('config_plugins', ['plugin' => 'theme_' . $currentTheme]);

        $settings = [];
        foreach ($themeConfigs as $config) {
            $value = $config->value;

            // Sanitize sensitive values.
            if ($this->options->sanitize_config && $this->is_sensitive_setting($config->name, 'theme_' . $currentTheme)) {
                $value = '[HIDDEN]';
            }

            $settings[$config->name] = $value;
        }

        $themeSettings['settings'] = $settings;

        // Get SCSS variables if available.
        if (method_exists('\theme_config', 'get_scss_property')) {
            try {
                $theme = \theme_config::load($currentTheme);
                if ($theme && method_exists($theme, 'get_settings')) {
                    // Theme settings are already included above.
                }
            } catch (\Exception $e) {
                $this->log('debug', 'Could not load theme settings: ' . $e->getMessage());
            }
        }

        // Export custom CSS if present.
        $customCss = get_config('theme_' . $currentTheme, 'scss');
        if (!empty($customCss)) {
            $themeSettings['custom_scss'] = $customCss;
        }

        $customCss = get_config('theme_' . $currentTheme, 'scsspre');
        if (!empty($customCss)) {
            $themeSettings['custom_scss_pre'] = $customCss;
        }

        // Get branding info.
        $themeSettings['branding'] = [
            'logo' => get_config('core_admin', 'logo') ?: null,
            'logocompact' => get_config('core_admin', 'logocompact') ?: null,
            'favicon' => get_config('core_admin', 'favicon') ?: null,
        ];

        return $themeSettings;
    }

    /**
     * Check if a setting name is sensitive.
     *
     * @param string $name Setting name.
     * @param string|null $plugin Plugin name (optional).
     * @return bool True if sensitive.
     */
    protected function is_sensitive_setting(string $name, ?string $plugin = null): bool {
        $nameLower = strtolower($name);

        // Check exact matches.
        foreach (self::SENSITIVE_SETTINGS as $sensitive) {
            if ($nameLower === strtolower($sensitive)) {
                return true;
            }
        }

        // Check patterns.
        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }

        // Check plugin-specific patterns.
        if ($plugin) {
            $fullName = $plugin . '_' . $name;
            foreach (self::SENSITIVE_PATTERNS as $pattern) {
                if (preg_match($pattern, $fullName)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get list of settings that were hidden due to sensitivity.
     *
     * @return array List of hidden setting names.
     */
    protected function get_hidden_settings(): array {
        global $DB;

        $hidden = [];

        // Check core config.
        $records = $DB->get_records('config');
        foreach ($records as $record) {
            if ($this->is_sensitive_setting($record->name)) {
                $hidden[] = 'core.' . $record->name;
            }
        }

        // Check plugin config.
        $records = $DB->get_records('config_plugins');
        foreach ($records as $record) {
            if ($this->is_sensitive_setting($record->name, $record->plugin)) {
                $hidden[] = $record->plugin . '.' . $record->name;
            }
        }

        return $hidden;
    }

    /**
     * Export site information.
     *
     * @return array Site information.
     */
    public function export_site_info(): array {
        global $CFG, $SITE, $DB;

        return [
            'site_name' => $SITE->fullname ?? '',
            'site_shortname' => $SITE->shortname ?? '',
            'wwwroot' => $CFG->wwwroot,
            'dataroot' => $CFG->dataroot,
            'dirroot' => $CFG->dirroot,
            'admin' => $CFG->admin ?? 'admin',
            'default_lang' => $CFG->lang ?? 'en',
            'default_timezone' => $CFG->timezone ?? 'UTC',
            'moodle_version' => $CFG->version,
            'moodle_release' => $CFG->release,
            'moodle_branch' => $CFG->branch ?? '',
            'php_version' => PHP_VERSION,
            'database' => [
                'type' => $CFG->dbtype,
                'host' => $CFG->dbhost,
                'name' => $CFG->dbname,
                'prefix' => $CFG->prefix,
            ],
        ];
    }

    /**
     * Export specific admin tree settings.
     *
     * @param string $section Admin tree section name.
     * @return array Settings from the section.
     */
    public function export_admin_section(string $section): array {
        global $DB;

        // This requires loading the admin tree which can be expensive.
        // For now, we'll just return config values that match the section prefix.
        $settings = [];

        // Get from config.
        $records = $DB->get_records_select('config', 'name LIKE ?', [$section . '_%']);
        foreach ($records as $record) {
            $value = $record->value;
            if ($this->options->sanitize_config && $this->is_sensitive_setting($record->name)) {
                $value = '[HIDDEN]';
            }
            $settings[$record->name] = $value;
        }

        return $settings;
    }
}
