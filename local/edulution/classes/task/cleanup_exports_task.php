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
 * Scheduled task to cleanup old export files.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Cleanup old export files task.
 */
class cleanup_exports_task extends \core\task\scheduled_task
{

    /**
     * Get task name.
     *
     * @return string Task name.
     */
    public function get_name(): string
    {
        return get_string('task_cleanup_exports', 'local_edulution');
    }

    /**
     * Execute the task.
     */
    public function execute(): void
    {
        global $CFG;

        mtrace('Cleaning up old export files...');

        $export_dir = $CFG->tempdir . '/edulution_exports';
        if (!is_dir($export_dir)) {
            mtrace('No export directory found. Nothing to clean up.');
            return;
        }

        // Delete files older than 7 days.
        $max_age = 7 * 24 * 60 * 60; // 7 days in seconds.
        $now = time();
        $deleted = 0;

        $files = glob($export_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                $age = $now - filemtime($file);
                if ($age > $max_age) {
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }

        mtrace("Deleted {$deleted} old export file(s).");
    }
}
