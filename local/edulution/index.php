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
 * Redirects to the main dashboard.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once(__DIR__ . '/lib.php');

// Check if Keycloak is configured - if not, redirect to setup.
// Environment variables take precedence over database values.
$keycloakurl = local_edulution_get_config('keycloak_url');
$keycloakclientid = local_edulution_get_config('keycloak_client_id');

if (empty($keycloakurl) || empty($keycloakclientid)) {
    redirect(new moodle_url('/local/edulution/setup.php'));
} else {
    redirect(new moodle_url('/local/edulution/dashboard.php'));
}
