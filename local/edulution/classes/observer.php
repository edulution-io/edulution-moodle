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
 * Event observer for local_edulution.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Observer class for handling events.
 */
class local_edulution_observer
{

    /**
     * Handle config changes.
     *
     * When sync_interval setting is changed, update the scheduled task.
     *
     * @param \core\event\config_log_created $event The event.
     * @return void
     */
    public static function config_changed(\core\event\config_log_created $event): void
    {
        $data = $event->get_data();
        $other = $data['other'] ?? [];

        // Check if this is our plugin's sync_interval setting.
        $plugin = $other['plugin'] ?? '';
        $name = $other['name'] ?? '';

        if ($plugin === 'local_edulution' && $name === 'sync_interval') {
            require_once(__DIR__ . '/../lib.php');
            local_edulution_update_sync_schedule();
        }
    }
}
