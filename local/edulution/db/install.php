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
 * Installation script for local_edulution.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Performs installation tasks for the edulution plugin.
 *
 * This function is called when the plugin is first installed.
 * It sets up default configuration values and any required
 * initial data structures.
 *
 * @return bool True on success.
 */
function xmldb_local_edulution_install()
{
    global $CFG;

    // Set default configuration values.
    set_config('enabled', 0, 'local_edulution');
    set_config('keycloak_sync_enabled', 0, 'local_edulution');
    set_config('export_path', $CFG->dataroot . '/edulution/exports', 'local_edulution');
    set_config('import_path', $CFG->dataroot . '/edulution/imports', 'local_edulution');
    set_config('export_retention_days', 30, 'local_edulution');

    // Create default directories if they don't exist.
    $exportpath = $CFG->dataroot . '/edulution/exports';
    $importpath = $CFG->dataroot . '/edulution/imports';

    if (!file_exists($exportpath)) {
        mkdir($exportpath, 0755, true);
    }

    if (!file_exists($importpath)) {
        mkdir($importpath, 0755, true);
    }

    return true;
}
