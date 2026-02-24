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
 * AJAX handler to get export progress.
 *
 * This endpoint is polled by the export page to get the current
 * status of an export job.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

// Require login and capability.
require_login();
require_capability('local/edulution:export', context_system::instance());
require_sesskey();

// Set JSON header.
header('Content-Type: application/json');

try {
    // Get job ID.
    $jobId = required_param('jobid', PARAM_ALPHANUMEXT);

    // Validate job ID format (export_HEXID).
    if (!preg_match('/^export_[a-f0-9]+$/i', $jobId)) {
        throw new Exception('Invalid job ID format: ' . $jobId);
    }

    // Check progress file in dataroot.
    $progressDir = $CFG->dataroot . '/edulution/progress';
    $progressFile = $progressDir . '/export_' . $jobId . '.json';

    // Also check legacy location in tempdir.
    $legacyFile = $CFG->tempdir . '/edulution_export_' . $jobId . '.json';

    if (!file_exists($progressFile) && file_exists($legacyFile)) {
        $progressFile = $legacyFile;
    }

    if (!file_exists($progressFile)) {
        // Check if directory exists and list files for debugging.
        $debugInfo = "Looking for: $progressFile\n";
        $debugInfo .= "Directory exists: " . (is_dir($progressDir) ? 'yes' : 'no') . "\n";
        if (is_dir($progressDir)) {
            $files = glob($progressDir . '/*');
            $debugInfo .= "Files in directory: " . count($files) . "\n";
            foreach (array_slice($files, 0, 10) as $f) {
                $debugInfo .= "  - " . basename($f) . "\n";
            }
        }

        // Return a "waiting" status instead of throwing error - job may still be starting.
        echo json_encode([
            'success' => true,
            'status' => 'starting',
            'progress' => 0,
            'percentage' => 0,
            'phase' => 'Waiting for export to start...',
            'message' => 'Export is initializing...',
            'log' => "Waiting for export process to begin...\n\nDebug:\n$debugInfo",
            'completed' => false,
        ]);
        exit;
    }

    $progressData = json_decode(file_get_contents($progressFile), true);

    if ($progressData === null) {
        throw new Exception('Invalid progress data');
    }

    // Also read log file if it exists for more detailed output.
    $logFile = $progressDir . '/export_' . $jobId . '.log';
    $logContent = $progressData['log'] ?? '';

    if (file_exists($logFile) && filesize($logFile) > 0) {
        $logContent = file_get_contents($logFile);
        // Limit log size to prevent huge responses.
        if (strlen($logContent) > 50000) {
            $logContent = "...(truncated)...\n\n" . substr($logContent, -50000);
        }

        // If log file is being written to, the export is running.
        // Check if the process completed by looking for success/error indicators.
        if (strpos($logContent, 'EXPORT COMPLETED SUCCESSFULLY') !== false ||
            strpos($logContent, 'Export completed successfully') !== false) {
            // Export seems complete - check for output file.
            $outputFile = $progressData['output_file'] ?? '';
            if (!empty($outputFile) && file_exists($outputFile)) {
                $progressData['status'] = 'complete';
                $progressData['completed'] = true;
                $progressData['success'] = true;
                $progressData['progress'] = 100;
                $progressData['filename'] = basename($outputFile);
                $progressData['filesize'] = filesize($outputFile);
            }
        } elseif (strpos($logContent, 'Export failed') !== false ||
                  strpos($logContent, 'ERROR:') !== false ||
                  strpos($logContent, 'Fatal error') !== false) {
            $progressData['status'] = 'error';
            $progressData['completed'] = true;
            $progressData['success'] = false;
        } elseif ($progressData['status'] === 'running') {
            // Still running - estimate progress from log content.
            $progressData['progress'] = min(90, 10 + (strlen($logContent) / 1000));
        }
    }

    // Check if background PID is still running.
    if (!empty($progressData['pid']) && $progressData['status'] === 'running') {
        $pid = (int)$progressData['pid'];
        $isRunning = file_exists("/proc/{$pid}");
        if (!$isRunning) {
            // Process completed - check output file.
            $outputFile = $progressData['output_file'] ?? '';
            if (!empty($outputFile) && file_exists($outputFile)) {
                $progressData['status'] = 'complete';
                $progressData['completed'] = true;
                $progressData['success'] = true;
                $progressData['progress'] = 100;
                $progressData['filename'] = basename($outputFile);
                $progressData['filesize'] = filesize($outputFile);
            } else {
                $progressData['status'] = 'error';
                $progressData['completed'] = true;
                $progressData['success'] = false;
                $progressData['error'] = 'Export process ended but no output file found';
            }
            // Update progress file.
            file_put_contents($progressFile, json_encode($progressData));
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

    // Check if job is completed.
    $completedStatuses = ['complete', 'error', 'failed'];
    $isCompleted = in_array($progressData['status'] ?? '', $completedStatuses) || ($progressData['completed'] ?? false);
    $response['completed'] = $isCompleted;

    if ($isCompleted) {
        $isSuccess = ($progressData['status'] === 'complete') || ($progressData['success'] ?? false);
        $response['success'] = $isSuccess;

        if ($isSuccess) {
            // Include download information.
            if (!empty($progressData['download_url'])) {
                $response['download_url'] = $progressData['download_url'];
            } else if (!empty($progressData['filename'])) {
                // Generate download URL if not provided (CLI script may not have it).
                $response['download_url'] = (new moodle_url('/local/edulution/ajax/download.php', [
                    'file' => $progressData['filename'],
                    'sesskey' => sesskey(),
                ]))->out(false);
            }
            if (!empty($progressData['filename'])) {
                $response['filename'] = $progressData['filename'];
            }
            if (!empty($progressData['filesize'])) {
                $response['filesize'] = $progressData['filesize'];
            }
        } else {
            // Include error information.
            $response['error'] = $progressData['error'] ?? $progressData['message'] ?? 'Export failed';
        }

        // Clean up old progress/log files after 10 minutes.
        $fileAge = time() - filemtime($progressFile);
        if ($fileAge > 600) {
            @unlink($progressFile);
            $logFile = $progressDir . '/export_' . $jobId . '.log';
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
        'percentage' => 0,
    ]);
}
