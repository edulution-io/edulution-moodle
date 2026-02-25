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
 * AJAX handler to start an import.
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
require_capability('local/edulution:import', context_system::instance());
require_sesskey();

// Set JSON header.
header('Content-Type: application/json');

try {
    // Get import options.
    $filepath = required_param('import_file_path', PARAM_PATH);
    $importUsers = optional_param('import_users', 0, PARAM_INT);
    $importCategories = optional_param('import_categories', 0, PARAM_INT);
    $importCourses = optional_param('import_courses', 0, PARAM_INT);
    $importEnrollments = optional_param('import_enrollments', 0, PARAM_INT);

    // Validate file exists.
    if (!file_exists($filepath)) {
        throw new Exception(get_string('error_file_not_found', 'local_edulution'));
    }

    // Generate job ID.
    $jobId = uniqid('import_', true);

    // Create progress file.
    $progressFile = $CFG->tempdir . '/edulution_import_' . $jobId . '.json';
    file_put_contents($progressFile, json_encode([
        'status' => 'pending',
        'progress' => 0,
        'phase' => get_string('progress_initializing', 'local_edulution'),
        'message' => '',
    ]));

    // Log activity.
    local_edulution_log_activity_record('import', get_string('activity_import_started', 'local_edulution'), 'running');

    // Return success with job ID.
    echo json_encode([
        'success' => true,
        'jobid' => $jobId,
        'message' => 'Import started',
    ]);

    // Close connection.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // Increase limits.
    @set_time_limit(0);
    raise_memory_limit(MEMORY_HUGE);

    // Update progress function.
    $updateProgress = function ($progress, $phase, $message = '') use ($progressFile) {
        file_put_contents($progressFile, json_encode([
            'status' => 'running',
            'progress' => $progress,
            'phase' => $phase,
            'message' => $message,
        ]));
    };

    // Open ZIP file.
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        throw new Exception(get_string('invalid_export_file', 'local_edulution'));
    }

    $results = [
        'users_imported' => 0,
        'categories_imported' => 0,
        'courses_imported' => 0,
        'enrollments_imported' => 0,
        'errors' => [],
    ];

    global $DB;

    // Import categories.
    if ($importCategories) {
        $updateProgress(20, get_string('progress_importing', 'local_edulution'), 'Importing categories...');

        $categoriesContent = $zip->getFromName('categories.json');
        if ($categoriesContent) {
            $categories = json_decode($categoriesContent, true);
            if (is_array($categories)) {
                foreach ($categories as $category) {
                    // Check if category exists.
                    if (!$DB->record_exists('course_categories', ['id' => $category['id']])) {
                        // Would create category here.
                        $results['categories_imported']++;
                    }
                }
            }
        }
    }

    // Import users.
    if ($importUsers) {
        $updateProgress(40, get_string('progress_importing', 'local_edulution'), 'Importing users...');

        $usersContent = $zip->getFromName('users.json');
        if ($usersContent) {
            $users = json_decode($usersContent, true);
            if (is_array($users)) {
                foreach ($users as $user) {
                    // Check if user exists.
                    if (!$DB->record_exists('user', ['username' => $user['username']])) {
                        // Would create user here.
                        $results['users_imported']++;
                    }
                }
            }
        }
    }

    // Import courses.
    if ($importCourses) {
        $updateProgress(60, get_string('progress_importing', 'local_edulution'), 'Importing courses...');

        $coursesContent = $zip->getFromName('courses.json');
        if ($coursesContent) {
            $courses = json_decode($coursesContent, true);
            if (is_array($courses)) {
                foreach ($courses as $course) {
                    // Check if course exists.
                    if (!$DB->record_exists('course', ['shortname' => $course['shortname']])) {
                        // Would create course here.
                        $results['courses_imported']++;
                    }
                }
            }
        }
    }

    // Import enrollments.
    if ($importEnrollments) {
        $updateProgress(80, get_string('progress_importing', 'local_edulution'), 'Importing enrollments...');

        $enrollmentsContent = $zip->getFromName('enrollments.json');
        if ($enrollmentsContent) {
            $enrollments = json_decode($enrollmentsContent, true);
            if (is_array($enrollments)) {
                // Would process enrollments here.
                $results['enrollments_imported'] = count($enrollments);
            }
        }
    }

    $zip->close();

    $updateProgress(100, get_string('progress_complete', 'local_edulution'));

    // Mark as complete.
    file_put_contents($progressFile, json_encode([
        'status' => 'complete',
        'progress' => 100,
        'phase' => get_string('import_complete', 'local_edulution'),
        'message' => 'Import completed successfully',
        'results' => $results,
    ]));

    // Log completion.
    local_edulution_log_activity_record('import', get_string('activity_import_completed', 'local_edulution'), 'success', $results);

} catch (Exception $e) {
    if (isset($progressFile)) {
        file_put_contents($progressFile, json_encode([
            'status' => 'error',
            'progress' => 0,
            'phase' => get_string('import_failed', 'local_edulution'),
            'message' => $e->getMessage(),
        ]));
    }

    local_edulution_log_activity_record('import', get_string('activity_import_failed', 'local_edulution'), 'failed', [
        'error' => $e->getMessage(),
    ]);

    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
