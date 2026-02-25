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
 * External services definition for local_edulution.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_edulution_get_sync_preview' => [
        'classname' => 'local_edulution\external\sync_external',
        'methodname' => 'get_sync_preview',
        'description' => 'Get a preview of what will be synchronized',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/edulution:sync',
    ],
    'local_edulution_start_sync' => [
        'classname' => 'local_edulution\external\sync_external',
        'methodname' => 'start_sync',
        'description' => 'Start the Keycloak synchronization',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/edulution:sync',
    ],
    'local_edulution_get_sync_status' => [
        'classname' => 'local_edulution\external\sync_external',
        'methodname' => 'get_sync_status',
        'description' => 'Get the status of a running sync job',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/edulution:sync',
    ],
    'local_edulution_cancel_sync' => [
        'classname' => 'local_edulution\external\sync_external',
        'methodname' => 'cancel_sync',
        'description' => 'Cancel a running sync job',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/edulution:sync',
    ],
    'local_edulution_get_ongoing_sync' => [
        'classname' => 'local_edulution\external\sync_external',
        'methodname' => 'get_ongoing_sync',
        'description' => 'Check if there is an ongoing sync job',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/edulution:sync',
    ],
];
