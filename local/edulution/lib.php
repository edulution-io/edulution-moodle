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
 * Core library functions for local_edulution.
 *
 * This file contains navigation hooks, helper functions, and constants
 * for the Edulution plugin.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Plugin component name.
 */
define('LOCAL_EDULUTION_COMPONENT', 'local_edulution');

/**
 * Called after config.php is loaded.
 *
 * This is the earliest hook available for plugins. Used for cookie-based SSO.
 */
function local_edulution_after_config() {
    global $CFG, $SESSION;

    // Skip during installation/upgrade.
    if (during_initial_install() || !empty($CFG->upgraderunning)) {
        return;
    }

    // Skip for CLI scripts.
    if (CLI_SCRIPT) {
        return;
    }

    // Skip for certain paths.
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $skip_paths = ['/login/', '/logout.php', '/admin/cron.php', '/lib/ajax/'];
    foreach ($skip_paths as $path) {
        if (strpos($script, $path) !== false) {
            return;
        }
    }

    // Try cookie-based auto-login.
    try {
        require_once($CFG->dirroot . '/local/edulution/classes/auth/cookie_auth_backend.php');
        $auth = new \local_edulution\auth\cookie_auth_backend();
        $auth->try_auto_login();
    } catch (\Exception $e) {
        // Silently fail - don't break the page.
        debugging('[Edulution Cookie Auth] Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}

/**
 * Default export retention days.
 */
define('LOCAL_EDULUTION_DEFAULT_RETENTION_DAYS', 30);

/**
 * Maximum file size for imports (100MB).
 */
define('LOCAL_EDULUTION_MAX_IMPORT_SIZE', 104857600);

/**
 * Export format: JSON.
 */
define('LOCAL_EDULUTION_FORMAT_JSON', 'json');

/**
 * Export format: CSV.
 */
define('LOCAL_EDULUTION_FORMAT_CSV', 'csv');

/**
 * Export format: XML.
 */
define('LOCAL_EDULUTION_FORMAT_XML', 'xml');

/**
 * Extends the navigation with Edulution links.
 *
 * This function is called by Moodle to extend the navigation tree.
 *
 * @param global_navigation $navigation The global navigation object.
 * @return void
 */
function local_edulution_extend_navigation(global_navigation $navigation) {
    // Navigation is handled through settings.php for admin pages.
}

/**
 * Extends the settings navigation with Edulution settings.
 *
 * This function adds Edulution-specific settings to the settings navigation
 * block when viewing the plugin pages.
 *
 * @param settings_navigation $settingsnav The settings navigation object.
 * @param context $context The context object.
 * @return void
 */
function local_edulution_extend_settings_navigation(settings_navigation $settingsnav, context $context) {
    global $PAGE;

    // Only add settings for users with management capability.
    if (!has_capability('local/edulution:manage', context_system::instance())) {
        return;
    }

    // Check if we're on an Edulution page.
    if (strpos($PAGE->url->get_path(), '/local/edulution/') === false) {
        return;
    }

    $settingnode = $settingsnav->find('root', navigation_node::TYPE_SITE_ADMIN);
    if ($settingnode) {
        $edulutionnode = $settingnode->add(
            get_string('pluginname', 'local_edulution'),
            new moodle_url('/local/edulution/index.php'),
            navigation_node::TYPE_CONTAINER,
            null,
            'local_edulution'
        );

        if ($edulutionnode) {
            $edulutionnode->add(
                get_string('dashboard', 'local_edulution'),
                new moodle_url('/local/edulution/index.php'),
                navigation_node::TYPE_SETTING
            );

            if (has_capability('local/edulution:export', context_system::instance())) {
                $edulutionnode->add(
                    get_string('export', 'local_edulution'),
                    new moodle_url('/local/edulution/export.php'),
                    navigation_node::TYPE_SETTING
                );
            }

            if (has_capability('local/edulution:import', context_system::instance())) {
                $edulutionnode->add(
                    get_string('import', 'local_edulution'),
                    new moodle_url('/local/edulution/import.php'),
                    navigation_node::TYPE_SETTING
                );
            }

            if (has_capability('local/edulution:sync', context_system::instance())) {
                $edulutionnode->add(
                    get_string('keycloaksync', 'local_edulution'),
                    new moodle_url('/local/edulution/sync.php'),
                    navigation_node::TYPE_SETTING
                );
            }
        }
    }
}

/**
 * Format file size into human-readable string.
 *
 * @param int $bytes The size in bytes.
 * @param int $precision Number of decimal places.
 * @return string Formatted file size string.
 */
function local_edulution_format_filesize($bytes, $precision = 2) {
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $base = log($bytes, 1024);
    $index = floor($base);

    if ($index >= count($units)) {
        $index = count($units) - 1;
    }

    return round(pow(1024, $base - $index), $precision) . ' ' . $units[$index];
}

/**
 * Get the export directory path.
 *
 * @return string The export directory path.
 */
function local_edulution_get_export_path() {
    global $CFG;

    $path = get_config('local_edulution', 'export_path');
    if (empty($path)) {
        $path = $CFG->dataroot . '/edulution/exports';
    }

    return $path;
}

/**
 * Get the import directory path.
 *
 * @return string The import directory path.
 */
function local_edulution_get_import_path() {
    global $CFG;

    $path = get_config('local_edulution', 'import_path');
    if (empty($path)) {
        $path = $CFG->dataroot . '/edulution/imports';
    }

    return $path;
}

/**
 * Ensure directory exists and is writable.
 *
 * @param string $path The directory path to check/create.
 * @return bool True if directory exists and is writable, false otherwise.
 */
function local_edulution_ensure_directory($path) {
    if (!file_exists($path)) {
        if (!mkdir($path, 0755, true)) {
            return false;
        }
    }

    return is_dir($path) && is_writable($path);
}

/**
 * Generate a unique filename for exports.
 *
 * @param string $prefix Filename prefix.
 * @param string $extension File extension (without dot).
 * @return string Generated filename.
 */
function local_edulution_generate_export_filename($prefix, $extension = 'json') {
    $timestamp = date('Y-m-d_His');
    $random = substr(md5(uniqid(mt_rand(), true)), 0, 8);

    return "{$prefix}_{$timestamp}_{$random}.{$extension}";
}

/**
 * Check if the plugin is enabled.
 *
 * @return bool True if plugin is enabled, false otherwise.
 */
function local_edulution_is_enabled() {
    return (bool) get_config('local_edulution', 'enabled');
}

/**
 * Check if Keycloak sync is enabled.
 *
 * @return bool True if Keycloak sync is enabled, false otherwise.
 */
function local_edulution_is_keycloak_sync_enabled() {
    return (bool) get_config('local_edulution', 'keycloak_sync_enabled');
}

/**
 * Get the export retention days setting.
 *
 * @return int Number of days to retain exports.
 */
function local_edulution_get_retention_days() {
    $days = get_config('local_edulution', 'export_retention_days');
    if (empty($days) || $days < 1) {
        return LOCAL_EDULUTION_DEFAULT_RETENTION_DAYS;
    }
    return (int) $days;
}

/**
 * Clean up old export files.
 *
 * Removes export files older than the configured retention period.
 *
 * @return int Number of files deleted.
 */
function local_edulution_cleanup_old_exports() {
    $exportpath = local_edulution_get_export_path();
    $retentiondays = local_edulution_get_retention_days();
    $cutofftime = time() - ($retentiondays * 86400);
    $deleted = 0;

    if (!is_dir($exportpath)) {
        return 0;
    }

    $files = glob($exportpath . '/*');
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutofftime) {
            if (unlink($file)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

/**
 * Log an action to the Moodle event system.
 *
 * @param string $action The action name.
 * @param array $data Additional data to log.
 * @param int|null $userid The user ID (null for current user).
 * @return void
 */
function local_edulution_log_action($action, array $data = [], $userid = null) {
    global $USER;

    if ($userid === null) {
        $userid = $USER->id;
    }

    // Event logging will be implemented through proper Moodle events.
    // This is a placeholder for the event system integration.
    debugging("Edulution action logged: {$action}", DEBUG_DEVELOPER);
}

/**
 * Callback function for plugin file serving.
 *
 * This function handles file requests for the plugin.
 *
 * @param stdClass $course The course object.
 * @param stdClass $cm The course module object.
 * @param context $context The context object.
 * @param string $filearea The file area.
 * @param array $args Extra arguments.
 * @param bool $forcedownload Force download flag.
 * @param array $options Additional options.
 * @return bool|void
 */
function local_edulution_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $CFG;

    require_login();

    // Check capability.
    if (!has_capability('local/edulution:export', $context)) {
        return false;
    }

    $fs = get_file_storage();
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $file = $fs->get_file($context->id, 'local_edulution', $filearea, 0, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Render the navigation bar for Edulution pages.
 *
 * @param string $active The active page identifier.
 * @return string HTML for the navigation bar.
 */
function local_edulution_render_nav(string $active = 'dashboard'): string {
    $context = context_system::instance();

    // Check if Keycloak is configured.
    $keycloakurl = local_edulution_get_config('keycloak_url');
    $isconfigured = !empty($keycloakurl);

    $items = [];

    // Dashboard/Sync - main page.
    $items[] = [
        'id' => 'dashboard',
        'url' => new moodle_url('/local/edulution/dashboard.php'),
        'label' => 'Sync',
        'icon' => 'fa-refresh',
    ];

    // Setup.
    $items[] = [
        'id' => 'setup',
        'url' => new moodle_url('/local/edulution/setup.php'),
        'label' => 'Setup',
        'icon' => 'fa-cog',
    ];

    // Export.
    if (has_capability('local/edulution:export', $context)) {
        $items[] = [
            'id' => 'export',
            'url' => new moodle_url('/local/edulution/export.php'),
            'label' => 'Export',
            'icon' => 'fa-download',
        ];
    }

    // Import.
    if (has_capability('local/edulution:import', $context)) {
        $items[] = [
            'id' => 'import',
            'url' => new moodle_url('/local/edulution/import.php'),
            'label' => 'Import',
            'icon' => 'fa-upload',
        ];
    }

    // Reports.
    $items[] = [
        'id' => 'reports',
        'url' => new moodle_url('/local/edulution/pages/reports.php'),
        'label' => 'Berichte',
        'icon' => 'fa-list',
    ];

    // Docs.
    $items[] = [
        'id' => 'docs',
        'url' => new moodle_url('/local/edulution/pages/schema_docs.php'),
        'label' => 'Hilfe',
        'icon' => 'fa-question-circle',
    ];

    // Build HTML.
    $html = '<nav class="edulution-nav mb-3" style="background: #f8f9fa; border-radius: 4px; padding: 4px 8px;">';
    $html .= '<ul class="nav nav-pills nav-sm" style="margin: 0;">';
    foreach ($items as $item) {
        $activeclass = ($item['id'] === $active) ? ' active' : '';
        $html .= '<li class="nav-item">';
        $html .= '<a class="nav-link' . $activeclass . '" href="' . $item['url'] . '">';
        $html .= '<i class="fa ' . $item['icon'] . '"></i> ' . $item['label'];
        $html .= '</a></li>';
    }
    $html .= '</ul></nav>';

    return $html;
}

/**
 * Get system status information.
 *
 * @return array Status information.
 */
function local_edulution_get_system_status(): array {
    global $DB, $CFG;

    $status = [
        'users' => $DB->count_records('user', ['deleted' => 0]),
        'courses' => $DB->count_records('course') - 1, // Exclude site course.
        'keycloak_connected' => false,
        'keycloak_status' => get_string('not_configured', 'local_edulution'),
        'disk_total' => 0,
        'disk_free' => 0,
        'disk_used' => 0,
    ];

    // Check Keycloak configuration.
    $keycloakUrl = get_config('local_edulution', 'keycloak_url');
    if (!empty($keycloakUrl)) {
        $status['keycloak_status'] = get_string('disconnected', 'local_edulution');
    }

    // Get disk space for dataroot.
    if (function_exists('disk_total_space') && function_exists('disk_free_space')) {
        $status['disk_total'] = @disk_total_space($CFG->dataroot);
        $status['disk_free'] = @disk_free_space($CFG->dataroot);
        if ($status['disk_total'] && $status['disk_free']) {
            $status['disk_used'] = $status['disk_total'] - $status['disk_free'];
        }
    }

    return $status;
}

/**
 * Get last sync time.
 *
 * @return int|null Timestamp of last sync or null.
 */
function local_edulution_get_last_sync_time(): ?int {
    global $DB;

    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('local_edulution_sync_log')) {
        return null;
    }

    $record = $DB->get_record_sql(
        "SELECT MAX(timecreated) as lasttime FROM {local_edulution_sync_log}",
        [],
        IGNORE_MISSING
    );

    return $record && $record->lasttime ? (int)$record->lasttime : null;
}

/**
 * Get recent activity for the dashboard.
 *
 * @param int $limit Number of records to return.
 * @return array Recent activity records.
 */
function local_edulution_get_recent_activity(int $limit = 10): array {
    global $DB;

    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('local_edulution_activity')) {
        return [];
    }

    $records = $DB->get_records('local_edulution_activity', [], 'timecreated DESC', '*', 0, $limit);
    return array_values($records);
}

/**
 * Log an activity.
 *
 * @param string $type Activity type.
 * @param string $description Activity description.
 * @param string $status Activity status.
 * @param array $data Additional data.
 */
function local_edulution_log_activity_record(string $type, string $description, string $status = 'success', array $data = []): void {
    global $DB, $USER;

    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('local_edulution_activity')) {
        return;
    }

    $record = new stdClass();
    $record->type = $type;
    $record->description = $description;
    $record->status = $status;
    $record->data = json_encode($data);
    $record->userid = $USER->id ?? 0;
    $record->timecreated = time();

    $DB->insert_record('local_edulution_activity', $record);
}

/**
 * Get export history.
 *
 * @param int $limit Number of records to return.
 * @param int $offset Offset for pagination.
 * @return array Export history records.
 */
function local_edulution_get_export_history(int $limit = 50, int $offset = 0): array {
    global $DB;

    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('local_edulution_exports')) {
        return [];
    }

    $records = $DB->get_records('local_edulution_exports', [], 'timecreated DESC', '*', $offset, $limit);
    return array_values($records);
}

/**
 * Get sync history.
 *
 * @param int $limit Number of records to return.
 * @param int $offset Offset for pagination.
 * @return array Sync history records.
 */
function local_edulution_get_sync_history(int $limit = 50, int $offset = 0): array {
    global $DB;

    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('local_edulution_sync_log')) {
        return [];
    }

    $records = $DB->get_records('local_edulution_sync_log', [], 'timecreated DESC', '*', $offset, $limit);
    return array_values($records);
}

/**
 * Get course categories for select list.
 *
 * @return array Categories indexed by ID.
 */
function local_edulution_get_categories_list(): array {
    global $DB;

    $categories = $DB->get_records('course_categories', [], 'sortorder', 'id, name, parent, depth');
    $result = [];

    foreach ($categories as $cat) {
        $indent = str_repeat('-- ', $cat->depth);
        $result[$cat->id] = $indent . $cat->name;
    }

    return $result;
}

/**
 * Get courses for select list.
 *
 * @param int|null $categoryid Filter by category.
 * @return array Courses indexed by ID.
 */
function local_edulution_get_courses_list(?int $categoryid = null): array {
    global $DB;

    $conditions = [];
    if ($categoryid !== null) {
        $conditions['category'] = $categoryid;
    }

    $courses = $DB->get_records('course', $conditions, 'fullname', 'id, fullname, shortname');
    $result = [];

    foreach ($courses as $course) {
        if ($course->id == SITEID) {
            continue;
        }
        $result[$course->id] = $course->fullname . ' (' . $course->shortname . ')';
    }

    return $result;
}

/**
 * Environment variable prefix for configuration.
 */
define('LOCAL_EDULUTION_ENV_PREFIX', 'EDULUTION_');

/**
 * Mapping of config keys to environment variable names.
 * Environment variables always take precedence over database values.
 */
define('LOCAL_EDULUTION_ENV_CONFIG_MAP', [
    'keycloak_url' => 'EDULUTION_KEYCLOAK_URL',
    'keycloak_realm' => 'EDULUTION_KEYCLOAK_REALM',
    'keycloak_client_id' => 'EDULUTION_KEYCLOAK_CLIENT_ID',
    'keycloak_client_secret' => 'EDULUTION_KEYCLOAK_CLIENT_SECRET',
    'keycloak_sync_enabled' => 'EDULUTION_KEYCLOAK_SYNC_ENABLED',
    'verify_ssl' => 'EDULUTION_VERIFY_SSL',
    'sync_create_users' => 'EDULUTION_SYNC_CREATE_USERS',
    'sync_update_users' => 'EDULUTION_SYNC_UPDATE_USERS',
    'sync_suspend_users' => 'EDULUTION_SYNC_SUSPEND_USERS',
    'cookie_auth_enabled' => 'EDULUTION_COOKIE_AUTH_ENABLED',
    'cookie_auth_cookie_name' => 'EDULUTION_COOKIE_AUTH_COOKIE_NAME',
]);

/**
 * Get a plugin configuration value with environment variable override.
 *
 * This function first checks if an environment variable is set for the given
 * config key. If set, the environment variable value takes precedence over
 * any database value. This is useful for Docker/container deployments.
 *
 * @param string $name The configuration key name.
 * @param mixed $default Default value if neither env nor db value exists.
 * @return mixed The configuration value.
 */
function local_edulution_get_config(string $name, $default = null) {
    // Check if there's an environment variable mapping for this config.
    $envMap = LOCAL_EDULUTION_ENV_CONFIG_MAP;

    if (isset($envMap[$name])) {
        $envValue = getenv($envMap[$name]);
        if ($envValue !== false && $envValue !== '') {
            // Handle boolean values from env vars.
            if (in_array(strtolower($envValue), ['true', '1', 'yes', 'on'])) {
                return true;
            }
            if (in_array(strtolower($envValue), ['false', '0', 'no', 'off'])) {
                return false;
            }
            return $envValue;
        }
    }

    // Fall back to database config.
    $value = get_config('local_edulution', $name);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return $value;
}

/**
 * Check if a config value comes from an environment variable.
 *
 * Useful for displaying in the admin UI whether a value is overridden.
 *
 * @param string $name The configuration key name.
 * @return bool True if the value comes from an environment variable.
 */
function local_edulution_config_from_env(string $name): bool {
    $envMap = LOCAL_EDULUTION_ENV_CONFIG_MAP;

    if (isset($envMap[$name])) {
        $envValue = getenv($envMap[$name]);
        return $envValue !== false && $envValue !== '';
    }

    return false;
}

/**
 * Get all environment-based configuration values.
 *
 * Returns an array of config keys that are currently set via environment variables.
 *
 * @return array Array of config keys that have env var overrides.
 */
function local_edulution_get_env_configs(): array {
    $envConfigs = [];
    $envMap = LOCAL_EDULUTION_ENV_CONFIG_MAP;

    foreach ($envMap as $configKey => $envVar) {
        $envValue = getenv($envVar);
        if ($envValue !== false && $envValue !== '') {
            $envConfigs[$configKey] = $envVar;
        }
    }

    return $envConfigs;
}

/**
 * Check if Keycloak is configured.
 *
 * @return bool True if configured.
 */
function local_edulution_is_keycloak_configured(): bool {
    $url = local_edulution_get_config('keycloak_url');
    $realm = local_edulution_get_config('keycloak_realm');
    $clientId = local_edulution_get_config('keycloak_client_id');

    return !empty($url) && !empty($realm) && !empty($clientId);
}

/**
 * Update the sync task schedule based on the sync_interval setting.
 *
 * This should be called when the sync_interval setting is changed.
 *
 * @return bool True if updated successfully.
 */
function local_edulution_update_sync_schedule(): bool {
    global $DB;

    $interval = get_config('local_edulution', 'sync_interval');
    if (empty($interval)) {
        $interval = 30; // Default 30 minutes.
    }

    // Calculate cron expression based on interval.
    $minute = '*';
    $hour = '*';

    if ($interval < 60) {
        // Less than an hour: run every X minutes.
        $minute = "*/{$interval}";
        $hour = '*';
    } else if ($interval == 60) {
        // Hourly: run at minute 0 every hour.
        $minute = '0';
        $hour = '*';
    } else if ($interval == 360) {
        // Every 6 hours.
        $minute = '0';
        $hour = '*/6';
    } else if ($interval == 720) {
        // Every 12 hours.
        $minute = '0';
        $hour = '*/12';
    } else if ($interval >= 1440) {
        // Daily: run at 4:00 AM.
        $minute = '0';
        $hour = '4';
    }

    // Update the scheduled task.
    $task = $DB->get_record('task_scheduled', ['component' => 'local_edulution', 'classname' => '\local_edulution\task\sync_users_task']);

    if ($task) {
        $task->minute = $minute;
        $task->hour = $hour;
        $task->customised = 1;
        $DB->update_record('task_scheduled', $task);
        return true;
    }

    return false;
}

/**
 * Callback when plugin settings are updated.
 *
 * @param string $name Setting name.
 * @return void
 */
function local_edulution_config_updated($name): void {
    if ($name === 'sync_interval' || $name === 'local_edulution/sync_interval') {
        local_edulution_update_sync_schedule();
    }
}

/**
 * Get the last export information.
 *
 * @return array|null Last export info or null.
 */
function local_edulution_get_last_export(): ?array {
    $exportPath = local_edulution_get_export_path();

    if (!is_dir($exportPath)) {
        return null;
    }

    $files = glob($exportPath . '/*.zip');
    if (empty($files)) {
        return null;
    }

    // Sort by modification time descending.
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });

    $lastFile = $files[0];

    return [
        'filename' => basename($lastFile),
        'path' => $lastFile,
        'size' => filesize($lastFile),
        'time' => filemtime($lastFile),
    ];
}
