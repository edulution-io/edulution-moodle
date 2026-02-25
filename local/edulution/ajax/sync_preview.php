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
 * AJAX handler to get sync preview.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

// Require login and capability.
require_login();
require_capability('local/edulution:sync', context_system::instance());
require_sesskey();

// Set JSON header.
header('Content-Type: application/json');

try {
    // Check if Keycloak is configured.
    if (!local_edulution_is_keycloak_configured()) {
        throw new Exception(get_string('sync_not_configured', 'local_edulution'));
    }

    // Get sync options.
    $syncNewUsers = optional_param('sync_new_users', 1, PARAM_INT);
    $syncExistingUsers = optional_param('sync_existing_users', 1, PARAM_INT);
    $syncDeletions = optional_param('sync_deletions', 0, PARAM_INT);

    // Get Keycloak configuration.
    $keycloakUrl = get_config('local_edulution', 'keycloak_url');
    $keycloakRealm = get_config('local_edulution', 'keycloak_realm');
    $keycloakClientId = get_config('local_edulution', 'keycloak_client_id');
    $keycloakClientSecret = get_config('local_edulution', 'keycloak_client_secret');

    // In a real implementation, this would:
    // 1. Connect to Keycloak
    // 2. Fetch users from Keycloak
    // 3. Compare with Moodle users
    // 4. Return differences

    // For now, return a simulated preview.
    $preview = [
        'haschanges' => true,
        'userstocreate' => [
            'users' => [
                ['username' => 'newuser1', 'email' => 'newuser1@example.com'],
                ['username' => 'newuser2', 'email' => 'newuser2@example.com'],
            ],
        ],
        'userstoupdate' => [
            'users' => [
                ['username' => 'existinguser1', 'changes' => 'Email changed'],
            ],
        ],
        'userstodelete' => [
            'users' => [],
        ],
        'createcount' => 2,
        'updatecount' => 1,
        'deletecount' => 0,
    ];

    // If no sync options selected, no changes.
    if (!$syncNewUsers && !$syncExistingUsers && !$syncDeletions) {
        $preview = [
            'haschanges' => false,
            'userstocreate' => [],
            'userstoupdate' => [],
            'userstodelete' => [],
            'createcount' => 0,
            'updatecount' => 0,
            'deletecount' => 0,
        ];
    }

    echo json_encode([
        'success' => true,
        'preview' => $preview,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
