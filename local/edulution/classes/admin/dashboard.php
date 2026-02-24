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

namespace local_edulution\admin;

defined('MOODLE_INTERNAL') || die();

/**
 * Dashboard data provider class for local_edulution.
 *
 * This class provides data and statistics for the Edulution dashboard,
 * including system status, recent activity, and quick action links.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard {

    /**
     * Get the complete dashboard data.
     *
     * @return array Dashboard data including stats, status, and activities.
     */
    public function get_dashboard_data(): array {
        return [
            'stats' => $this->get_stats(),
            'status' => $this->get_system_status(),
            'recent_exports' => $this->get_recent_exports(),
            'recent_imports' => $this->get_recent_imports(),
            'recent_syncs' => $this->get_recent_syncs(),
            'quick_actions' => $this->get_quick_actions(),
        ];
    }

    /**
     * Get system statistics.
     *
     * @return array Statistics data.
     */
    public function get_stats(): array {
        global $DB;

        return [
            'total_users' => $DB->count_records('user', ['deleted' => 0]),
            'total_courses' => $DB->count_records('course') - 1, // Exclude site course.
            'total_enrolments' => $DB->count_records('user_enrolments'),
            'active_users' => $this->get_active_users_count(),
            'exports_count' => $this->get_exports_count(),
            'imports_count' => $this->get_imports_count(),
        ];
    }

    /**
     * Get count of active users in the last 30 days.
     *
     * @return int Number of active users.
     */
    protected function get_active_users_count(): int {
        global $DB;

        $cutoff = time() - (30 * 86400);
        return $DB->count_records_select(
            'user',
            'deleted = 0 AND lastaccess > ?',
            [$cutoff]
        );
    }

    /**
     * Get count of export files.
     *
     * @return int Number of export files.
     */
    protected function get_exports_count(): int {
        $exportpath = \local_edulution_get_export_path();
        if (!is_dir($exportpath)) {
            return 0;
        }

        $files = glob($exportpath . '/*');
        return is_array($files) ? count($files) : 0;
    }

    /**
     * Get count of import files.
     *
     * @return int Number of import files.
     */
    protected function get_imports_count(): int {
        $importpath = \local_edulution_get_import_path();
        if (!is_dir($importpath)) {
            return 0;
        }

        $files = glob($importpath . '/*');
        return is_array($files) ? count($files) : 0;
    }

    /**
     * Get system status information.
     *
     * @return array System status data.
     */
    public function get_system_status(): array {
        $exportpath = \local_edulution_get_export_path();
        $importpath = \local_edulution_get_import_path();

        return [
            'plugin_enabled' => \local_edulution_is_enabled(),
            'keycloak_sync_enabled' => \local_edulution_is_keycloak_sync_enabled(),
            'export_path_writable' => is_dir($exportpath) && is_writable($exportpath),
            'import_path_writable' => is_dir($importpath) && is_writable($importpath),
            'export_path' => $exportpath,
            'import_path' => $importpath,
            'retention_days' => \local_edulution_get_retention_days(),
            'last_keycloak_sync' => $this->get_last_sync_time('keycloak'),
            'last_cleanup' => $this->get_last_sync_time('cleanup'),
        ];
    }

    /**
     * Get the last sync time for a specific task.
     *
     * @param string $type The sync type (keycloak, cleanup).
     * @return int|null Timestamp of last sync or null if never run.
     */
    protected function get_last_sync_time(string $type): ?int {
        global $DB;

        $classname = match ($type) {
            'keycloak' => '\local_edulution\task\keycloak_sync_task',
            'cleanup' => '\local_edulution\task\cleanup_exports_task',
            default => null,
        };

        if ($classname === null) {
            return null;
        }

        $task = $DB->get_record('task_scheduled', ['classname' => $classname]);
        if ($task && $task->lastruntime > 0) {
            return (int) $task->lastruntime;
        }

        return null;
    }

    /**
     * Get recent export activities.
     *
     * @param int $limit Maximum number of items to return.
     * @return array List of recent exports.
     */
    public function get_recent_exports(int $limit = 5): array {
        $exportpath = \local_edulution_get_export_path();
        if (!is_dir($exportpath)) {
            return [];
        }

        $files = glob($exportpath . '/*');
        if (empty($files)) {
            return [];
        }

        // Sort by modification time descending.
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $exports = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            if (is_file($file)) {
                $exports[] = [
                    'filename' => basename($file),
                    'size' => \local_edulution_format_filesize(filesize($file)),
                    'date' => filemtime($file),
                    'date_formatted' => userdate(filemtime($file)),
                ];
            }
        }

        return $exports;
    }

    /**
     * Get recent import activities.
     *
     * @param int $limit Maximum number of items to return.
     * @return array List of recent imports.
     */
    public function get_recent_imports(int $limit = 5): array {
        $importpath = \local_edulution_get_import_path();
        if (!is_dir($importpath)) {
            return [];
        }

        $files = glob($importpath . '/*');
        if (empty($files)) {
            return [];
        }

        // Sort by modification time descending.
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $imports = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            if (is_file($file)) {
                $imports[] = [
                    'filename' => basename($file),
                    'size' => \local_edulution_format_filesize(filesize($file)),
                    'date' => filemtime($file),
                    'date_formatted' => userdate(filemtime($file)),
                ];
            }
        }

        return $imports;
    }

    /**
     * Get recent Keycloak sync activities.
     *
     * @param int $limit Maximum number of items to return.
     * @return array List of recent syncs.
     */
    public function get_recent_syncs(int $limit = 5): array {
        global $DB;

        // Query task log for recent sync runs.
        $sql = "SELECT tl.id, tl.timestart, tl.timeend, tl.result
                FROM {task_log} tl
                WHERE tl.classname = ?
                ORDER BY tl.timestart DESC";

        $records = $DB->get_records_sql($sql, ['\local_edulution\task\keycloak_sync_task'], 0, $limit);

        $syncs = [];
        foreach ($records as $record) {
            $syncs[] = [
                'id' => $record->id,
                'start_time' => (int) $record->timestart,
                'end_time' => (int) $record->timeend,
                'duration' => $record->timeend - $record->timestart,
                'result' => $record->result,
                'success' => $record->result == 0,
                'date_formatted' => userdate($record->timestart),
            ];
        }

        return $syncs;
    }

    /**
     * Get quick action links for the dashboard.
     *
     * @return array List of quick actions with URLs and capabilities.
     */
    public function get_quick_actions(): array {
        $context = \context_system::instance();

        $actions = [];

        if (has_capability('local/edulution:export', $context)) {
            $actions[] = [
                'name' => 'export',
                'title' => get_string('export', 'local_edulution'),
                'url' => new \moodle_url('/local/edulution/export.php'),
                'icon' => 'i/export',
            ];
        }

        if (has_capability('local/edulution:import', $context)) {
            $actions[] = [
                'name' => 'import',
                'title' => get_string('import', 'local_edulution'),
                'url' => new \moodle_url('/local/edulution/import.php'),
                'icon' => 'i/import',
            ];
        }

        if (has_capability('local/edulution:sync', $context)) {
            $actions[] = [
                'name' => 'sync',
                'title' => get_string('runsync', 'local_edulution'),
                'url' => new \moodle_url('/local/edulution/sync.php'),
                'icon' => 'i/reload',
            ];
        }

        if (has_capability('local/edulution:viewreports', $context)) {
            $actions[] = [
                'name' => 'reports',
                'title' => get_string('viewreports', 'local_edulution'),
                'url' => new \moodle_url('/local/edulution/reports.php'),
                'icon' => 'i/report',
            ];
        }

        if (has_capability('local/edulution:manage', $context)) {
            $actions[] = [
                'name' => 'settings',
                'title' => get_string('settings'),
                'url' => new \moodle_url('/admin/settings.php', ['section' => 'local_edulution_keycloak']),
                'icon' => 'i/settings',
            ];
        }

        return $actions;
    }

    /**
     * Get storage usage information.
     *
     * @return array Storage usage data.
     */
    public function get_storage_info(): array {
        $exportpath = \local_edulution_get_export_path();
        $importpath = \local_edulution_get_import_path();

        return [
            'export_size' => $this->get_directory_size($exportpath),
            'export_size_formatted' => \local_edulution_format_filesize($this->get_directory_size($exportpath)),
            'import_size' => $this->get_directory_size($importpath),
            'import_size_formatted' => \local_edulution_format_filesize($this->get_directory_size($importpath)),
        ];
    }

    /**
     * Calculate total size of files in a directory.
     *
     * @param string $path Directory path.
     * @return int Total size in bytes.
     */
    protected function get_directory_size(string $path): int {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $files = glob($path . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    $size += filesize($file);
                }
            }
        }

        return $size;
    }
}
