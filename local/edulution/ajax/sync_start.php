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
 * AJAX handler to start sync.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

use local_edulution\sync\keycloak_client;
use local_edulution\sync\group_classifier;
use local_edulution\sync\phased_sync;

// Require login and capability.
require_login();
require_capability('local/edulution:sync', context_system::instance());
require_sesskey();

// Set JSON header.
header('Content-Type: application/json');

try {
    // Check if Keycloak is configured.
    if (!local_edulution_is_keycloak_configured()) {
        throw new Exception('Keycloak ist nicht konfiguriert.');
    }

    // Generate job ID.
    $jobId = uniqid('sync_', true);

    // Create progress file.
    $progressFile = $CFG->tempdir . '/edulution_sync_' . $jobId . '.json';
    file_put_contents($progressFile, json_encode([
        'status' => 'pending',
        'progress' => 0,
        'phase' => 'init',
        'message' => 'Initialisiere...',
        'stats' => [],
        'log' => [],
    ]));

    // Return success with job ID immediately.
    echo json_encode([
        'success' => true,
        'jobid' => $jobId,
        'message' => 'Sync gestartet',
    ]);

    // Close connection to browser.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // Fallback for non-FPM.
        ob_end_flush();
        flush();
    }

    // Increase limits for long-running sync.
    @set_time_limit(0);
    raise_memory_limit(MEMORY_HUGE);

    // Get Keycloak configuration (environment variables take precedence).
    $keycloakUrl = local_edulution_get_config('keycloak_url');
    $keycloakRealm = local_edulution_get_config('keycloak_realm', 'master');
    $keycloakClientId = local_edulution_get_config('keycloak_client_id');
    $keycloakClientSecret = local_edulution_get_config('keycloak_client_secret');
    $verifySsl = local_edulution_get_config('verify_ssl', true);

    // Create Keycloak client.
    $client = new keycloak_client($keycloakUrl, $keycloakRealm, $keycloakClientId, $keycloakClientSecret);
    if (!$verifySsl) {
        $client->set_verify_ssl(false);
    }

    // Create classifier.
    $classifier = new group_classifier();

    // Create phased sync with progress callback.
    $sync = new phased_sync($client, $classifier);

    // Progress callback to update the progress file.
    $sync->set_progress_callback(function($phase, $progress, $message, $stats) use ($progressFile) {
        $current = json_decode(file_get_contents($progressFile), true) ?: [];
        $current['status'] = 'running';
        $current['progress'] = $progress;
        $current['phase'] = $phase;
        $current['message'] = $message;
        $current['stats'] = $stats;
        file_put_contents($progressFile, json_encode($current));
    });

    // Run the sync.
    $result = $sync->run();

    // Save last sync time and stats.
    set_config('last_sync_time', time(), 'local_edulution');
    set_config('last_sync_stats', json_encode($result['stats']), 'local_edulution');

    // Mark as complete.
    file_put_contents($progressFile, json_encode([
        'status' => 'complete',
        'progress' => 100,
        'phase' => 'complete',
        'message' => 'Synchronisierung abgeschlossen',
        'stats' => $result['stats'],
        'log' => array_slice($result['log'], -20), // Last 20 log entries.
        'errors' => $result['errors'],
    ]));

} catch (Exception $e) {
    // Log error.
    error_log('[Edulution Sync] Error: ' . $e->getMessage());

    if (isset($progressFile)) {
        file_put_contents($progressFile, json_encode([
            'status' => 'error',
            'progress' => 0,
            'phase' => 'error',
            'message' => $e->getMessage(),
            'stats' => [],
            'log' => [],
            'errors' => [['type' => 'exception', 'message' => $e->getMessage()]],
        ]));
    } else {
        // If we haven't returned the job ID yet.
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
}
