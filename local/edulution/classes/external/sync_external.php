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
 * External functions for Keycloak synchronization.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use context_system;
use local_edulution\sync\sync_manager;
use local_edulution\sync\keycloak_client;
use local_edulution\sync\group_classifier;
use local_edulution\task\adhoc_sync_task;

/**
 * External functions for sync operations.
 */
class sync_external extends external_api {

    /**
     * Parameters for get_sync_preview.
     *
     * @return external_function_parameters
     */
    public static function get_sync_preview_parameters(): external_function_parameters {
        return new external_function_parameters([
            'direction' => new external_value(PARAM_ALPHANUMEXT, 'Sync direction', VALUE_DEFAULT, 'from_keycloak'),
            'options' => new external_value(PARAM_RAW, 'JSON options', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Get a preview of what will be synchronized.
     *
     * Shows users, courses, and enrollments that would be created/updated.
     *
     * @param string $direction Sync direction.
     * @param string $options JSON-encoded options.
     * @return array Preview data.
     */
    public static function get_sync_preview(string $direction = 'from_keycloak', string $options = '{}'): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::get_sync_preview_parameters(), [
            'direction' => $direction,
            'options' => $options,
        ]);

        // Check capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/edulution:sync', $context);

        try {
            // Check if Keycloak is configured (environment variables take precedence).
            $url = \local_edulution_get_config('keycloak_url');
            $realm = \local_edulution_get_config('keycloak_realm', 'master');
            $client_id = \local_edulution_get_config('keycloak_client_id');
            $client_secret = \local_edulution_get_config('keycloak_client_secret');

            if (empty($url) || empty($realm) || empty($client_id) || empty($client_secret)) {
                return [
                    'success' => false,
                    'message' => get_string('keycloak_not_configured', 'local_edulution'),
                    'toCreate' => [],
                    'toUpdate' => [],
                    'toDelete' => [],
                    'toSkip' => [],
                    'warnings' => [],
                ];
            }

            // Create Keycloak client.
            $client = new keycloak_client($url, $realm, $client_id, $client_secret);
            $classifier = new \local_edulution\sync\group_classifier();

            $to_create = [];
            $to_update = [];
            $to_skip = [];
            $warnings = [];

            // === USERS ===
            // Fetch users from Keycloak.
            $keycloak_users = [];
            $offset = 0;
            $batch_size = 100;

            do {
                $users = $client->get_users('', $batch_size, $offset);
                $keycloak_users = array_merge($keycloak_users, $users);
                $offset += $batch_size;
            } while (count($users) === $batch_size);

            // Build Moodle user lookup.
            $moodle_users_by_email = [];
            $moodle_users_by_username = [];
            $records = $DB->get_records('user', ['deleted' => 0], '', 'id, username, email, firstname, lastname');
            foreach ($records as $user) {
                $moodle_users_by_email[strtolower($user->email)] = $user;
                $moodle_users_by_username[strtolower($user->username)] = $user;
            }

            $users_to_create = 0;
            $users_to_update = 0;
            $users_to_skip = 0;

            foreach ($keycloak_users as $kc_user) {
                if (empty($kc_user['username']) || empty($kc_user['email'])) {
                    $users_to_skip++;
                    continue;
                }
                if (!($kc_user['enabled'] ?? true)) {
                    $users_to_skip++;
                    continue;
                }

                $email_lower = strtolower($kc_user['email']);
                $username_lower = strtolower($kc_user['username']);

                $moodle_user = $moodle_users_by_email[$email_lower]
                    ?? $moodle_users_by_username[$username_lower]
                    ?? null;

                if ($moodle_user) {
                    // Check if update needed.
                    $kc_firstname = $kc_user['firstName'] ?? '';
                    $kc_lastname = $kc_user['lastName'] ?? '';

                    if ($moodle_user->firstname !== $kc_firstname || $moodle_user->lastname !== $kc_lastname) {
                        $users_to_update++;
                        // Only add first 10 to list.
                        if (count($to_update) < 10) {
                            $to_update[] = [
                                'id' => $kc_user['username'],
                                'type' => 'user',
                                'name' => $kc_user['username'],
                                'details' => "Update: {$kc_user['email']}",
                            ];
                        }
                    } else {
                        $users_to_skip++;
                    }
                } else {
                    $users_to_create++;
                    // Only add first 10 to list.
                    if ($users_to_create <= 10) {
                        $to_create[] = [
                            'id' => $kc_user['username'],
                            'type' => 'user',
                            'name' => $kc_user['username'],
                            'details' => ($kc_user['firstName'] ?? '') . ' ' . ($kc_user['lastName'] ?? '') . ' <' . $kc_user['email'] . '>',
                        ];
                    }
                }
            }

            // Add summary items if there are more.
            if ($users_to_create > 10) {
                $to_create[] = [
                    'id' => 'users_summary',
                    'type' => 'user',
                    'name' => '... and ' . ($users_to_create - 10) . ' more users',
                    'details' => 'Total: ' . $users_to_create . ' users to create',
                ];
            }
            if ($users_to_update > 10) {
                $to_update[] = [
                    'id' => 'users_update_summary',
                    'type' => 'user',
                    'name' => '... and ' . ($users_to_update - 10) . ' more users',
                    'details' => 'Total: ' . $users_to_update . ' users to update',
                ];
            }

            // === GROUPS/COURSES ===
            // Fetch groups from Keycloak.
            $keycloak_groups = $client->get_all_groups_flat();

            // Classify groups.
            $classified = $classifier->classify_groups($keycloak_groups);

            // Get existing courses.
            $existing_courses = [];
            $records = $DB->get_records('course', [], '', 'id, idnumber, shortname');
            foreach ($records as $course) {
                if (!empty($course->idnumber)) {
                    $existing_courses[$course->idnumber] = $course;
                }
            }

            $courses_to_create = 0;
            $courses_existing = 0;

            // Class groups -> Courses.
            $class_groups = $classified[\local_edulution\sync\group_classifier::TYPE_CLASS] ?? [];
            foreach ($class_groups as $group) {
                $idnumber = 'kc_' . $group['name'];

                if (isset($existing_courses[$idnumber])) {
                    $courses_existing++;
                } else {
                    $courses_to_create++;
                    if ($courses_to_create <= 10) {
                        $to_create[] = [
                            'id' => 'course_' . $group['name'],
                            'type' => 'course',
                            'name' => $group['name'],
                            'details' => 'Class course from group',
                        ];
                    }
                }
            }

            // Project groups -> Courses.
            $project_groups = $classified[\local_edulution\sync\group_classifier::TYPE_PROJECT] ?? [];
            foreach ($project_groups as $group) {
                $idnumber = 'kc_project_' . $group['name'];

                if (isset($existing_courses[$idnumber])) {
                    $courses_existing++;
                } else {
                    $courses_to_create++;
                    if ($courses_to_create <= 20) { // Include some project courses.
                        $to_create[] = [
                            'id' => 'project_' . $group['name'],
                            'type' => 'course',
                            'name' => $group['name'],
                            'details' => 'Project course',
                        ];
                    }
                }
            }

            if ($courses_to_create > 20) {
                $to_create[] = [
                    'id' => 'courses_summary',
                    'type' => 'course',
                    'name' => '... and ' . ($courses_to_create - 20) . ' more courses',
                    'details' => 'Total: ' . $courses_to_create . ' courses to create',
                ];
            }

            // === BUILD USER CACHE WITH TEACHER STATUS ===
            // Same logic as phased_sync - detect teachers via LDAP_ENTRY_DN
            $user_cache = [];
            $teachers_detected = 0;

            foreach ($keycloak_users as $kc_user) {
                if (empty($kc_user['username'])) {
                    continue;
                }
                $username_lower = strtolower($kc_user['username']);
                $email_lower = strtolower($kc_user['email'] ?? '');

                // Check if user exists in Moodle
                $moodle_user = $moodle_users_by_email[$email_lower]
                    ?? $moodle_users_by_username[$username_lower]
                    ?? null;

                if (!$moodle_user) {
                    continue;
                }

                // Detect teacher via LDAP_ENTRY_DN (same as phased_sync)
                $is_teacher = self::is_teacher_user_static($kc_user);
                $user_cache[$username_lower] = [
                    'moodle_id' => $moodle_user->id,
                    'is_teacher' => $is_teacher,
                ];
                if ($is_teacher) {
                    $teachers_detected++;
                }
            }

            // === ENROLLMENTS ===
            // Check for missing enrollments AND wrong roles in existing courses.
            $enrollments_to_create = 0;
            $enrollments_existing = 0;
            $roles_to_update = 0;

            // Get existing enrollments WITH their roles.
            $existing_enrollments = [];
            $sql = "SELECT DISTINCT ue.id, ue.userid, e.courseid, r.shortname as role
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                    JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
                    LEFT JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id
                    LEFT JOIN {role} r ON r.id = ra.roleid
                    WHERE e.enrol = 'manual'";
            $records = $DB->get_records_sql($sql);
            foreach ($records as $record) {
                $key = $record->courseid . '_' . $record->userid;
                $existing_enrollments[$key] = $record->role ?? 'student';
            }

            // Get course lookup by idnumber.
            $courses_by_idnumber = [];
            $course_records = $DB->get_records('course', [], '', 'id, idnumber');
            foreach ($course_records as $course) {
                if (!empty($course->idnumber)) {
                    $courses_by_idnumber[$course->idnumber] = $course->id;
                }
            }

            // Get user lookup.
            $users_by_username = [];
            $users_by_email = [];
            $user_records = $DB->get_records('user', ['deleted' => 0], '', 'id, username, email');
            foreach ($user_records as $user) {
                $users_by_username[strtolower($user->username)] = $user->id;
                $users_by_email[strtolower($user->email)] = $user->id;
            }

            // Check enrollments for all groups.
            $teacher_groups = $classified[\local_edulution\sync\group_classifier::TYPE_TEACHER] ?? [];
            $groups_to_check = array_merge($class_groups, $teacher_groups, $project_groups);

            foreach ($groups_to_check as $group) {
                // Determine course idnumber.
                if (strpos($group['name'], '-teachers') !== false) {
                    $base_name = str_replace('-teachers', '', $group['name']);
                    $idnumber = 'kc_' . $base_name;
                } elseif (in_array($group, $project_groups, true)) {
                    $idnumber = 'kc_project_' . $group['name'];
                } else {
                    $idnumber = 'kc_' . $group['name'];
                }

                $course_id = $courses_by_idnumber[$idnumber] ?? null;
                if (!$course_id) {
                    continue; // Course doesn't exist yet.
                }

                // Fetch group members.
                try {
                    $members = $client->get_group_members($group['id']);
                } catch (\Exception $e) {
                    continue;
                }

                foreach ($members as $member) {
                    $username_lower = strtolower($member['username'] ?? '');
                    $email_lower = strtolower($member['email'] ?? '');

                    $user_id = $users_by_username[$username_lower]
                        ?? $users_by_email[$email_lower]
                        ?? null;

                    if (!$user_id) {
                        continue;
                    }

                    // Determine expected role from user_cache (LDAP_ENTRY_DN based)
                    $cached = $user_cache[$username_lower] ?? null;
                    $is_teacher = $cached['is_teacher'] ?? false;
                    $expected_role = $is_teacher ? 'editingteacher' : 'student';

                    $key = $course_id . '_' . $user_id;
                    if (isset($existing_enrollments[$key])) {
                        $current_role = $existing_enrollments[$key];
                        if ($current_role !== $expected_role) {
                            // Role needs to be updated
                            $roles_to_update++;
                        } else {
                            $enrollments_existing++;
                        }
                    } else {
                        $enrollments_to_create++;
                    }
                }
            }

            // Add enrollment summary to preview if there are enrollments to create.
            if ($enrollments_to_create > 0) {
                $to_create[] = [
                    'id' => 'enrollments_summary',
                    'type' => 'enrollment',
                    'name' => $enrollments_to_create . ' enrollments',
                    'details' => 'Missing course enrollments to create',
                ];
            }

            // Add role updates to preview.
            if ($roles_to_update > 0) {
                $to_update[] = [
                    'id' => 'roles_summary',
                    'type' => 'role',
                    'name' => $roles_to_update . ' role updates',
                    'details' => 'Users enrolled with wrong role (student→teacher or vice versa)',
                ];
            }

            // Add summary warnings.
            $total_keycloak_users = count($keycloak_users);
            $total_keycloak_groups = count($keycloak_groups);
            $total_class_groups = count($class_groups);
            $total_teacher_groups = count($teacher_groups);
            $total_project_groups = count($project_groups);

            $warnings[] = "Keycloak: {$total_keycloak_users} users, {$total_keycloak_groups} groups";
            $warnings[] = "Groups classified: {$total_class_groups} classes, {$total_teacher_groups} teacher groups, {$total_project_groups} projects";
            $warnings[] = "Teachers detected (OU=Teachers): {$teachers_detected}";

            return [
                'success' => true,
                'message' => '',
                'toCreate' => $to_create,
                'toUpdate' => $to_update,
                'toDelete' => [],
                'toSkip' => $to_skip,
                'warnings' => $warnings,
                'stats' => [
                    'totalKeycloakUsers' => $total_keycloak_users,
                    'totalKeycloakGroups' => $total_keycloak_groups,
                    'usersToCreate' => $users_to_create,
                    'usersToUpdate' => $users_to_update,
                    'usersToSkip' => $users_to_skip,
                    'coursesToCreate' => $courses_to_create,
                    'coursesExisting' => $courses_existing,
                    'enrollmentsToCreate' => $enrollments_to_create,
                    'enrollmentsExisting' => $enrollments_existing,
                    'rolesToUpdate' => $roles_to_update,
                    'teachersDetected' => $teachers_detected,
                ],
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'toCreate' => [],
                'toUpdate' => [],
                'toDelete' => [],
                'toSkip' => [],
                'warnings' => [$e->getMessage()],
            ];
        }
    }

    /**
     * Return type for get_sync_preview.
     *
     * @return external_single_structure
     */
    public static function get_sync_preview_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the preview was successful'),
            'message' => new external_value(PARAM_RAW, 'Error message if failed'),
            'toCreate' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_RAW, 'Item identifier'),
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'Item type (user, group, course)'),
                    'name' => new external_value(PARAM_RAW, 'Item name'),
                    'details' => new external_value(PARAM_RAW, 'Additional details'),
                ]),
                'Items to create',
                VALUE_DEFAULT,
                []
            ),
            'toUpdate' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_RAW, 'Item identifier'),
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'Item type'),
                    'name' => new external_value(PARAM_RAW, 'Item name'),
                    'details' => new external_value(PARAM_RAW, 'Additional details'),
                ]),
                'Items to update',
                VALUE_DEFAULT,
                []
            ),
            'toDelete' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_RAW, 'Item identifier'),
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'Item type'),
                    'name' => new external_value(PARAM_RAW, 'Item name'),
                    'details' => new external_value(PARAM_RAW, 'Additional details'),
                ]),
                'Items to delete',
                VALUE_DEFAULT,
                []
            ),
            'toSkip' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_RAW, 'Item identifier'),
                    'type' => new external_value(PARAM_ALPHANUMEXT, 'Item type'),
                    'name' => new external_value(PARAM_RAW, 'Item name'),
                    'details' => new external_value(PARAM_RAW, 'Additional details'),
                ]),
                'Items to skip',
                VALUE_DEFAULT,
                []
            ),
            'warnings' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Warning message'),
                'Warning messages',
                VALUE_DEFAULT,
                []
            ),
            'stats' => new external_single_structure([
                'totalKeycloakUsers' => new external_value(PARAM_INT, 'Total users in Keycloak', VALUE_DEFAULT, 0),
                'totalKeycloakGroups' => new external_value(PARAM_INT, 'Total groups in Keycloak', VALUE_DEFAULT, 0),
                'usersToCreate' => new external_value(PARAM_INT, 'Users to create', VALUE_DEFAULT, 0),
                'usersToUpdate' => new external_value(PARAM_INT, 'Users to update', VALUE_DEFAULT, 0),
                'usersToSkip' => new external_value(PARAM_INT, 'Users to skip', VALUE_DEFAULT, 0),
                'coursesToCreate' => new external_value(PARAM_INT, 'Courses to create', VALUE_DEFAULT, 0),
                'coursesExisting' => new external_value(PARAM_INT, 'Courses already existing', VALUE_DEFAULT, 0),
                'enrollmentsToCreate' => new external_value(PARAM_INT, 'Enrollments to create', VALUE_DEFAULT, 0),
                'enrollmentsExisting' => new external_value(PARAM_INT, 'Enrollments existing', VALUE_DEFAULT, 0),
                'rolesToUpdate' => new external_value(PARAM_INT, 'Roles to update', VALUE_DEFAULT, 0),
                'teachersDetected' => new external_value(PARAM_INT, 'Teachers detected', VALUE_DEFAULT, 0),
            ], 'Statistics', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Parameters for start_sync.
     *
     * @return external_function_parameters
     */
    public static function start_sync_parameters(): external_function_parameters {
        return new external_function_parameters([
            'direction' => new external_value(PARAM_ALPHANUMEXT, 'Sync direction', VALUE_DEFAULT, 'from_keycloak'),
            'selectedItems' => new external_value(PARAM_RAW, 'JSON selected items', VALUE_DEFAULT, '{}'),
            'options' => new external_value(PARAM_RAW, 'JSON options', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Start the synchronization process.
     *
     * This queues an ad-hoc task to run the sync in the background,
     * so the web UI remains responsive.
     *
     * @param string $direction Sync direction.
     * @param string $selectedItems JSON-encoded selected items.
     * @param string $options JSON-encoded options.
     * @return array Sync start result.
     */
    public static function start_sync(string $direction = 'from_keycloak', string $selectedItems = '{}', string $options = '{}'): array {
        global $DB, $USER;

        // Validate parameters.
        $params = self::validate_parameters(self::start_sync_parameters(), [
            'direction' => $direction,
            'selectedItems' => $selectedItems,
            'options' => $options,
        ]);

        // Check capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/edulution:sync', $context);

        try {
            // Check if there's already a running sync (for ANY user, not just current).
            $running = $DB->get_record_select(
                'local_edulution_sync_jobs',
                "status IN ('pending', 'processing') AND timecreated > ?",
                [time() - 3600] // Only check jobs from last hour
            );

            if ($running) {
                return [
                    'success' => false,
                    'syncId' => $running->sync_id,
                    'message' => get_string('sync_already_running', 'local_edulution'),
                ];
            }

            // Also check for very recent jobs (within last 5 seconds) to prevent double-clicks.
            $recent = $DB->get_record_select(
                'local_edulution_sync_jobs',
                "timecreated > ? AND userid = ?",
                [time() - 5, $USER->id]
            );

            if ($recent) {
                return [
                    'success' => false,
                    'syncId' => $recent->sync_id,
                    'message' => get_string('sync_already_running', 'local_edulution'),
                ];
            }

            // Generate sync ID.
            $sync_id = uniqid('sync_', true);

            // Parse options.
            $opts = json_decode($params['options'], true) ?: [];

            // Create job record in database.
            $job = new \stdClass();
            $job->sync_id = $sync_id;
            $job->userid = $USER->id;
            $job->direction = $params['direction'];
            $job->status = 'pending';
            $job->progress = 0;
            $job->processed = 0;
            $job->total = 0;
            $job->created_count = 0;
            $job->updated_count = 0;
            $job->deleted_count = 0;
            $job->error_count = 0;
            $job->error_details = '[]';
            $job->log_entries = json_encode([
                ['type' => 'info', 'message' => 'Sync job queued, waiting for background processing...'],
            ]);
            $job->timecreated = time();
            $job->timemodified = time();

            $DB->insert_record('local_edulution_sync_jobs', $job);

            // Queue ad-hoc task for background processing.
            $task = new adhoc_sync_task();
            $task->set_custom_data([
                'sync_id' => $sync_id,
                'direction' => $params['direction'],
                'user_id' => $USER->id,
                'options' => $opts,
            ]);
            $task->set_userid($USER->id);

            \core\task\manager::queue_adhoc_task($task);

            return [
                'success' => true,
                'syncId' => $sync_id,
                'message' => get_string('sync_queued', 'local_edulution'),
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'syncId' => '',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Return type for start_sync.
     *
     * @return external_single_structure
     */
    public static function start_sync_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether sync started successfully'),
            'syncId' => new external_value(PARAM_RAW, 'Sync job ID'),
            'message' => new external_value(PARAM_RAW, 'Status message'),
        ]);
    }

    /**
     * Parameters for get_sync_status.
     *
     * @return external_function_parameters
     */
    public static function get_sync_status_parameters(): external_function_parameters {
        return new external_function_parameters([
            'syncId' => new external_value(PARAM_RAW, 'Sync job ID'),
        ]);
    }

    /**
     * Get the status of a sync job.
     *
     * @param string $syncId Sync job ID.
     * @return array Status data.
     */
    public static function get_sync_status(string $syncId): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::get_sync_status_parameters(), [
            'syncId' => $syncId,
        ]);

        // Check capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/edulution:sync', $context);

        $job = $DB->get_record('local_edulution_sync_jobs', ['sync_id' => $params['syncId']]);

        if (!$job) {
            return [
                'success' => false,
                'status' => 'not_found',
                'progress' => 0,
                'processed' => 0,
                'total' => 0,
                'errors' => 0,
                'message' => 'Sync job not found',
                'newLogEntries' => [],
                'stats' => [],
            ];
        }

        // Parse JSON fields.
        $log_entries = json_decode($job->log_entries ?: '[]', true) ?: [];
        $error_details = json_decode($job->error_details ?: '[]', true) ?: [];

        // Calculate duration.
        $started = $job->timecreated;
        $finished = $job->timefinished ?: time();
        $duration = $finished - $started;

        return [
            'success' => true,
            'status' => $job->status,
            'progress' => (int) $job->progress,
            'processed' => (int) $job->processed,
            'total' => (int) $job->total,
            'errors' => (int) $job->error_count,
            'message' => self::get_status_message_from_record($job),
            'newLogEntries' => $log_entries,
            'stats' => [
                'created' => (int) $job->created_count,
                'updated' => (int) $job->updated_count,
                'deleted' => (int) $job->deleted_count,
                'errors' => (int) $job->error_count,
            ],
            'created' => (int) $job->created_count,
            'updated' => (int) $job->updated_count,
            'deleted' => (int) $job->deleted_count,
            'duration' => $duration,
            'errorCount' => (int) $job->error_count,
            'errorDetails' => $error_details,
        ];
    }

    /**
     * Get a human-readable status message from a database record.
     *
     * @param \stdClass $job Sync job record.
     * @return string Status message.
     */
    protected static function get_status_message_from_record(\stdClass $job): string {
        $error_details = json_decode($job->error_details ?: '[]', true) ?: [];

        switch ($job->status) {
            case 'pending':
                return get_string('sync_status_pending', 'local_edulution');
            case 'processing':
                if ($job->total > 0) {
                    return get_string('sync_status_processing_progress', 'local_edulution', [
                        'processed' => $job->processed,
                        'total' => $job->total,
                    ]);
                }
                return get_string('sync_status_processing', 'local_edulution');
            case 'completed':
                return get_string('sync_status_completed', 'local_edulution');
            case 'failed':
                $error = !empty($error_details) ? $error_details[0] : 'Unknown error';
                return get_string('sync_status_failed', 'local_edulution', $error);
            case 'cancelled':
                return get_string('sync_status_cancelled', 'local_edulution');
            default:
                return 'Unknown status';
        }
    }

    /**
     * Return type for get_sync_status.
     *
     * @return external_single_structure
     */
    public static function get_sync_status_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether status was retrieved'),
            'status' => new external_value(PARAM_ALPHA, 'Sync status'),
            'progress' => new external_value(PARAM_INT, 'Progress percentage'),
            'processed' => new external_value(PARAM_INT, 'Items processed'),
            'total' => new external_value(PARAM_INT, 'Total items'),
            'errors' => new external_value(PARAM_INT, 'Number of errors'),
            'message' => new external_value(PARAM_RAW, 'Status message'),
            'newLogEntries' => new external_multiple_structure(
                new external_single_structure([
                    'type' => new external_value(PARAM_ALPHA, 'Log entry type'),
                    'message' => new external_value(PARAM_RAW, 'Log message'),
                ]),
                'New log entries',
                VALUE_DEFAULT,
                []
            ),
            'stats' => new external_single_structure([
                'created' => new external_value(PARAM_INT, 'Created count', VALUE_DEFAULT, 0),
                'updated' => new external_value(PARAM_INT, 'Updated count', VALUE_DEFAULT, 0),
                'deleted' => new external_value(PARAM_INT, 'Deleted count', VALUE_DEFAULT, 0),
                'errors' => new external_value(PARAM_INT, 'Error count', VALUE_DEFAULT, 0),
            ], 'Statistics', VALUE_DEFAULT, []),
            'created' => new external_value(PARAM_INT, 'Created count', VALUE_DEFAULT, 0),
            'updated' => new external_value(PARAM_INT, 'Updated count', VALUE_DEFAULT, 0),
            'deleted' => new external_value(PARAM_INT, 'Deleted count', VALUE_DEFAULT, 0),
            'duration' => new external_value(PARAM_INT, 'Duration in seconds', VALUE_DEFAULT, 0),
            'errorCount' => new external_value(PARAM_INT, 'Error count', VALUE_DEFAULT, 0),
            'errorDetails' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Error detail'),
                'Error details',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Parameters for cancel_sync.
     *
     * @return external_function_parameters
     */
    public static function cancel_sync_parameters(): external_function_parameters {
        return new external_function_parameters([
            'syncId' => new external_value(PARAM_RAW, 'Sync job ID'),
        ]);
    }

    /**
     * Cancel a running sync job.
     *
     * @param string $syncId Sync job ID.
     * @return array Result.
     */
    public static function cancel_sync(string $syncId): array {
        global $DB;

        // Validate parameters.
        $params = self::validate_parameters(self::cancel_sync_parameters(), [
            'syncId' => $syncId,
        ]);

        // Check capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/edulution:sync', $context);

        $job = $DB->get_record('local_edulution_sync_jobs', ['sync_id' => $params['syncId']]);

        if (!$job) {
            return [
                'success' => false,
                'message' => 'Sync job not found',
            ];
        }

        // Mark as cancelled.
        $log_entries = json_decode($job->log_entries ?: '[]', true) ?: [];
        $log_entries[] = ['type' => 'warning', 'message' => 'Sync cancelled by user'];

        $job->status = 'cancelled';
        $job->timefinished = time();
        $job->timemodified = time();
        $job->log_entries = json_encode($log_entries);

        $DB->update_record('local_edulution_sync_jobs', $job);

        return [
            'success' => true,
            'message' => get_string('sync_cancelled', 'local_edulution'),
        ];
    }

    /**
     * Return type for cancel_sync.
     *
     * @return external_single_structure
     */
    public static function cancel_sync_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether cancellation was successful'),
            'message' => new external_value(PARAM_RAW, 'Status message'),
        ]);
    }

    /**
     * Parameters for get_ongoing_sync.
     *
     * @return external_function_parameters
     */
    public static function get_ongoing_sync_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Check if there is an ongoing sync job.
     *
     * @return array Ongoing sync data.
     */
    public static function get_ongoing_sync(): array {
        global $DB, $USER;

        // Check capability.
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/edulution:sync', $context);

        // Find processing or pending job for this user.
        $job = $DB->get_record_select(
            'local_edulution_sync_jobs',
            "status IN ('pending', 'processing') AND userid = ?",
            [$USER->id],
            '*',
            IGNORE_MULTIPLE
        );

        if ($job) {
            return [
                'syncId' => $job->sync_id,
                'status' => $job->status,
                'progress' => (int) $job->progress,
                'direction' => $job->direction,
            ];
        }

        return [
            'syncId' => '',
            'status' => '',
            'progress' => 0,
            'direction' => '',
        ];
    }

    /**
     * Return type for get_ongoing_sync.
     *
     * @return external_single_structure
     */
    public static function get_ongoing_sync_returns(): external_single_structure {
        return new external_single_structure([
            'syncId' => new external_value(PARAM_RAW, 'Sync job ID'),
            'status' => new external_value(PARAM_RAW, 'Sync status'),
            'progress' => new external_value(PARAM_INT, 'Progress percentage'),
            'direction' => new external_value(PARAM_RAW, 'Sync direction'),
        ]);
    }

    /**
     * Determine if a Keycloak user is a teacher or admin based on attributes.
     *
     * Static version for use in preview.
     * Checks LDAP_ENTRY_DN for OU=Teachers (linuxmuster.net style).
     *
     * @param array $kc_user Keycloak user data with attributes.
     * @return bool True if user is a teacher or admin.
     */
    protected static function is_teacher_user_static(array $kc_user): bool {
        $username = strtolower($kc_user['username'] ?? '');
        $attributes = $kc_user['attributes'] ?? [];

        // Check for admin usernames - treat them as teachers.
        $admin_usernames = ['global-admin', 'admin', 'administrator', 'moodle-admin', 'keycloak-admin'];
        if (in_array($username, $admin_usernames) || strpos($username, 'admin') !== false) {
            return true;
        }

        // PRIMARY: Check LDAP_ENTRY_DN for OU=Teachers (linuxmuster.net / LDAP federated users).
        if (isset($attributes['LDAP_ENTRY_DN'])) {
            $dn = is_array($attributes['LDAP_ENTRY_DN'])
                ? ($attributes['LDAP_ENTRY_DN'][0] ?? '')
                : $attributes['LDAP_ENTRY_DN'];
            if (stripos($dn, 'OU=Teachers') !== false) {
                return true;
            }
        }

        // Fallback: Check sophomorixRole attribute.
        if (isset($attributes['sophomorixRole'])) {
            $role = is_array($attributes['sophomorixRole'])
                ? ($attributes['sophomorixRole'][0] ?? '')
                : $attributes['sophomorixRole'];
            if (strcasecmp($role, 'teacher') === 0) {
                return true;
            }
        }

        // Fallback: Check role attribute.
        if (isset($attributes['role'])) {
            $role = is_array($attributes['role'])
                ? ($attributes['role'][0] ?? '')
                : $attributes['role'];
            if (strcasecmp($role, 'teacher') === 0) {
                return true;
            }
        }

        return false;
    }

}
