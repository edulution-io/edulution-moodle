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
 * Plugin information exporter.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

use core_plugin_manager;

/**
 * Exporter for installed plugin information.
 *
 * Lists all installed plugins with versions, gets download URLs from
 * Moodle Plugin Directory API, and creates plugins/installed.json
 * and plugins/sources.json files.
 */
class plugin_exporter extends base_exporter {

    /** @var string Moodle Plugin Directory API URL */
    protected const PLUGIN_DIRECTORY_API = 'https://moodle.org/plugins/api/1.3/get_plugin_info.php';

    /** @var array Cached plugin data */
    protected ?array $plugins_cache = null;

    /**
     * Get the exporter name.
     *
     * @return string Human-readable name.
     */
    public function get_name(): string {
        return get_string('exporter_plugins', 'local_edulution');
    }

    /**
     * Get the language string key.
     *
     * @return string Language string key.
     */
    public function get_string_key(): string {
        return 'plugins';
    }

    /**
     * Get total count for progress tracking.
     *
     * @return int Number of steps.
     */
    public function get_total_count(): int {
        // Steps: Collect plugins, check sources, write files.
        return 3;
    }

    /**
     * Export plugin information.
     *
     * @return array Exported plugin data.
     * @throws \moodle_exception On export failure.
     */
    public function export(): array {
        global $CFG, $DB;

        $this->log('info', 'Exporting plugin information...');

        // Step 1: Collect all plugin information.
        $this->update_progress(1, 'Collecting plugin information...');
        $plugins = $this->collect_plugins();

        // Step 2: Get download sources for additional plugins.
        $this->update_progress(2, 'Checking plugin sources...');
        $sources = $this->get_plugin_sources($plugins);

        // Step 3: Write output files.
        $this->update_progress(3, 'Writing plugin data...');

        // Create plugins subdirectory.
        $pluginsDir = $this->get_subdir('plugins');

        // Build installed.json data.
        $installedData = [
            'export_timestamp' => date('c'),
            'moodle_version' => $CFG->version,
            'moodle_release' => $CFG->release,
            'moodle_branch' => $CFG->branch ?? '',
            'php_version' => PHP_VERSION,
            'database' => [
                'type' => $CFG->dbtype,
                'version' => $this->get_database_version(),
            ],
            'plugins' => $plugins,
            'statistics' => [
                'total_plugins' => count($plugins),
                'core_plugins' => count(array_filter($plugins, fn($p) => $p['is_core'])),
                'additional_plugins' => count(array_filter($plugins, fn($p) => !$p['is_core'])),
            ],
        ];

        $this->write_json($installedData, 'plugins/installed.json');

        // Build sources.json data.
        $sourcesData = [
            'export_timestamp' => date('c'),
            'plugins' => $sources,
            'statistics' => [
                'total_additional' => count($sources),
                'with_download_url' => count(array_filter($sources, fn($p) => !empty($p['download_url']))),
                'manual_install' => count(array_filter($sources, fn($p) => empty($p['download_url']))),
            ],
        ];

        $this->write_json($sourcesData, 'plugins/sources.json');

        // Update statistics.
        $this->stats = [
            'total_plugins' => count($plugins),
            'core_plugins' => $installedData['statistics']['core_plugins'],
            'additional_plugins' => $installedData['statistics']['additional_plugins'],
            'with_download_url' => $sourcesData['statistics']['with_download_url'],
        ];

        $this->log('info', sprintf(
            'Plugin export complete: %d plugins (%d additional, %d with download URLs)',
            $this->stats['total_plugins'],
            $this->stats['additional_plugins'],
            $this->stats['with_download_url']
        ));

        return $installedData;
    }

    /**
     * Collect all installed plugin information.
     *
     * @return array Array of plugin data.
     */
    protected function collect_plugins(): array {
        $pluginManager = core_plugin_manager::instance();
        $allPlugins = $pluginManager->get_plugins();

        $plugins = [];

        foreach ($allPlugins as $type => $typePlugins) {
            foreach ($typePlugins as $name => $pluginInfo) {
                $component = $type . '_' . $name;
                $isCore = $this->is_core_plugin($component, $pluginInfo);

                $pluginData = [
                    'component' => $component,
                    'type' => $type,
                    'name' => $name,
                    'version' => $pluginInfo->versiondb ?? $pluginInfo->versiondisk ?? null,
                    'release' => $pluginInfo->release ?? null,
                    'requires' => $pluginInfo->versionrequires ?? null,
                    'maturity' => $this->get_maturity_string($pluginInfo->maturity ?? null),
                    'is_core' => $isCore,
                    'is_enabled' => $this->is_plugin_enabled($pluginInfo),
                    'source' => $pluginInfo->source ?? ($isCore ? 'core' : 'unknown'),
                    'path' => $pluginInfo->full_path ?? null,
                ];

                // Add dependencies if present.
                if (!empty($pluginInfo->dependencies)) {
                    $pluginData['dependencies'] = $pluginInfo->dependencies;
                }

                // Add supported versions info if available.
                if (isset($pluginInfo->supported)) {
                    $pluginData['supported'] = $pluginInfo->supported;
                }

                $plugins[] = $pluginData;
            }
        }

        // Sort plugins by component name.
        usort($plugins, function ($a, $b) {
            return strcmp($a['component'], $b['component']);
        });

        return $plugins;
    }

    /**
     * Get download sources for additional (non-core) plugins.
     *
     * @param array $plugins All plugins.
     * @return array Source information for additional plugins.
     */
    protected function get_plugin_sources(array $plugins): array {
        $sources = [];

        foreach ($plugins as $plugin) {
            if ($plugin['is_core']) {
                continue;
            }

            $sourceInfo = [
                'component' => $plugin['component'],
                'type' => $plugin['type'],
                'name' => $plugin['name'],
                'version' => $plugin['version'],
                'download_url' => null,
                'source_type' => 'manual',
                'notes' => null,
            ];

            // Try to get download URL from Moodle Plugin Directory.
            $apiInfo = $this->query_plugin_directory($plugin['component']);
            if ($apiInfo) {
                $sourceInfo['download_url'] = $apiInfo['download_url'] ?? null;
                $sourceInfo['source_type'] = 'moodle_plugins_directory';
                $sourceInfo['plugin_directory_url'] = $apiInfo['plugin_url'] ?? null;
                $sourceInfo['latest_version'] = $apiInfo['latest_version'] ?? null;
            }

            $sources[] = $sourceInfo;
        }

        return $sources;
    }

    /**
     * Query Moodle Plugin Directory API for plugin info.
     *
     * @param string $component Plugin component name.
     * @return array|null Plugin info or null if not found.
     */
    protected function query_plugin_directory(string $component): ?array {
        global $CFG;

        // Build API URL.
        $url = self::PLUGIN_DIRECTORY_API . '?plugin=' . urlencode($component);

        // Make API request with timeout.
        $options = [
            'http' => [
                'method' => 'GET',
                'timeout' => 5,
                'header' => 'User-Agent: Moodle/' . $CFG->release,
            ],
        ];

        $context = stream_context_create($options);

        try {
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                return null;
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
                return null;
            }

            // Extract relevant info.
            return [
                'plugin_url' => $data['url'] ?? null,
                'download_url' => $data['version']['downloadurl'] ?? null,
                'latest_version' => $data['version']['version'] ?? null,
            ];

        } catch (\Exception $e) {
            $this->log('debug', "API query failed for {$component}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a plugin is a core Moodle plugin.
     *
     * @param string $component Plugin component name.
     * @param object $pluginInfo Plugin information object.
     * @return bool True if core plugin.
     */
    protected function is_core_plugin(string $component, object $pluginInfo): bool {
        // Check source if available.
        if (isset($pluginInfo->source)) {
            return $pluginInfo->source === 'core';
        }

        // Check if plugin is part of standard Moodle distribution.
        // This is determined by the plugin manager.
        if (method_exists($pluginInfo, 'is_standard')) {
            return $pluginInfo->is_standard();
        }

        // Fallback: check if it's in the standard plugin types.
        $standardTypes = [
            'mod', 'block', 'qtype', 'qformat', 'qbehaviour', 'qbank',
            'auth', 'enrol', 'filter', 'format', 'gradeexport', 'gradeimport',
            'gradereport', 'gradingform', 'report', 'repository', 'portfolio',
            'search', 'message', 'media', 'theme', 'editor', 'atto',
            'assignsubmission', 'assignfeedback', 'booktool', 'datafield',
            'datapreset', 'fileconverter', 'forumreport', 'ltiservice',
            'mlbackend', 'paygw', 'plagiarism', 'profilefield', 'quizaccess',
            'scormreport', 'workshopallocation', 'workshopeval', 'workshopform',
            'availability', 'calendartype', 'contenttype', 'customfield',
            'dataformat', 'tool', 'cachelock', 'cachestore', 'antivirus',
            'webservice', 'tiny', 'communication', 'ai', 'aiplacement',
        ];

        $type = explode('_', $component)[0];
        return in_array($type, $standardTypes);
    }

    /**
     * Check if a plugin is enabled.
     *
     * @param object $pluginInfo Plugin information object.
     * @return bool True if enabled.
     */
    protected function is_plugin_enabled(object $pluginInfo): bool {
        if (method_exists($pluginInfo, 'is_enabled')) {
            $result = $pluginInfo->is_enabled();
            return $result !== false && $result !== null;
        }
        return true;
    }

    /**
     * Get maturity level as string.
     *
     * @param int|null $maturity Maturity constant.
     * @return string Maturity string.
     */
    protected function get_maturity_string(?int $maturity): string {
        if ($maturity === null) {
            return 'unknown';
        }

        $levels = [
            MATURITY_ALPHA => 'alpha',
            MATURITY_BETA => 'beta',
            MATURITY_RC => 'rc',
            MATURITY_STABLE => 'stable',
        ];

        return $levels[$maturity] ?? 'unknown';
    }

    /**
     * Get database version.
     *
     * @return string Database version string.
     */
    protected function get_database_version(): string {
        global $DB, $CFG;

        try {
            if ($CFG->dbtype === 'mysqli' || $CFG->dbtype === 'mariadb') {
                return $DB->get_server_info();
            } elseif ($CFG->dbtype === 'pgsql') {
                $result = $DB->get_record_sql("SELECT version()");
                return $result ? $result->version : 'unknown';
            }
        } catch (\Exception $e) {
            return 'unknown';
        }

        return 'unknown';
    }

    /**
     * Compare exported plugins with target system.
     *
     * @param array $targetPlugins List of target plugin components.
     * @return array Comparison results.
     */
    public function compare_with_target(array $targetPlugins): array {
        $exported = $this->collect_plugins();
        $exportedComponents = array_column($exported, 'component');

        $compatible = [];
        $missing = [];
        $extra = [];

        // Find plugins in both.
        foreach ($exported as $plugin) {
            if (in_array($plugin['component'], $targetPlugins)) {
                $compatible[] = $plugin;
            } elseif (!$plugin['is_core']) {
                $extra[] = $plugin;
            }
        }

        // Find plugins in target but not in source.
        foreach ($targetPlugins as $target) {
            if (!in_array($target, $exportedComponents)) {
                $missing[] = ['component' => $target];
            }
        }

        return [
            'compatible' => $compatible,
            'missing' => $missing,
            'extra' => $extra,
            'is_compatible' => count($missing) === 0,
            'compatibility_score' => count($targetPlugins) > 0
                ? round((count($compatible) / count($targetPlugins)) * 100, 1)
                : 100,
        ];
    }

    /**
     * Get list of additional (non-core) plugins only.
     *
     * @return array Additional plugins.
     */
    public function get_additional_plugins(): array {
        $plugins = $this->collect_plugins();
        return array_filter($plugins, fn($p) => !$p['is_core']);
    }
}
