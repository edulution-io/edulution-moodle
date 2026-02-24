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
 * Enrollment synchronization from Keycloak group memberships to Moodle.
 *
 * Handles:
 * - Enrolling students in courses based on group membership
 * - Enrolling teachers with editingteacher role
 * - Removing enrollments for users no longer in groups
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->libdir . '/enrollib.php');

/**
 * Enrollment synchronization class.
 */
class enrollment_sync {

    /** @var keycloak_client Keycloak API client */
    protected keycloak_client $client;

    /** @var group_classifier Group classifier */
    protected group_classifier $classifier;

    /** @var bool Dry run mode */
    protected bool $dry_run = false;

    /** @var bool Whether to auto-enroll teachers */
    protected bool $auto_enroll_teachers = true;

    /** @var bool Whether to auto-enroll students */
    protected bool $auto_enroll_students = true;

    /** @var bool Whether to unenroll removed users */
    protected bool $unenroll_removed = true;

    /** @var array Sync statistics */
    protected array $stats = [
        'enrollments_created' => 0,
        'enrollments_skipped' => 0,
        'unenrollments' => 0,
        'errors' => 0,
    ];

    /** @var array Log messages */
    protected array $log = [];

    /** @var array Expected enrollments for delta sync [course_id => [user_id => role]] */
    protected array $expected_enrollments = [];

    /**
     * Constructor.
     *
     * @param keycloak_client $client Keycloak API client.
     * @param group_classifier $classifier Group classifier.
     */
    public function __construct(keycloak_client $client, group_classifier $classifier) {
        $this->client = $client;
        $this->classifier = $classifier;
        $this->load_settings();
    }

    /**
     * Load settings from plugin configuration.
     */
    protected function load_settings(): void {
        $this->auto_enroll_teachers = (bool) (get_config('local_edulution', 'auto_enroll_teachers') ?? true);
        $this->auto_enroll_students = (bool) (get_config('local_edulution', 'auto_enroll_students') ?? true);
        $this->unenroll_removed = (bool) (get_config('local_edulution', 'unenroll_removed_users') ?? true);
    }

    /**
     * Enable or disable dry run mode.
     *
     * @param bool $dry_run Whether to enable dry run.
     * @return self
     */
    public function set_dry_run(bool $dry_run): self {
        $this->dry_run = $dry_run;
        return $this;
    }

    /**
     * Set enrollment options.
     *
     * @param bool $teachers Whether to auto-enroll teachers.
     * @param bool $students Whether to auto-enroll students.
     * @param bool $unenroll Whether to unenroll removed users.
     * @return self
     */
    public function set_options(bool $teachers = true, bool $students = true, bool $unenroll = true): self {
        $this->auto_enroll_teachers = $teachers;
        $this->auto_enroll_students = $students;
        $this->unenroll_removed = $unenroll;
        return $this;
    }

    /**
     * Synchronize enrollments for all courses.
     *
     * @param array $synced_users User sync results [username => info].
     * @param array $synced_courses Course sync results [group_name => info].
     * @param array $groups All Keycloak groups (classified).
     * @return array Sync results.
     */
    public function sync(array $synced_users, array $synced_courses, array $groups): array {
        $this->log('info', 'Starting enrollment synchronization...');

        // Classify groups if not already done.
        $classified = isset($groups[group_classifier::TYPE_CLASS])
            ? $groups
            : $this->classifier->classify_groups($groups);

        // Process class courses.
        foreach ($classified[group_classifier::TYPE_CLASS] as $group) {
            $this->sync_class_enrollments($group, $synced_users, $synced_courses, $classified[group_classifier::TYPE_TEACHER]);
        }

        // Process project courses.
        foreach ($classified[group_classifier::TYPE_PROJECT] as $group) {
            $this->sync_project_enrollments($group, $synced_users, $synced_courses);
        }

        // Clean up stale enrollments.
        if ($this->unenroll_removed) {
            $this->cleanup_enrollments($synced_courses);
        }

        $this->log('info', 'Enrollment synchronization complete');
        $this->log('info', "Created: {$this->stats['enrollments_created']}, Removed: {$this->stats['unenrollments']}");

        return $this->get_results();
    }

    /**
     * Synchronize enrollments for a class course.
     *
     * @param array $group Class group data.
     * @param array $synced_users Synced users.
     * @param array $synced_courses Synced courses.
     * @param array $teacher_groups All teacher groups.
     */
    protected function sync_class_enrollments(
        array $group,
        array $synced_users,
        array $synced_courses,
        array $teacher_groups
    ): void {
        $group_name = $group['name'];
        $group_id = $group['id'];

        // Find the corresponding course.
        if (!isset($synced_courses[$group_name])) {
            return;
        }

        $course_info = $synced_courses[$group_name];
        $course_id = $course_info['course_id'];

        if ($course_id < 1) {
            return; // Skip placeholder courses from dry run.
        }

        // Initialize expected enrollments for this course.
        if (!isset($this->expected_enrollments[$course_id])) {
            $this->expected_enrollments[$course_id] = [];
        }

        // Enroll students from this group.
        if ($this->auto_enroll_students) {
            try {
                $members = $this->client->get_all_group_members($group_id);

                foreach ($members as $member) {
                    $username = $member['username'];
                    if (!isset($synced_users[$username])) {
                        continue;
                    }

                    $user_info = $synced_users[$username];
                    $user_id = $user_info['moodle_id'];

                    if ($user_id < 1) {
                        continue; // Skip placeholder users from dry run.
                    }

                    $enrolled = $this->enroll_user($user_id, $course_id, 'student');
                    if ($enrolled) {
                        $this->stats['enrollments_created']++;
                    }

                    $this->expected_enrollments[$course_id][$user_id] = 'student';
                }
            } catch (\Exception $e) {
                $this->log('error', "Failed to get members for group {$group_name}: " . $e->getMessage());
                $this->stats['errors']++;
            }
        }

        // Find and enroll teachers from the corresponding teacher group.
        if ($this->auto_enroll_teachers) {
            $teacher_group = $this->classifier->find_teacher_group($group_name, $teacher_groups);

            if ($teacher_group) {
                try {
                    $teachers = $this->client->get_all_group_members($teacher_group['id']);

                    foreach ($teachers as $teacher) {
                        $username = $teacher['username'];
                        if (!isset($synced_users[$username])) {
                            continue;
                        }

                        $user_info = $synced_users[$username];
                        $user_id = $user_info['moodle_id'];

                        if ($user_id < 1) {
                            continue;
                        }

                        $enrolled = $this->enroll_user($user_id, $course_id, 'editingteacher');
                        if ($enrolled) {
                            $this->stats['enrollments_created']++;
                        }

                        $this->expected_enrollments[$course_id][$user_id] = 'editingteacher';
                    }
                } catch (\Exception $e) {
                    $this->log('error', "Failed to get teachers for {$teacher_group['name']}: " . $e->getMessage());
                    $this->stats['errors']++;
                }
            }
        }
    }

    /**
     * Synchronize enrollments for a project course.
     *
     * @param array $group Project group data.
     * @param array $synced_users Synced users.
     * @param array $synced_courses Synced courses.
     */
    protected function sync_project_enrollments(
        array $group,
        array $synced_users,
        array $synced_courses
    ): void {
        $group_name = $group['name'];
        $group_id = $group['id'];

        // Find the corresponding course.
        if (!isset($synced_courses[$group_name])) {
            return;
        }

        $course_info = $synced_courses[$group_name];
        $course_id = $course_info['course_id'];

        if ($course_id < 1) {
            return;
        }

        // Initialize expected enrollments for this course.
        if (!isset($this->expected_enrollments[$course_id])) {
            $this->expected_enrollments[$course_id] = [];
        }

        // Enroll members based on their role (teacher/student).
        try {
            $members = $this->client->get_all_group_members($group_id);

            foreach ($members as $member) {
                $username = $member['username'];
                if (!isset($synced_users[$username])) {
                    continue;
                }

                $user_info = $synced_users[$username];
                $user_id = $user_info['moodle_id'];
                $is_teacher = $user_info['is_teacher'] ?? false;

                if ($user_id < 1) {
                    continue;
                }

                // Check enrollment settings.
                if ($is_teacher && !$this->auto_enroll_teachers) {
                    continue;
                }
                if (!$is_teacher && !$this->auto_enroll_students) {
                    continue;
                }

                $role = $is_teacher ? 'editingteacher' : 'student';
                $enrolled = $this->enroll_user($user_id, $course_id, $role);
                if ($enrolled) {
                    $this->stats['enrollments_created']++;
                }

                $this->expected_enrollments[$course_id][$user_id] = $role;
            }
        } catch (\Exception $e) {
            $this->log('error', "Failed to get members for project {$group_name}: " . $e->getMessage());
            $this->stats['errors']++;
        }
    }

    /**
     * Enroll a user in a course with the specified role.
     *
     * @param int $user_id Moodle user ID.
     * @param int $course_id Course ID.
     * @param string $role_shortname Role shortname ('student', 'editingteacher', etc.).
     * @return bool True if newly enrolled.
     */
    protected function enroll_user(int $user_id, int $course_id, string $role_shortname): bool {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => $role_shortname]);
        if (!$role) {
            $this->log('error', "Role {$role_shortname} not found");
            return false;
        }

        $context = \context_course::instance($course_id);

        // Check if already enrolled.
        if (is_enrolled($context, $user_id)) {
            // Ensure the role is assigned.
            if (!$this->dry_run) {
                role_assign($role->id, $user_id, $context->id);
            }
            $this->stats['enrollments_skipped']++;
            return false;
        }

        if ($this->dry_run) {
            $this->log('dry_run', "Would enroll user {$user_id} in course {$course_id} as {$role_shortname}");
            return true;
        }

        // Get or create manual enrol instance.
        $enrol_plugin = enrol_get_plugin('manual');
        $instances = $DB->get_records('enrol', ['courseid' => $course_id, 'enrol' => 'manual']);

        if (empty($instances)) {
            $course = $DB->get_record('course', ['id' => $course_id]);
            $enrol_plugin->add_instance($course);
            $instances = $DB->get_records('enrol', ['courseid' => $course_id, 'enrol' => 'manual']);
        }

        $instance = reset($instances);

        try {
            $enrol_plugin->enrol_user($instance, $user_id, $role->id);
            $this->log('debug', "Enrolled user {$user_id} in course {$course_id} as {$role_shortname}");
            return true;
        } catch (\Exception $e) {
            $this->log('error', "Failed to enroll user {$user_id} in course {$course_id}: " . $e->getMessage());
            $this->stats['errors']++;
            return false;
        }
    }

    /**
     * Clean up stale enrollments (users no longer in Keycloak groups).
     *
     * @param array $synced_courses Synced courses.
     */
    protected function cleanup_enrollments(array $synced_courses): void {
        global $DB;

        $this->log('info', 'Cleaning up stale enrollments...');

        // Get all sync-managed courses.
        $sync_courses = $DB->get_records_sql(
            "SELECT id, shortname, idnumber FROM {course}
             WHERE idnumber LIKE ? OR idnumber LIKE ?",
            [course_sync::PREFIX_CLASS . '%', course_sync::PREFIX_PROJECT . '%']
        );

        foreach ($sync_courses as $course) {
            $expected = $this->expected_enrollments[$course->id] ?? [];

            // Get current manual enrollments.
            $current_enrollments = $DB->get_records_sql(
                "SELECT ue.userid, u.username
                 FROM {user_enrolments} ue
                 JOIN {enrol} e ON e.id = ue.enrolid
                 JOIN {user} u ON u.id = ue.userid
                 WHERE e.courseid = ? AND e.enrol = 'manual'",
                [$course->id]
            );

            foreach ($current_enrollments as $enrollment) {
                if (!isset($expected[$enrollment->userid])) {
                    // User should not be in this course anymore.
                    $this->unenroll_user($enrollment->userid, $course->id);
                    $this->log('info', "Unenrolled {$enrollment->username} from {$course->shortname}");
                    $this->stats['unenrollments']++;
                }
            }
        }
    }

    /**
     * Unenroll a user from a course.
     *
     * @param int $user_id User ID.
     * @param int $course_id Course ID.
     */
    protected function unenroll_user(int $user_id, int $course_id): void {
        global $DB;

        if ($this->dry_run) {
            $this->log('dry_run', "Would unenroll user {$user_id} from course {$course_id}");
            return;
        }

        $enrol_plugin = enrol_get_plugin('manual');
        $instances = $DB->get_records('enrol', ['courseid' => $course_id, 'enrol' => 'manual']);

        foreach ($instances as $instance) {
            $enrol_plugin->unenrol_user($instance, $user_id);
        }
    }

    /**
     * Sync enrollments for a single course by group ID.
     *
     * @param string $keycloak_group_id Keycloak group ID.
     * @param int $course_id Moodle course ID.
     * @param array $synced_users Synced users.
     * @param string $course_type Course type ('class' or 'project').
     * @return array Enrollment results.
     */
    public function sync_course_enrollments(
        string $keycloak_group_id,
        int $course_id,
        array $synced_users,
        string $course_type = 'class'
    ): array {
        $enrolled_count = 0;

        try {
            $members = $this->client->get_all_group_members($keycloak_group_id);

            foreach ($members as $member) {
                $username = $member['username'];
                if (!isset($synced_users[$username])) {
                    continue;
                }

                $user_info = $synced_users[$username];
                $user_id = $user_info['moodle_id'];
                $is_teacher = $user_info['is_teacher'] ?? false;

                if ($user_id < 1) {
                    continue;
                }

                // Determine role based on course type and user type.
                if ($course_type === 'class') {
                    $role = 'student';
                } else {
                    $role = $is_teacher ? 'editingteacher' : 'student';
                }

                if ($this->enroll_user($user_id, $course_id, $role)) {
                    $enrolled_count++;
                }
            }
        } catch (\Exception $e) {
            $this->log('error', "Failed to sync enrollments for course {$course_id}: " . $e->getMessage());
            $this->stats['errors']++;
        }

        return [
            'enrolled' => $enrolled_count,
            'errors' => $this->stats['errors'],
        ];
    }

    /**
     * Log a message.
     *
     * @param string $level Log level.
     * @param string $message Log message.
     */
    protected function log(string $level, string $message): void {
        $this->log[] = [
            'time' => time(),
            'level' => $level,
            'message' => $message,
        ];

        if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
            $prefix = strtoupper($level) === 'DRY_RUN' ? '[DRY-RUN]' : "[{$level}]";
            mtrace("  {$prefix} {$message}");
        }
    }

    /**
     * Get sync results.
     *
     * @return array Results.
     */
    public function get_results(): array {
        return [
            'success' => $this->stats['errors'] === 0,
            'stats' => $this->stats,
            'log' => $this->log,
            'expected_enrollments' => $this->expected_enrollments,
        ];
    }

    /**
     * Get sync statistics.
     *
     * @return array Statistics.
     */
    public function get_stats(): array {
        return $this->stats;
    }

    /**
     * Get expected enrollments.
     *
     * @return array Expected enrollments [course_id => [user_id => role]].
     */
    public function get_expected_enrollments(): array {
        return $this->expected_enrollments;
    }

    /**
     * Get log messages.
     *
     * @return array Log entries.
     */
    public function get_log(): array {
        return $this->log;
    }
}
