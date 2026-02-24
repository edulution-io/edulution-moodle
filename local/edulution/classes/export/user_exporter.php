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
 * User data exporter.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

/**
 * Exporter for user data.
 *
 * Exports users with profile fields, roles and role assignments,
 * with support for anonymization.
 */
class user_exporter extends base_exporter {

    /** @var int Users exported counter */
    protected int $users_exported = 0;

    /** @var int Profile pictures exported counter */
    protected int $profile_pictures_exported = 0;

    /** @var array Auth method counts */
    protected array $auth_counts = [];

    /**
     * Get the exporter name.
     *
     * @return string Human-readable name.
     */
    public function get_name(): string {
        return get_string('exporter_users', 'local_edulution');
    }

    /**
     * Get the language string key.
     *
     * @return string Language string key.
     */
    public function get_string_key(): string {
        return 'users';
    }

    /**
     * Get total count for progress tracking.
     *
     * @return int Number of users to export.
     */
    public function get_total_count(): int {
        global $DB;

        $where = "deleted = 0 AND username NOT IN ('guest')";
        $params = [];

        if (!empty($this->options->user_ids)) {
            list($insql, $params) = $DB->get_in_or_equal($this->options->user_ids);
            $where .= " AND id {$insql}";
        }

        return $DB->count_records_select('user', $where, $params);
    }

    /**
     * Export user data.
     *
     * @return array Exported user data.
     * @throws \moodle_exception On export failure.
     */
    public function export(): array {
        global $DB;

        $this->log('info', 'Exporting user data...');

        if ($this->options->anonymize_users) {
            $this->log('info', 'User data will be anonymized');
        }

        // Create subdirectories.
        $usersDir = $this->get_subdir('users');
        if ($this->options->export_profile_pictures) {
            $this->get_subdir('users/pictures');
        }

        // Build query.
        $where = "deleted = 0 AND username NOT IN ('guest')";
        $params = [];

        if (!empty($this->options->user_ids)) {
            list($insql, $params) = $DB->get_in_or_equal($this->options->user_ids);
            $where .= " AND id {$insql}";
        }

        // Get users.
        $users = $DB->get_records_select('user', $where, $params, 'id ASC');
        $total = count($users);
        $count = 0;

        $exportedUsers = [];

        foreach ($users as $user) {
            $count++;

            // Update progress every 50 users.
            if ($count % 50 === 0 || $count === $total || $count === 1) {
                $this->update_progress($count, "Exporting user {$count}/{$total}");
            }

            // Skip admin and guest if anonymizing.
            if ($this->options->anonymize_users && $user->id <= 2) {
                continue;
            }

            $userData = $this->export_user($user);
            $exportedUsers[] = $userData;
            $this->users_exported++;

            // Track auth methods.
            $auth = $userData['auth'] ?? 'unknown';
            $this->auth_counts[$auth] = ($this->auth_counts[$auth] ?? 0) + 1;
        }

        // Export roles.
        $roles = $this->export_roles();

        // Build result data.
        $data = [
            'export_timestamp' => date('c'),
            'anonymized' => $this->options->anonymize_users,
            'total_users' => count($exportedUsers),
            'users' => $exportedUsers,
            'roles' => $roles,
            'statistics' => [
                'total_users' => count($exportedUsers),
                'with_profile_picture' => $this->profile_pictures_exported,
                'auth_methods' => $this->auth_counts,
            ],
        ];

        // Write users data.
        $this->write_json($data, 'users/users.json');

        // Write roles separately.
        $this->write_json([
            'export_timestamp' => date('c'),
            'roles' => $roles,
        ], 'users/roles.json');

        // Update statistics.
        $this->stats = [
            'total_users' => count($exportedUsers),
            'profile_pictures' => $this->profile_pictures_exported,
            'auth_methods' => $this->auth_counts,
            'roles_exported' => count($roles['roles'] ?? []),
            'anonymized' => $this->options->anonymize_users,
        ];

        $this->log('info', sprintf(
            'User export complete: %d users, %d profile pictures',
            $this->stats['total_users'],
            $this->stats['profile_pictures']
        ));

        return $data;
    }

    /**
     * Export a single user.
     *
     * @param object $user User record.
     * @return array User data.
     */
    protected function export_user(object $user): array {
        $anonymize = $this->options->anonymize_users;

        $userData = [
            'id' => (int) $user->id,
            'username' => $user->username,
            'email' => $anonymize ? $this->anonymize_email($user->id, $user->email) : $user->email,
            'firstname' => $anonymize ? 'User' : $user->firstname,
            'lastname' => $anonymize ? (string) $user->id : $user->lastname,
            'auth' => $user->auth,
            'confirmed' => (bool) $user->confirmed,
            'suspended' => (bool) $user->suspended,
            'idnumber' => $user->idnumber ?: null,
            'institution' => $anonymize ? null : ($user->institution ?: null),
            'department' => $anonymize ? null : ($user->department ?: null),
            'phone1' => $anonymize ? null : ($user->phone1 ?: null),
            'phone2' => $anonymize ? null : ($user->phone2 ?: null),
            'address' => $anonymize ? null : ($user->address ?: null),
            'city' => $user->city ?: null,
            'country' => $user->country ?: null,
            'lang' => $user->lang ?: null,
            'timezone' => $user->timezone ?: null,
            'description' => $anonymize ? null : ($user->description ?: null),
            'firstaccess' => $this->format_timestamp($user->firstaccess),
            'lastaccess' => $this->format_timestamp($user->lastaccess),
            'lastlogin' => $this->format_timestamp($user->lastlogin),
            'timecreated' => $this->format_timestamp($user->timecreated),
            'timemodified' => $this->format_timestamp($user->timemodified),
        ];

        // Get profile fields.
        $profileFields = $this->get_profile_fields($user->id, $anonymize);
        if (!empty($profileFields)) {
            $userData['profile_fields'] = $profileFields;
        }

        // Get system roles.
        $systemRoles = $this->get_system_roles($user->id);
        if (!empty($systemRoles)) {
            $userData['system_roles'] = $systemRoles;
        }

        // Export profile picture.
        if ($this->options->export_profile_pictures && $user->picture > 0) {
            $picturePath = $this->export_profile_picture($user->id);
            if ($picturePath) {
                $userData['profile_picture'] = $picturePath;
                $this->profile_pictures_exported++;
            }
        }

        // Get course enrollments if needed.
        if ($this->options->include_enrollments) {
            $enrollments = $this->get_user_enrollments($user->id);
            if (!empty($enrollments)) {
                $userData['enrollments'] = $enrollments;
            }
        }

        return $userData;
    }

    /**
     * Get user custom profile fields.
     *
     * @param int $userid User ID.
     * @param bool $anonymize Whether to anonymize data.
     * @return array Profile field name => value mapping.
     */
    protected function get_profile_fields(int $userid, bool $anonymize): array {
        global $DB;

        $sql = "SELECT f.shortname, f.name, f.datatype, f.param1, d.data
                FROM {user_info_data} d
                JOIN {user_info_field} f ON f.id = d.fieldid
                WHERE d.userid = ?";

        $fields = $DB->get_records_sql($sql, [$userid]);
        $result = [];

        foreach ($fields as $field) {
            // Skip empty values.
            if ($field->data === '' || $field->data === null) {
                continue;
            }

            // Anonymize if needed (for text/textarea fields).
            if ($anonymize && in_array($field->datatype, ['text', 'textarea'])) {
                $value = '[ANONYMIZED]';
            } else {
                $value = $field->data;
            }

            $result[$field->shortname] = [
                'name' => $field->name,
                'type' => $field->datatype,
                'value' => $value,
            ];
        }

        return $result;
    }

    /**
     * Get user's system-level roles.
     *
     * @param int $userid User ID.
     * @return array Array of role data.
     */
    protected function get_system_roles(int $userid): array {
        global $DB;

        $systemContext = \context_system::instance();

        $sql = "SELECT r.id, r.shortname, r.name, r.archetype
                FROM {role_assignments} ra
                JOIN {role} r ON r.id = ra.roleid
                WHERE ra.userid = ? AND ra.contextid = ?";

        $roles = $DB->get_records_sql($sql, [$userid, $systemContext->id]);

        return array_map(function ($role) {
            return [
                'id' => (int) $role->id,
                'shortname' => $role->shortname,
                'name' => $role->name,
                'archetype' => $role->archetype,
            ];
        }, array_values($roles));
    }

    /**
     * Get user's course enrollments.
     *
     * @param int $userid User ID.
     * @return array Enrollment data.
     */
    protected function get_user_enrollments(int $userid): array {
        global $DB;

        $sql = "SELECT e.courseid, c.shortname as course_shortname, c.fullname as course_fullname,
                       ue.status, ue.timestart, ue.timeend, ue.timecreated,
                       r.shortname as role_shortname
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {course} c ON c.id = e.courseid
                LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = ?
                LEFT JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id
                LEFT JOIN {role} r ON r.id = ra.roleid
                WHERE ue.userid = ?
                ORDER BY c.fullname";

        $enrollments = $DB->get_records_sql($sql, [CONTEXT_COURSE, $userid]);

        $result = [];
        foreach ($enrollments as $enrollment) {
            // Check if we should export this course.
            if (!$this->should_export_course($enrollment->courseid)) {
                continue;
            }

            $result[] = [
                'course_id' => (int) $enrollment->courseid,
                'course_shortname' => $enrollment->course_shortname,
                'course_fullname' => $enrollment->course_fullname,
                'status' => $enrollment->status == 0 ? 'active' : 'suspended',
                'role' => $enrollment->role_shortname,
                'timestart' => $enrollment->timestart ? $this->format_timestamp($enrollment->timestart) : null,
                'timeend' => $enrollment->timeend ? $this->format_timestamp($enrollment->timeend) : null,
                'timecreated' => $this->format_timestamp($enrollment->timecreated),
            ];
        }

        return $result;
    }

    /**
     * Export user profile picture.
     *
     * @param int $userid User ID.
     * @return string|null Relative path to exported file or null.
     */
    protected function export_profile_picture(int $userid): ?string {
        $context = \context_user::instance($userid, IGNORE_MISSING);
        if (!$context) {
            return null;
        }

        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'user', 'icon', 0, 'filename', false);

        foreach ($files as $file) {
            $filename = $file->get_filename();
            // Get the main profile picture (f1 = full size).
            if ($filename === 'f1.png' || $filename === 'f1.jpg') {
                $extension = pathinfo($filename, PATHINFO_EXTENSION);
                $destPath = "users/pictures/{$userid}.{$extension}";

                // Copy file content.
                $fullPath = $this->basedir . '/' . $destPath;
                $file->copy_content_to($fullPath);

                return $destPath;
            }
        }

        return null;
    }

    /**
     * Export roles and capabilities.
     *
     * @return array Roles data.
     */
    protected function export_roles(): array {
        global $DB;

        // Get all roles.
        $roles = $DB->get_records('role', null, 'sortorder ASC');

        $rolesData = [];
        foreach ($roles as $role) {
            $roleData = [
                'id' => (int) $role->id,
                'shortname' => $role->shortname,
                'name' => $role->name,
                'description' => $role->description,
                'archetype' => $role->archetype,
                'sortorder' => (int) $role->sortorder,
            ];

            // Get role assignments count by context level.
            $sql = "SELECT ctx.contextlevel, COUNT(*) as count
                    FROM {role_assignments} ra
                    JOIN {context} ctx ON ctx.id = ra.contextid
                    WHERE ra.roleid = ?
                    GROUP BY ctx.contextlevel";
            $counts = $DB->get_records_sql($sql, [$role->id]);

            $assignmentCounts = [];
            foreach ($counts as $count) {
                $levelName = $this->get_context_level_name($count->contextlevel);
                $assignmentCounts[$levelName] = (int) $count->count;
            }
            $roleData['assignment_counts'] = $assignmentCounts;

            $rolesData[] = $roleData;
        }

        // Get role allow assignments/overrides.
        $allowAssigns = $DB->get_records('role_allow_assign');
        $allowOverrides = $DB->get_records('role_allow_override');
        $allowSwitches = $DB->get_records('role_allow_switch');

        return [
            'roles' => $rolesData,
            'allow_assign' => array_map(function ($r) {
                return ['role_id' => (int) $r->roleid, 'allow_assign_id' => (int) $r->allowassign];
            }, array_values($allowAssigns)),
            'allow_override' => array_map(function ($r) {
                return ['role_id' => (int) $r->roleid, 'allow_override_id' => (int) $r->allowoverride];
            }, array_values($allowOverrides)),
            'allow_switch' => array_map(function ($r) {
                return ['role_id' => (int) $r->roleid, 'allow_switch_id' => (int) $r->allowswitch];
            }, array_values($allowSwitches)),
        ];
    }

    /**
     * Get context level name.
     *
     * @param int $level Context level constant.
     * @return string Level name.
     */
    protected function get_context_level_name(int $level): string {
        $names = [
            CONTEXT_SYSTEM => 'system',
            CONTEXT_USER => 'user',
            CONTEXT_COURSECAT => 'category',
            CONTEXT_COURSE => 'course',
            CONTEXT_MODULE => 'module',
            CONTEXT_BLOCK => 'block',
        ];

        return $names[$level] ?? 'unknown';
    }

    /**
     * Anonymize an email address.
     *
     * @param int $userid User ID for consistent anonymization.
     * @param string $email Original email.
     * @return string Anonymized email.
     */
    protected function anonymize_email(int $userid, string $email): string {
        // Use user ID for consistent anonymization.
        return "user_{$userid}@anonymized.local";
    }

    /**
     * Get list of users that would be exported.
     *
     * @return array User list.
     */
    public function get_user_list(): array {
        global $DB;

        $where = "deleted = 0 AND username NOT IN ('guest')";
        $params = [];

        if (!empty($this->options->user_ids)) {
            list($insql, $params) = $DB->get_in_or_equal($this->options->user_ids);
            $where .= " AND id {$insql}";
        }

        return $DB->get_records_select('user', $where, $params, 'lastname, firstname', 'id, username, firstname, lastname, email');
    }
}
