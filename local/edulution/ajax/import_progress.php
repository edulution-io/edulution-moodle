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
 * AJAX handler for import progress polling.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once('../../../config.php');

// Note: We don't require login/sesskey here because after a database import, the session is invalid.
// The job ID validation is sufficient for reading progress status (it's just read-only status info).
// The actual import operation requires full authentication.

header('Content-Type: application/json');

try {
    // Get job ID.
    $jobId = required_param('jobid', PARAM_ALPHANUMEXT);

    // Validate job ID format (import_HEXID).
    if (!preg_match('/^import_[a-f0-9]+$/i', $jobId)) {
        throw new Exception('Invalid job ID format: ' . $jobId);
    }

    // Check progress file.
    $progressDir = $CFG->dataroot . '/edulution/progress';
    $progressFile = $progressDir . '/import_' . $jobId . '.json';
    $logFile = $progressDir . '/import_' . $jobId . '.log';

    // Also check legacy location.
    $legacyFile = $CFG->dataroot . '/edulution/import_progress_' . sesskey() . '.json';

    if (!file_exists($progressFile) && file_exists($legacyFile)) {
        $progressFile = $legacyFile;
    }

    if (!file_exists($progressFile)) {
        // Check if directory exists and list files for debugging.
        $debugInfo = "Looking for: $progressFile\n";
        $debugInfo .= "Job ID: $jobId\n";
        $debugInfo .= "Directory exists: " . (is_dir($progressDir) ? 'yes' : 'no') . "\n";
        if (is_dir($progressDir)) {
            $files = glob($progressDir . '/import_*');
            $debugInfo .= "Import files in directory: " . count($files) . "\n";
            foreach (array_slice($files, 0, 10) as $f) {
                $debugInfo .= "  - " . basename($f) . "\n";
            }
        }

        // Return waiting status instead of error.
        echo json_encode([
            'success' => true,
            'status' => 'starting',
            'progress' => 0,
            'percentage' => 0,
            'phase' => 'Waiting for import to start...',
            'message' => 'Import is initializing...',
            'log' => "Waiting for import process to begin...\n\nDebug:\n$debugInfo",
            'completed' => false,
            'complete' => false,
        ]);
        exit;
    }

    $progressData = json_decode(file_get_contents($progressFile), true);

    if ($progressData === null) {
        throw new Exception('Invalid progress data');
    }

    // Read log file if exists.
    $logContent = $progressData['log'] ?? '';
    if (file_exists($logFile)) {
        $logContent = file_get_contents($logFile);
        if (strlen($logContent) > 50000) {
            $logContent = "...(truncated)...\n\n" . substr($logContent, -50000);
        }
    }

    // Build response.
    $response = [
        'success' => true,
        'status' => $progressData['status'] ?? 'unknown',
        'progress' => $progressData['progress'] ?? $progressData['percentage'] ?? 0,
        'percentage' => $progressData['progress'] ?? $progressData['percentage'] ?? 0,
        'phase' => $progressData['phase'] ?? $progressData['message'] ?? '',
        'message' => $progressData['message'] ?? $progressData['phase'] ?? '',
        'log' => $logContent,
    ];

    // Check completion.
    $completedStatuses = ['complete', 'success', 'error', 'failed'];
    $isCompleted = in_array($progressData['status'] ?? '', $completedStatuses) || ($progressData['complete'] ?? false) || ($progressData['completed'] ?? false);
    $response['completed'] = $isCompleted;
    $response['complete'] = $isCompleted;

    if ($isCompleted) {
        $isSuccess = in_array($progressData['status'] ?? '', ['complete', 'success']) || ($progressData['success'] ?? false);
        $response['success'] = $isSuccess;

        if ($isSuccess && !($progressData['dry_run'] ?? false)) {
            // Provide redirect URL for successful non-dry-run imports.
            $response['redirect'] = ($progressData['wwwroot'] ?? $CFG->wwwroot) . '/login/index.php';
        }

        // Clean up old files after 10 minutes.
        $fileAge = time() - filemtime($progressFile);
        if ($fileAge > 600) {
            @unlink($progressFile);
            if (file_exists($logFile)) {
                @unlink($logFile);
            }
        }
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'completed' => true,
        'complete' => true,
        'percentage' => 0,
    ]);
}
