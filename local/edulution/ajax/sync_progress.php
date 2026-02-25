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
 * AJAX handler to get sync progress.
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
    // Get job ID.
    $jobId = required_param('jobid', PARAM_ALPHANUMEXT);

    // Check progress file.
    $progressFile = $CFG->tempdir . '/edulution_sync_' . $jobId . '.json';

    if (!file_exists($progressFile)) {
        throw new Exception(get_string('error_file_not_found', 'local_edulution'));
    }

    $progressData = json_decode(file_get_contents($progressFile), true);

    if ($progressData === null) {
        throw new Exception(get_string('error_invalid_file', 'local_edulution'));
    }

    // Add completion flag for UI.
    $progressData['completed'] = in_array($progressData['status'], ['complete', 'error']);
    $progressData['success'] = $progressData['status'] === 'complete';
    $progressData['percentage'] = $progressData['progress'] ?? 0;

    echo json_encode($progressData);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'completed' => true,
        'percentage' => 0,
    ]);
}
