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
 * AJAX handler to start an export.
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
    // Get export options from POST.
    $options = [
        'full_db_export' => optional_param('full_db_export', 0, PARAM_INT),
        'exclude_tables' => optional_param('exclude_tables', '', PARAM_TEXT),
        'include_moodledata' => optional_param('include_moodledata', 0, PARAM_INT),
        'include_backups' => optional_param('include_backups', 1, PARAM_INT),
        'compression_level' => optional_param('compression_level', 6, PARAM_INT),
        'categories' => optional_param_array('categories', [], PARAM_INT),
        'courses' => optional_param_array('courses', [], PARAM_INT),
    ];

    // Generate a unique job ID.
    $jobId = uniqid('export_', true);

    // Store options in session for the export process.
    $sessionKey = 'edulution_export_' . $jobId;
    $_SESSION[$sessionKey] = [
        'options' => $options,
        'status' => 'pending',
        'progress' => 0,
        'phase' => get_string('progress_initializing', 'local_edulution'),
        'message' => '',
        'started' => time(),
    ];

    // Create progress file.
    $progressFile = $CFG->tempdir . '/edulution_export_' . $jobId . '.json';
    file_put_contents($progressFile, json_encode([
        'status' => 'pending',
        'progress' => 0,
        'phase' => get_string('progress_initializing', 'local_edulution'),
        'message' => '',
    ]));

    // Log activity.
    local_edulution_log_activity_record('export', get_string('activity_export_started', 'local_edulution'), 'running', $options);

    // Return success with job ID.
    echo json_encode([
        'success' => true,
        'jobid' => $jobId,
        'message' => get_string('export_running', 'local_edulution'),
    ]);

    // Close connection to client.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // Now run the actual export (in background).
    // In a real implementation, this would be done via a scheduled task or
    // a separate process to avoid timeout issues.
    // For now, we'll start the export process.

    // Increase limits.
    @set_time_limit(0);
    raise_memory_limit(MEMORY_HUGE);

    // Update progress.
    $updateProgress = function($progress, $phase, $message = '') use ($progressFile) {
        $data = [
            'status' => 'running',
            'progress' => $progress,
            'phase' => $phase,
            'message' => $message,
        ];
        file_put_contents($progressFile, json_encode($data));
    };

    // Simulate export process.
    $exportDir = local_edulution_get_export_path();
    if (!local_edulution_ensure_directory($exportDir)) {
        throw new Exception(get_string('error_directory_not_writable', 'local_edulution', $exportDir));
    }

    // Generate filename.
    $filename = local_edulution_generate_export_filename('edulution_export', 'zip');
    $exportPath = $exportDir . '/' . $filename;

    $updateProgress(10, get_string('progress_initializing', 'local_edulution'));

    // Create export package.
    $zip = new ZipArchive();
    if ($zip->open($exportPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception(get_string('error_export_failed', 'local_edulution', 'Could not create ZIP file'));
    }

    // Export based on options.
    if ($options['full_db_export']) {
        $updateProgress(30, get_string('progress_exporting_database', 'local_edulution'));
        // Full DB export would be implemented here.
        // This requires mysqldump or similar.
    }

    $updateProgress(50, get_string('progress_exporting_users', 'local_edulution'));
    // Export users.
    global $DB;
    $users = $DB->get_records('user', ['deleted' => 0], '', 'id, username, email, firstname, lastname');
    $zip->addFromString('users.json', json_encode(array_values($users), JSON_PRETTY_PRINT));

    $updateProgress(70, get_string('progress_exporting_courses', 'local_edulution'));
    // Export courses.
    $courses = $DB->get_records('course', [], '', 'id, fullname, shortname, category');
    $zip->addFromString('courses.json', json_encode(array_values($courses), JSON_PRETTY_PRINT));

    $updateProgress(80, get_string('progress_exporting_categories', 'local_edulution'));
    // Export categories.
    $categories = $DB->get_records('course_categories');
    $zip->addFromString('categories.json', json_encode(array_values($categories), JSON_PRETTY_PRINT));

    $updateProgress(90, get_string('progress_creating_package', 'local_edulution'));

    // Add manifest.
    $manifest = [
        'version' => '1.0',
        'created' => date('c'),
        'moodle_version' => $CFG->version,
        'options' => $options,
        'files' => [
            'users.json',
            'courses.json',
            'categories.json',
        ],
    ];
    $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

    $zip->close();

    $updateProgress(100, get_string('progress_complete', 'local_edulution'));

    // Mark as complete.
    $downloadUrl = (new moodle_url('/local/edulution/download.php', [
        'file' => $filename,
        'sesskey' => sesskey(),
    ]))->out(false);

    file_put_contents($progressFile, json_encode([
        'status' => 'complete',
        'progress' => 100,
        'phase' => get_string('export_complete', 'local_edulution'),
        'message' => 'Export file: ' . $filename,
        'filename' => $filename,
        'filesize' => filesize($exportPath),
        'download_url' => $downloadUrl,
    ]));

    // Log completion.
    local_edulution_log_activity_record('export', get_string('activity_export_completed', 'local_edulution'), 'success', [
        'filename' => $filename,
        'filesize' => filesize($exportPath),
    ]);

} catch (Exception $e) {
    // Log error.
    if (isset($progressFile)) {
        file_put_contents($progressFile, json_encode([
            'status' => 'error',
            'progress' => 0,
            'phase' => get_string('export_failed', 'local_edulution'),
            'message' => $e->getMessage(),
        ]));
    }

    local_edulution_log_activity_record('export', get_string('activity_export_failed', 'local_edulution'), 'failed', [
        'error' => $e->getMessage(),
    ]);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
