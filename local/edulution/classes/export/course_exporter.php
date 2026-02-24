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
 * Course backup exporter.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/backup/util/includes/backup_includes.php');

/**
 * Exporter for course data and .mbz backups.
 *
 * Uses Moodle's backup API to create .mbz backup files for courses,
 * with support for selective course export and progress tracking.
 */
class course_exporter extends base_exporter {

    /** @var array Cached category paths */
    protected ?array $category_paths = null;

    /** @var int Courses exported counter */
    protected int $courses_exported = 0;

    /** @var int Backups created counter */
    protected int $backups_created = 0;

    /** @var int Backup errors counter */
    protected int $backup_errors = 0;

    /**
     * Get the exporter name.
     *
     * @return string Human-readable name.
     */
    public function get_name(): string {
        return get_string('exporter_courses', 'local_edulution');
    }

    /**
     * Get the language string key.
     *
     * @return string Language string key.
     */
    public function get_string_key(): string {
        return 'courses';
    }

    /**
     * Get total count for progress tracking.
     *
     * @return int Number of courses to export.
     */
    public function get_total_count(): int {
        global $DB;

        // Build query conditions.
        $where = 'id != 1'; // Exclude site course.
        $params = [];

        if (!empty($this->options->course_ids)) {
            list($insql, $params) = $DB->get_in_or_equal($this->options->course_ids);
            $where .= " AND id {$insql}";
        }

        if (!empty($this->options->category_ids)) {
            list($catinsql, $catparams) = $DB->get_in_or_equal($this->options->category_ids);
            $where .= " AND category {$catinsql}";
            $params = array_merge($params, $catparams);
        }

        return $DB->count_records_select('course', $where, $params);
    }

    /**
     * Export course data and create .mbz backups.
     *
     * @return array Exported course data.
     * @throws \moodle_exception On export failure.
     */
    public function export(): array {
        global $DB;

        $this->log('info', 'Exporting courses...');

        // Pre-load category paths for efficiency.
        $this->category_paths = $this->get_all_category_paths();

        // Create subdirectories.
        $coursesDir = $this->get_subdir('courses');
        $backupsDir = $this->get_subdir('courses/backups');

        // Build query conditions.
        $where = 'id != 1'; // Exclude site course.
        $params = [];

        if (!empty($this->options->course_ids)) {
            list($insql, $params) = $DB->get_in_or_equal($this->options->course_ids);
            $where .= " AND id {$insql}";
        }

        if (!empty($this->options->category_ids)) {
            list($catinsql, $catparams) = $DB->get_in_or_equal($this->options->category_ids);
            $where .= " AND category {$catinsql}";
            $params = array_merge($params, $catparams);
        }

        // Get courses.
        $courses = $DB->get_records_select('course', $where, $params, 'id ASC');
        $total = count($courses);
        $count = 0;

        $exportedCourses = [];

        foreach ($courses as $course) {
            $count++;
            $this->update_progress($count, "Exporting course: {$course->shortname} ({$count}/{$total})");

            try {
                $courseData = $this->export_course($course, $backupsDir);
                $exportedCourses[] = $courseData;
                $this->courses_exported++;
            } catch (\Exception $e) {
                $this->log('error', "Failed to export course {$course->shortname}: " . $e->getMessage());
                $exportedCourses[] = [
                    'id' => (int) $course->id,
                    'shortname' => $course->shortname,
                    'fullname' => $course->fullname,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Build result data.
        $data = [
            'export_timestamp' => date('c'),
            'total_courses' => count($exportedCourses),
            'backups_created' => $this->backups_created,
            'backup_errors' => $this->backup_errors,
            'backup_settings' => [
                'include_files' => $this->options->include_course_files,
                'include_user_data' => $this->options->include_user_data,
                'skip_backups' => $this->options->skip_course_backups,
            ],
            'courses' => $exportedCourses,
        ];

        // Write courses metadata.
        $this->write_json($data, 'courses/courses.json');

        // Update statistics.
        $this->stats = [
            'total_courses' => count($exportedCourses),
            'backups_created' => $this->backups_created,
            'backup_errors' => $this->backup_errors,
            'formats' => array_count_values(array_filter(array_column($exportedCourses, 'format'))),
            'total_backup_size' => array_sum(array_filter(array_column($exportedCourses, 'backup_size_bytes'))),
        ];

        $this->log('info', sprintf(
            'Course export complete: %d courses, %d backups (%d errors)',
            $this->stats['total_courses'],
            $this->stats['backups_created'],
            $this->stats['backup_errors']
        ));

        return $data;
    }

    /**
     * Export a single course.
     *
     * @param object $course Course record.
     * @param string $backupsDir Backups directory path.
     * @return array Course data.
     */
    protected function export_course(object $course, string $backupsDir): array {
        // Get course statistics.
        $stats = $this->get_course_statistics($course->id);

        // Get custom fields.
        $customFields = $this->get_course_custom_fields($course->id);

        // Get category path.
        $categoryPath = $this->category_paths[$course->category] ?? '';

        // Build course data.
        $courseData = [
            'id' => (int) $course->id,
            'shortname' => $course->shortname,
            'fullname' => $course->fullname,
            'idnumber' => $course->idnumber,
            'category_id' => (int) $course->category,
            'category_path' => $categoryPath,
            'format' => $course->format,
            'numsections' => $stats['sections'],
            'startdate' => $this->format_date($course->startdate),
            'enddate' => $course->enddate ? $this->format_date($course->enddate) : null,
            'visible' => (bool) $course->visible,
            'summary' => $course->summary,
            'summary_format' => (int) $course->summaryformat,
            'lang' => $course->lang ?: null,
            'enablecompletion' => (bool) $course->enablecompletion,
            'timecreated' => $this->format_timestamp($course->timecreated),
            'timemodified' => $this->format_timestamp($course->timemodified),
            'statistics' => $stats,
        ];

        if (!empty($customFields)) {
            $courseData['custom_fields'] = $customFields;
        }

        // Create .mbz backup if not skipped.
        if (!$this->options->skip_course_backups) {
            try {
                $backupResult = $this->create_course_backup($course, $backupsDir);
                if ($backupResult) {
                    $courseData['backup_file'] = $backupResult['file'];
                    $courseData['backup_size_bytes'] = $backupResult['size'];
                    $courseData['backup_size_formatted'] = $this->format_size($backupResult['size']);
                    $courseData['backup_checksum'] = $backupResult['checksum'];
                    $this->backups_created++;
                }
            } catch (\Exception $e) {
                $this->log('warning', "Backup failed for course {$course->shortname}: " . $e->getMessage());
                $courseData['backup_error'] = $e->getMessage();
                $this->backup_errors++;
            }
        }

        return $courseData;
    }

    /**
     * Get course statistics.
     *
     * @param int $courseid Course ID.
     * @return array Statistics.
     */
    protected function get_course_statistics(int $courseid): array {
        global $DB;

        // Count sections.
        $sections = $DB->count_records('course_sections', ['course' => $courseid]);

        // Count activities by type.
        $sql = "SELECT m.name, COUNT(*) as count
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                WHERE cm.course = ? AND cm.deletioninprogress = 0
                GROUP BY m.name
                ORDER BY count DESC";
        $activities = $DB->get_records_sql($sql, [$courseid]);

        $activityList = [];
        $totalActivities = 0;
        foreach ($activities as $activity) {
            $activityList[] = [
                'type' => $activity->name,
                'count' => (int) $activity->count,
            ];
            $totalActivities += $activity->count;
        }

        // Count enrolled users.
        $sql = "SELECT COUNT(DISTINCT ue.userid) as count
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                WHERE e.courseid = ? AND ue.status = 0";
        $enrolledUsers = $DB->count_records_sql($sql, [$courseid]);

        // Get completion stats if enabled.
        $completionStats = null;
        $course = $DB->get_record('course', ['id' => $courseid]);
        if ($course && $course->enablecompletion) {
            $sql = "SELECT
                        COUNT(CASE WHEN timecompleted IS NOT NULL THEN 1 END) as completed,
                        COUNT(*) as total
                    FROM {course_completions}
                    WHERE course = ?";
            $result = $DB->get_record_sql($sql, [$courseid]);
            if ($result && $result->total > 0) {
                $completionStats = [
                    'completed' => (int) $result->completed,
                    'total' => (int) $result->total,
                    'rate' => round(($result->completed / $result->total) * 100, 1),
                ];
            }
        }

        return [
            'sections' => $sections,
            'activities' => $activityList,
            'total_activities' => $totalActivities,
            'enrolled_users' => $enrolledUsers,
            'completion' => $completionStats,
        ];
    }

    /**
     * Get course custom fields.
     *
     * @param int $courseid Course ID.
     * @return array Custom field data.
     */
    protected function get_course_custom_fields(int $courseid): array {
        global $DB;

        $sql = "SELECT f.shortname, f.name, f.type, d.value, d.intvalue, d.charvalue
                FROM {customfield_data} d
                JOIN {customfield_field} f ON f.id = d.fieldid
                JOIN {context} ctx ON ctx.id = d.contextid
                WHERE ctx.instanceid = ? AND ctx.contextlevel = ?";

        $fields = $DB->get_records_sql($sql, [$courseid, CONTEXT_COURSE]);
        $result = [];

        foreach ($fields as $field) {
            // Determine value based on field type.
            if ($field->type === 'checkbox') {
                $value = (bool) $field->intvalue;
            } elseif ($field->type === 'date') {
                $value = $field->intvalue ? $this->format_date($field->intvalue) : null;
            } elseif (!empty($field->charvalue)) {
                $value = $field->charvalue;
            } elseif (!empty($field->value)) {
                $value = $field->value;
            } else {
                $value = $field->intvalue;
            }

            if ($value !== '' && $value !== null) {
                $result[$field->shortname] = [
                    'name' => $field->name,
                    'type' => $field->type,
                    'value' => $value,
                ];
            }
        }

        return $result;
    }

    /**
     * Get all category paths.
     *
     * @return array Category ID => full path.
     */
    protected function get_all_category_paths(): array {
        global $DB;

        $categories = $DB->get_records('course_categories', null, 'sortorder');
        $paths = [];
        $names = [];

        foreach ($categories as $cat) {
            $names[$cat->id] = $cat->name;
        }

        foreach ($categories as $cat) {
            $pathIds = explode('/', trim($cat->path, '/'));
            $pathNames = [];
            foreach ($pathIds as $id) {
                if (isset($names[$id])) {
                    $pathNames[] = $names[$id];
                }
            }
            $paths[$cat->id] = '/' . implode('/', $pathNames);
        }

        return $paths;
    }

    /**
     * Create .mbz backup for a course.
     *
     * @param object $course Course record.
     * @param string $backupsDir Backups directory path.
     * @return array|null Backup result or null on failure.
     * @throws \moodle_exception On backup failure.
     */
    protected function create_course_backup(object $course, string $backupsDir): ?array {
        global $USER, $CFG;

        // Generate backup filename.
        $shortname = clean_filename($course->shortname);
        $filename = sprintf('course_%d_%s_%s.mbz', $course->id, $shortname, date('Ymd'));
        $destPath = $backupsDir . '/' . $filename;

        $this->log('debug', "Creating backup for course {$course->shortname}...");

        try {
            // Create backup controller.
            $bc = new \backup_controller(
                \backup::TYPE_1COURSE,
                $course->id,
                \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO,
                \backup::MODE_GENERAL,
                $USER->id
            );

            // Configure backup settings.
            $this->configure_backup_settings($bc);

            // Execute backup.
            $bc->execute_plan();

            // Get the backup file.
            $results = $bc->get_results();
            $file = $results['backup_destination'] ?? null;

            if (!$file) {
                $bc->destroy();
                throw new \moodle_exception('error_backup_no_file', 'local_edulution');
            }

            // Copy backup file to export directory.
            $file->copy_content_to($destPath);
            $size = filesize($destPath);

            // Calculate checksum.
            $checksum = $this->calculate_checksum($destPath);

            // Cleanup.
            $file->delete();
            $bc->destroy();

            $this->log('debug', "Backup created: {$filename} ({$this->format_size($size)})");

            return [
                'file' => 'courses/backups/' . $filename,
                'full_path' => $destPath,
                'size' => $size,
                'checksum' => $checksum,
            ];

        } catch (\Exception $e) {
            if (isset($bc)) {
                $bc->destroy();
            }
            throw $e;
        }
    }

    /**
     * Configure backup settings based on export options.
     *
     * @param \backup_controller $bc Backup controller.
     */
    protected function configure_backup_settings(\backup_controller $bc): void {
        $plan = $bc->get_plan();
        $settings = $plan->get_settings();

        $configMap = [
            // User data settings.
            'users' => $this->options->include_user_data,
            'anonymize' => $this->options->anonymize_users,
            'role_assignments' => true,

            // Content settings.
            'activities' => true,
            'blocks' => true,
            'files' => $this->options->include_course_files,
            'filters' => true,
            'comments' => false,
            'badges' => true,
            'calendarevents' => true,
            'userscompletion' => $this->options->include_user_data,
            'logs' => false,
            'grade_histories' => false,
            'questionbank' => true,
            'groups' => true,
            'competencies' => true,
            'contentbankcontent' => true,
            'legacyfiles' => true,
        ];

        foreach ($settings as $setting) {
            $name = $setting->get_name();

            if (isset($configMap[$name])) {
                try {
                    $value = $configMap[$name] ? 1 : 0;
                    if ($setting->get_status() === \backup_setting::NOT_LOCKED) {
                        $setting->set_value($value);
                    }
                } catch (\Exception $e) {
                    // Setting might be locked or not available.
                    $this->log('debug', "Could not set backup setting {$name}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Get list of courses that would be exported.
     *
     * @return array Course list.
     */
    public function get_course_list(): array {
        global $DB;

        $where = 'id != 1';
        $params = [];

        if (!empty($this->options->course_ids)) {
            list($insql, $params) = $DB->get_in_or_equal($this->options->course_ids);
            $where .= " AND id {$insql}";
        }

        if (!empty($this->options->category_ids)) {
            list($catinsql, $catparams) = $DB->get_in_or_equal($this->options->category_ids);
            $where .= " AND category {$catinsql}";
            $params = array_merge($params, $catparams);
        }

        return $DB->get_records_select('course', $where, $params, 'fullname ASC', 'id, shortname, fullname, category');
    }
}
