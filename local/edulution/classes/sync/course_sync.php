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
 * Course synchronization from Keycloak groups to Moodle.
 *
 * Creates and manages courses based on Keycloak groups:
 * - Class courses from student groups
 * - Project courses from project groups
 * - Automatic category assignment based on group hierarchy
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/course/lib.php');

/**
 * Course synchronization class.
 */
class course_sync
{

    /** Sync prefix for class courses */
    public const PREFIX_CLASS = 'sync_class_';

    /** Sync prefix for project courses */
    public const PREFIX_PROJECT = 'sync_project_';

    /** Custom field name for Keycloak Group ID */
    public const CUSTOM_FIELD_GROUP_ID = 'keycloak_group_id';

    /** Custom field name for category Keycloak source */
    public const CUSTOM_FIELD_CATEGORY_SOURCE = 'keycloak_source';

    /** Project sub-types */
    public const SUBTYPE_FACHSCHAFT = 'fachschaft';
    public const SUBTYPE_TEACHER_COURSE = 'teacher_course';
    public const SUBTYPE_CLASS_COURSE = 'class_course';
    public const SUBTYPE_AG = 'ag';
    public const SUBTYPE_GENERAL = 'general';

    /** Subject abbreviation map (lowercase key => display name) */
    public const SUBJECT_MAP = [
        'bio' => 'Biologie',
        'biologie' => 'Biologie',
        'm' => 'Mathematik',
        'mathe' => 'Mathematik',
        'math' => 'Mathematik',
        'mathematik' => 'Mathematik',
        'd' => 'Deutsch',
        'deutsch' => 'Deutsch',
        'deu' => 'Deutsch',
        'e' => 'Englisch',
        'eng' => 'Englisch',
        'englisch' => 'Englisch',
        'f' => 'Franzoesisch',
        'franz' => 'Franzoesisch',
        'fra' => 'Franzoesisch',
        'l' => 'Latein',
        'lat' => 'Latein',
        'latein' => 'Latein',
        'g' => 'Geschichte',
        'gesch' => 'Geschichte',
        'geschichte' => 'Geschichte',
        'geo' => 'Geografie',
        'geografie' => 'Geografie',
        'ek' => 'Erdkunde',
        'erdkunde' => 'Erdkunde',
        'ph' => 'Physik',
        'physik' => 'Physik',
        'ch' => 'Chemie',
        'chemie' => 'Chemie',
        'mus' => 'Musik',
        'musik' => 'Musik',
        'bk' => 'Bildende Kunst',
        'kunst' => 'Bildende Kunst',
        'spo' => 'Sport',
        'sport' => 'Sport',
        'eth' => 'Ethik',
        'ethik' => 'Ethik',
        'evrel' => 'Ev. Religion',
        'krel' => 'Kath. Religion',
        'rel' => 'Religion',
        'spa' => 'Spanisch',
        'spanisch' => 'Spanisch',
        'rus' => 'Russisch',
        'nwt' => 'NwT',
        'bnt' => 'BNT',
        'gk' => 'Gemeinschaftskunde',
        'gemk' => 'Gemeinschaftskunde',
        'wbs' => 'WBS',
        'inf' => 'Informatik',
        'informatik' => 'Informatik',
        'it' => 'IT',
        'lehrer' => 'Alle Lehrer',
    ];

    /** @var keycloak_client Keycloak API client */
    protected keycloak_client $client;

    /** @var group_classifier Group classifier */
    protected group_classifier $classifier;

    /** @var bool Dry run mode */
    protected bool $dry_run = false;

    /** @var array Import settings */
    protected array $import_settings = [];

    /** @var array Category ID cache */
    protected array $categories = [];

    /** @var array Sync statistics */
    protected array $stats = [
        'total_groups' => 0,
        'class_groups' => 0,
        'project_groups' => 0,
        'courses_created' => 0,
        'courses_updated' => 0,
        'courses_skipped' => 0,
        'categories_created' => 0,
        'errors' => 0,
    ];

    /** @var array Log messages */
    protected array $log = [];

    /** @var array Created/updated courses [group_name => course_info] */
    protected array $synced_courses = [];

    /** @var int|null Custom field ID for Keycloak Group ID */
    protected ?int $group_field_id = null;

    /**
     * Constructor.
     *
     * @param keycloak_client $client Keycloak API client.
     * @param group_classifier $classifier Group classifier.
     */
    public function __construct(keycloak_client $client, group_classifier $classifier)
    {
        $this->client = $client;
        $this->classifier = $classifier;
        $this->load_import_settings();
    }

    /**
     * Load import settings from configuration.
     */
    protected function load_import_settings(): void
    {
        $this->import_settings = [
            'course_name_template' => get_config('local_edulution', 'course_name_template') ?: '{group_name}',
            'course_shortname_template' => get_config('local_edulution', 'course_shortname_template') ?: '{group_name}',
            'default_format' => get_config('local_edulution', 'default_course_format') ?: 'topics',
            'auto_enroll_teachers' => (bool) get_config('local_edulution', 'auto_enroll_teachers'),
            'auto_enroll_students' => (bool) get_config('local_edulution', 'auto_enroll_students'),
            'strip_suffixes' => $this->parse_list_config('strip_suffixes', ['-students', '-teachers', '-parents']),
            'strip_prefixes' => $this->parse_list_config('strip_prefixes', ['p_', 'project_']),
            'uppercase_shortnames' => (bool) get_config('local_edulution', 'uppercase_shortnames'),
            'delete_removed_courses' => (bool) get_config('local_edulution', 'delete_removed_courses'),
            'unenroll_removed_users' => (bool) get_config('local_edulution', 'unenroll_removed_users'),
        ];

        // Also try to load from JSON file.
        $json_path = get_config('local_edulution', 'import_settings_path') ?: '/sync-data/config/import-settings.json';
        if (file_exists($json_path)) {
            $content = @file_get_contents($json_path);
            if ($content !== false) {
                $json_settings = json_decode($content, true);
                if (is_array($json_settings)) {
                    $this->import_settings = array_merge($this->import_settings, $json_settings);
                }
            }
        }
    }

    /**
     * Parse a comma-separated list config into array.
     *
     * @param string $name Config name.
     * @param array $default Default value.
     * @return array Parsed array.
     */
    protected function parse_list_config(string $name, array $default): array
    {
        $value = get_config('local_edulution', $name);
        if (empty($value)) {
            return $default;
        }
        return array_map('trim', explode(',', $value));
    }

    /**
     * Enable or disable dry run mode.
     *
     * @param bool $dry_run Whether to enable dry run.
     * @return self
     */
    public function set_dry_run(bool $dry_run): self
    {
        $this->dry_run = $dry_run;
        return $this;
    }

    /**
     * Set import settings.
     *
     * @param array $settings Settings to merge.
     * @return self
     */
    public function set_import_settings(array $settings): self
    {
        $this->import_settings = array_merge($this->import_settings, $settings);
        return $this;
    }

    /**
     * Synchronize courses from Keycloak groups.
     *
     * @param array|null $groups Optional array of groups (or null to fetch from Keycloak).
     * @return array Sync results.
     */
    public function sync(?array $groups = null): array
    {
        $this->log('info', 'Starting course synchronization...');

        // Setup categories and custom fields.
        $this->setup_categories();
        $this->ensure_custom_fields();

        // Fetch groups if not provided.
        if ($groups === null) {
            try {
                $groups = $this->client->get_all_groups_flat();
                $this->log('info', "Found " . count($groups) . " groups in Keycloak");
            } catch (\Exception $e) {
                $this->log('error', "Failed to fetch groups from Keycloak: " . $e->getMessage());
                $this->stats['errors']++;
                return $this->get_results();
            }
        }

        $this->stats['total_groups'] = count($groups);

        // Classify groups.
        $classified = $this->classifier->classify_groups($groups);

        $this->stats['class_groups'] = count($classified[group_classifier::TYPE_CLASS]);
        $this->stats['project_groups'] = count($classified[group_classifier::TYPE_PROJECT]);

        // Process class groups.
        $this->log('info', "Processing {$this->stats['class_groups']} class groups...");
        foreach ($classified[group_classifier::TYPE_CLASS] as $group) {
            $this->sync_class_course($group, $classified[group_classifier::TYPE_TEACHER]);
        }

        // Process project groups.
        $this->log('info', "Processing {$this->stats['project_groups']} project groups...");
        foreach ($classified[group_classifier::TYPE_PROJECT] as $group) {
            $this->sync_project_course($group);
        }

        $this->log('info', 'Course synchronization complete');
        $this->log('info', "Created: {$this->stats['courses_created']}, Updated: {$this->stats['courses_updated']}, " .
            "Skipped: {$this->stats['courses_skipped']}");

        return $this->get_results();
    }

    /**
     * Setup the category structure for synced courses.
     */
    protected function setup_categories(): void
    {
        $this->log('debug', 'Setting up category structure...');

        // Main categories.
        $this->categories['klassen'] = $this->get_or_create_category(
            'Klassen',
            0,
            'Automatisch synchronisierte Klassenkurse'
        );
        $this->categories['projekte'] = $this->get_or_create_category(
            'Projekte',
            0,
            'Automatisch synchronisierte Projektkurse'
        );

        // Grade level sub-categories under Klassen.
        for ($i = 5; $i <= 10; $i++) {
            $this->categories["stufe_{$i}"] = $this->get_or_create_category(
                "Klassenstufe {$i}",
                $this->categories['klassen'],
                "Kurse für Klassenstufe {$i}"
            );
        }

        // Kursstufe (11, 12).
        $this->categories['kursstufe'] = $this->get_or_create_category(
            'Kursstufe',
            $this->categories['klassen'],
            'Kurse für die Kursstufe (K1/K2)'
        );

        // Sonstige.
        $this->categories['sonstige'] = $this->get_or_create_category(
            'Sonstige Klassen',
            $this->categories['klassen'],
            'Andere Klassenkurse'
        );

        // Project sub-categories.
        $this->categories['fachgruppen'] = $this->get_or_create_category(
            'Fachgruppen',
            $this->categories['projekte'],
            'Fachschaftskurse (alle Lehrer eines Fachs)'
        );
        $this->categories['lehrerkurse'] = $this->get_or_create_category(
            'Lehrerkurse',
            $this->categories['projekte'],
            'Fachkurse einzelner Lehrkraefte (z.B. Mueller Mathe 10A)'
        );
        $this->categories['klassenkurse'] = $this->get_or_create_category(
            'Klassenkurse',
            $this->categories['projekte'],
            'Fachkurse fuer Klassen (z.B. Deutsch 10A)'
        );
        $this->categories['ags'] = $this->get_or_create_category(
            'AGs',
            $this->categories['projekte'],
            'Arbeitsgemeinschaften'
        );
        $this->categories['projektkurse'] = $this->get_or_create_category(
            'Projektkurse',
            $this->categories['projekte'],
            'Allgemeine Projektkurse'
        );
    }

    /**
     * Get or create a category.
     *
     * @param string $name Category name.
     * @param int $parent_id Parent category ID.
     * @param string $description Category description.
     * @return int Category ID.
     */
    protected function get_or_create_category(string $name, int $parent_id, string $description = ''): int
    {
        global $DB;

        $category = $DB->get_record('course_categories', [
            'name' => $name,
            'parent' => $parent_id,
        ]);

        if ($category) {
            return $category->id;
        }

        if ($this->dry_run) {
            $this->log('dry_run', "Would create category: {$name}");
            return -1;
        }

        $data = new \stdClass();
        $data->name = $name;
        $data->parent = $parent_id;
        $data->description = $description;
        $data->descriptionformat = FORMAT_HTML;
        $data->visible = 1;

        $newcat = \core_course_category::create($data);
        $this->stats['categories_created']++;
        $this->log('info', "Created category: {$name}");

        return $newcat->id;
    }

    /**
     * Get the appropriate category ID for a class course.
     *
     * @param string $class_name Cleaned class name.
     * @return int Category ID.
     */
    protected function get_class_category(string $class_name): int
    {
        $name_lower = strtolower($class_name);

        // Kursstufe patterns.
        if (preg_match('/^(k1|k2|ks1|ks2|11|12|j1|j2)/', $name_lower)) {
            return $this->categories['kursstufe'];
        }

        // Grade level 5-10.
        if (preg_match('/^(\d+)/', $name_lower, $matches)) {
            $grade = (int) $matches[1];
            if ($grade >= 5 && $grade <= 10) {
                return $this->categories["stufe_{$grade}"];
            }
        }

        return $this->categories['sonstige'];
    }

    /**
     * Get the appropriate category ID for a project course.
     *
     * @param string $group_name Group name.
     * @return int Category ID.
     */
    protected function get_project_category(string $group_name): int
    {
        $subtype = $this->detect_project_subtype($group_name);

        switch ($subtype) {
            case self::SUBTYPE_FACHSCHAFT:
                return $this->categories['fachgruppen'];
            case self::SUBTYPE_TEACHER_COURSE:
                return $this->categories['lehrerkurse'];
            case self::SUBTYPE_CLASS_COURSE:
                return $this->categories['klassenkurse'];
            case self::SUBTYPE_AG:
                return $this->categories['ags'];
            default:
                return $this->categories['projektkurse'];
        }
    }

    /**
     * Detect the sub-type of a project group by analyzing its name parts.
     *
     * Patterns:
     * - p_alle_bio         → fachschaft (starts with 'alle')
     * - p_robotik_ag       → ag (ends with 'ag')
     * - p_mueller_mathe_10a → teacher_course (teacher + subject + class)
     * - p_10a_deutsch      → class_course (class + subject, no teacher)
     * - p_theater           → general (catch-all)
     *
     * @param string $group_name Group name (with p_ prefix).
     * @return string Sub-type constant.
     */
    protected function detect_project_subtype(string $group_name): string
    {
        $name = preg_replace('/^p_/', '', $group_name);
        $parts = preg_split('/[-_]/', $name);
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));

        if (empty($parts)) {
            return self::SUBTYPE_GENERAL;
        }

        // Fachschaft: p_alle_bio, p_alle-mathe.
        if (strtolower($parts[0]) === 'alle') {
            return self::SUBTYPE_FACHSCHAFT;
        }

        // AG: p_robotik_ag, p_theater_ag.
        if (strtolower(end($parts)) === 'ag') {
            return self::SUBTYPE_AG;
        }

        // Parse parts into teacher/subject/class.
        $parsed = $this->parse_project_parts($parts);

        // Teacher course: teacher + subject + class (e.g., p_mueller_mathe_10a).
        if ($parsed['teacher'] !== null && $parsed['subject'] !== null && $parsed['class'] !== null) {
            return self::SUBTYPE_TEACHER_COURSE;
        }

        // Class course: class + subject, no teacher (e.g., p_10a_deutsch).
        if ($parsed['class'] !== null && $parsed['subject'] !== null && $parsed['teacher'] === null) {
            return self::SUBTYPE_CLASS_COURSE;
        }

        return self::SUBTYPE_GENERAL;
    }

    /**
     * Parse project group name parts into teacher, subject, and class components.
     *
     * @param array $parts Name parts (already split by - or _).
     * @return array ['teacher' => string|null, 'subject' => string|null, 'class' => string|null]
     */
    protected function parse_project_parts(array $parts): array
    {
        $class_pattern = '/^(\d+[a-z]?|k[s]?[12]|j[12])$/i';
        $result = ['teacher' => null, 'subject' => null, 'class' => null];

        foreach ($parts as $part) {
            $lower = strtolower($part);
            if (preg_match($class_pattern, $part)) {
                $result['class'] = $part;
            } elseif (isset(self::SUBJECT_MAP[$lower])) {
                $result['subject'] = $part;
            } else {
                // First unrecognized part is the teacher name.
                if ($result['teacher'] === null) {
                    $result['teacher'] = $part;
                }
            }
        }

        return $result;
    }

    /**
     * Synchronize a class course from a Keycloak group.
     *
     * @param array $group Keycloak group data.
     * @param array $teacher_groups All teacher groups (for finding matching teachers).
     */
    protected function sync_class_course(array $group, array $teacher_groups): void
    {
        $group_name = $group['name'];
        $group_id = $group['id'];

        // Extract class name and generate course names.
        $class_name = $this->classifier->extract_base_name($group_name);
        $fullname = $this->generate_fullname($group_name, 'class', $class_name);
        $shortname = $this->generate_shortname($group_name, 'class', $class_name);
        $idnumber = self::PREFIX_CLASS . strtolower($class_name);
        $category_id = $this->get_class_category($class_name);

        $this->log('debug', "Class: {$group_name} -> {$shortname}");

        // Find or create the course.
        $course = $this->find_or_create_course($idnumber, $shortname, $fullname, $category_id, $group_id);

        if ($course) {
            $this->synced_courses[$group_name] = [
                'course_id' => $course->id,
                'shortname' => $course->shortname,
                'group_id' => $group_id,
                'type' => 'class',
                'class_name' => $class_name,
            ];
        }
    }

    /**
     * Synchronize a project course from a Keycloak group.
     *
     * @param array $group Keycloak group data.
     */
    protected function sync_project_course(array $group): void
    {
        $group_name = $group['name'];
        $group_id = $group['id'];

        // Detect sub-type and generate course names.
        $subtype = $this->detect_project_subtype($group_name);
        $project_name = $this->extract_project_name($group_name);
        $fullname = $this->generate_fullname($group_name, 'project', $project_name);
        $shortname = $this->generate_shortname($group_name, 'project', $project_name);
        $idnumber = self::PREFIX_PROJECT . strtolower(preg_replace('/[^a-z0-9_]/', '', $group_name));
        $category_id = $this->get_project_category($group_name);

        $this->log('debug', "Project ({$subtype}): {$group_name} -> {$shortname} [{$fullname}]");

        // Find or create the course.
        $course = $this->find_or_create_course($idnumber, $shortname, $fullname, $category_id, $group_id);

        if ($course) {
            $this->synced_courses[$group_name] = [
                'course_id' => $course->id,
                'shortname' => $course->shortname,
                'group_id' => $group_id,
                'type' => 'project',
                'subtype' => $subtype,
            ];
        }
    }

    /**
     * Generate course full name.
     *
     * @param string $group_name Original group name.
     * @param string $type Course type ('class' or 'project').
     * @param string $extracted_name Extracted name.
     * @return string Generated full name.
     */
    protected function generate_fullname(string $group_name, string $type, string $extracted_name): string
    {
        $template = $this->import_settings['course_name_template'];

        // Use default behavior if template is default.
        if ($template === '{group_name}') {
            if ($type === 'class') {
                return 'Klasse ' . strtoupper($extracted_name);
            } else {
                return $this->create_project_fullname($group_name);
            }
        }

        $clean_name = $this->apply_name_stripping($group_name);

        return str_replace(
            ['{group_name}', '{clean_name}', '{class_name}', '{project_name}'],
            [$group_name, $clean_name, $extracted_name, $extracted_name],
            $template
        );
    }

    /**
     * Generate course short name.
     *
     * @param string $group_name Original group name.
     * @param string $type Course type.
     * @param string $extracted_name Extracted name.
     * @return string Generated short name.
     */
    protected function generate_shortname(string $group_name, string $type, string $extracted_name): string
    {
        $template = $this->import_settings['course_shortname_template'];

        if ($template !== '{group_name}') {
            $clean_name = $this->apply_name_stripping($group_name);
            $shortname = str_replace(
                ['{group_name}', '{clean_name}', '{class_name}', '{project_name}'],
                [$group_name, $clean_name, $extracted_name, $extracted_name],
                $template
            );
        } else {
            $shortname = $extracted_name;
        }

        if (!empty($this->import_settings['uppercase_shortnames'])) {
            $shortname = strtoupper($shortname);
        }

        return $shortname;
    }

    /**
     * Apply strip_suffixes and strip_prefixes to a group name.
     *
     * @param string $group_name Original group name.
     * @return string Cleaned name.
     */
    protected function apply_name_stripping(string $group_name): string
    {
        $name = $group_name;

        // Strip suffixes.
        foreach ($this->import_settings['strip_suffixes'] as $suffix) {
            if (substr($name, -strlen($suffix)) === $suffix) {
                $name = substr($name, 0, -strlen($suffix));
                break;
            }
        }

        // Strip prefixes.
        foreach ($this->import_settings['strip_prefixes'] as $prefix) {
            if (strpos($name, $prefix) === 0) {
                $name = substr($name, strlen($prefix));
                break;
            }
        }

        return $name;
    }

    /**
     * Extract project short name from group name.
     *
     * @param string $group_name Group name.
     * @return string Project short name.
     */
    protected function extract_project_name(string $group_name): string
    {
        $subtype = $this->detect_project_subtype($group_name);
        $name = preg_replace('/^p_/', '', $group_name);
        $parts = preg_split('/[-_]/', $name);
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));

        switch ($subtype) {
            case self::SUBTYPE_FACHSCHAFT:
                // p_alle_bio → FS_BIO.
                $subject = strtoupper($parts[1] ?? 'X');
                return "FS_{$subject}";

            case self::SUBTYPE_TEACHER_COURSE:
                // p_mueller_mathe_10a → MUELLER_MATHE_10A.
                $parsed = $this->parse_project_parts($parts);
                return strtoupper(implode('_', array_filter([
                    $parsed['teacher'],
                    $parsed['subject'],
                    $parsed['class'],
                ])));

            case self::SUBTYPE_CLASS_COURSE:
                // p_10a_deutsch → 10A_DEUTSCH.
                $parsed = $this->parse_project_parts($parts);
                return strtoupper(implode('_', array_filter([
                    $parsed['class'],
                    $parsed['subject'],
                ])));

            case self::SUBTYPE_AG:
                // p_robotik_ag → AG_ROBOTIK.
                array_pop($parts); // Remove 'ag'.
                return 'AG_' . strtoupper(implode('_', $parts));

            default:
                // p_theater → THEATER.
                return strtoupper(preg_replace('/[^a-zA-Z0-9_]/', '', implode('_', $parts)));
        }
    }

    /**
     * Create a readable full name for a project course.
     *
     * Recognizes sub-types:
     * - Fachschaften:  p_alle_bio          → "Fachschaft Biologie"
     * - Lehrer-Kurse:  p_mueller_mathe_10a → "Mathematik Klasse 10A (MUELLER)"
     * - Klassen-Kurse: p_10a_deutsch       → "Deutsch 10A"
     * - AGs:           p_robotik_ag        → "AG: Robotik"
     * - Sonstige:      p_theater           → "Projekt: Theater"
     *
     * @param string $group_name Group name.
     * @return string Full name.
     */
    protected function create_project_fullname(string $group_name): string
    {
        $subtype = $this->detect_project_subtype($group_name);
        $name = preg_replace('/^p_/', '', $group_name);
        $parts = preg_split('/[-_]/', $name);
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));

        switch ($subtype) {
            case self::SUBTYPE_FACHSCHAFT:
                // p_alle_bio → "Fachschaft Biologie".
                $subject_key = strtolower($parts[1] ?? '');
                $subject = self::SUBJECT_MAP[$subject_key] ?? ucfirst($parts[1] ?? 'Unbekannt');
                return "Fachschaft {$subject}";

            case self::SUBTYPE_TEACHER_COURSE:
                // p_mueller_mathe_10a → "Mathematik Klasse 10A (MUELLER)".
                $parsed = $this->parse_project_parts($parts);
                $subject = self::SUBJECT_MAP[strtolower($parsed['subject'] ?? '')] ?? ucfirst($parsed['subject'] ?? '');
                $class = strtoupper($parsed['class'] ?? '');
                $teacher = strtoupper($parsed['teacher'] ?? '');
                return "{$subject} Klasse {$class} ({$teacher})";

            case self::SUBTYPE_CLASS_COURSE:
                // p_10a_deutsch → "Deutsch 10A".
                $parsed = $this->parse_project_parts($parts);
                $subject = self::SUBJECT_MAP[strtolower($parsed['subject'] ?? '')] ?? ucfirst($parsed['subject'] ?? '');
                $class = strtoupper($parsed['class'] ?? '');
                return "{$subject} {$class}";

            case self::SUBTYPE_AG:
                // p_robotik_ag → "AG: Robotik".
                array_pop($parts); // Remove 'ag'.
                $ag_name = ucwords(implode(' ', $parts));
                return "AG: {$ag_name}";

            default:
                // p_theater → "Projekt: Theater".
                $name = str_replace(['-', '_'], ' ', $name);
                return 'Projekt: ' . ucwords($name);
        }
    }

    /**
     * Find or create a sync-managed course.
     *
     * @param string $idnumber Course idnumber.
     * @param string $shortname Course shortname.
     * @param string $fullname Course fullname.
     * @param int $category_id Category ID.
     * @param string|null $keycloak_group_id Keycloak group ID.
     * @return \stdClass|null Course object or null.
     */
    protected function find_or_create_course(
        string $idnumber,
        string $shortname,
        string $fullname,
        int $category_id,
        ?string $keycloak_group_id = null
    ): ?\stdClass {
        global $DB;

        // First try to find by Keycloak Group ID (most reliable).
        $course = null;
        if ($keycloak_group_id) {
            $course = $this->get_course_by_keycloak_group_id($keycloak_group_id);
            if ($course) {
                // Update course if needed.
                $updated = $this->update_course_if_needed($course, $shortname, $fullname, $idnumber, $category_id);
                if ($updated) {
                    $this->stats['courses_updated']++;
                }
                return $course;
            }
        }

        // Try by idnumber.
        if (!$course) {
            $course = $DB->get_record('course', ['idnumber' => $idnumber]);
        }

        if ($course) {
            // Update category if changed.
            if ($course->category != $category_id) {
                if (!$this->dry_run) {
                    $course->category = $category_id;
                    $DB->update_record('course', $course);
                }
                $this->stats['courses_updated']++;
            }

            // Link Keycloak Group ID if not yet linked.
            if ($keycloak_group_id) {
                $this->set_course_keycloak_group_id($course->id, $keycloak_group_id);
            }

            return $course;
        }

        // Check if a course with this shortname exists but no idnumber.
        $existing = $DB->get_record('course', ['shortname' => $shortname]);
        if ($existing && empty($existing->idnumber)) {
            if ($this->dry_run) {
                $this->log('dry_run', "Would claim existing course {$shortname}");
                return $existing;
            }

            // Claim this course for sync.
            $existing->idnumber = $idnumber;
            $existing->category = $category_id;
            $DB->update_record('course', $existing);

            if ($keycloak_group_id) {
                $this->set_course_keycloak_group_id($existing->id, $keycloak_group_id);
            }

            $this->stats['courses_updated']++;
            return $existing;
        } elseif ($existing) {
            // Course exists with different idnumber - append _SYNC.
            $shortname = $shortname . '_SYNC';
        }

        // Create new course.
        if ($this->dry_run) {
            $this->log('dry_run', "Would create course: {$shortname} - {$fullname}");
            $course = new \stdClass();
            $course->id = -1;
            $course->shortname = $shortname;
            return $course;
        }

        $coursedata = new \stdClass();
        $coursedata->shortname = $shortname;
        $coursedata->fullname = $fullname;
        $coursedata->idnumber = $idnumber;
        $coursedata->category = $category_id;
        $coursedata->format = $this->import_settings['default_format'];
        $coursedata->numsections = 10;
        $coursedata->visible = 1;
        $coursedata->startdate = time();

        try {
            $course = create_course($coursedata);
            $this->stats['courses_created']++;
            $this->log('info', "Created course: {$shortname} - {$fullname}");

            // Link Keycloak Group ID.
            if ($keycloak_group_id) {
                $this->set_course_keycloak_group_id($course->id, $keycloak_group_id);
            }

            return $course;
        } catch (\Exception $e) {
            $this->log('error', "Failed to create course {$shortname}: " . $e->getMessage());
            $this->stats['errors']++;
            return null;
        }
    }

    /**
     * Update a course if needed.
     *
     * @param \stdClass $course Course object.
     * @param string $shortname New shortname.
     * @param string $fullname New fullname.
     * @param string $idnumber New idnumber.
     * @param int $category_id New category ID.
     * @return bool True if updated.
     */
    protected function update_course_if_needed(
        \stdClass $course,
        string $shortname,
        string $fullname,
        string $idnumber,
        int $category_id
    ): bool {
        global $DB;

        $updates = [];

        if ($course->shortname !== $shortname) {
            // Check if new shortname is available.
            $existing = $DB->get_record('course', ['shortname' => $shortname]);
            if (!$existing) {
                $updates['shortname'] = $shortname;
                $this->log('info', "Renaming course {$course->id} -> {$shortname}");
            }
        }

        if ($course->fullname !== $fullname) {
            $updates['fullname'] = $fullname;
        }

        if ($course->idnumber !== $idnumber) {
            $updates['idnumber'] = $idnumber;
        }

        if ($course->category != $category_id) {
            $updates['category'] = $category_id;
        }

        if (empty($updates)) {
            return false;
        }

        if ($this->dry_run) {
            $this->log('dry_run', "Would update course {$course->shortname}: " . json_encode($updates));
            return true;
        }

        foreach ($updates as $field => $value) {
            $course->$field = $value;
        }
        $course->timemodified = time();
        $DB->update_record('course', $course);

        return true;
    }

    /**
     * Ensure custom fields exist for courses.
     */
    protected function ensure_custom_fields(): void
    {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/customfield/fieldcontroller.php');

        // Setup Course Custom Field for Keycloak Group ID.
        $this->group_field_id = $this->setup_customfield(
            'core_course',
            'course',
            self::CUSTOM_FIELD_GROUP_ID,
            'Keycloak Group ID',
            'Die Keycloak Group UUID (automatisch synchronisiert)',
            36
        );
    }

    /**
     * Create or get a custom field.
     *
     * @param string $component Component name.
     * @param string $area Area name.
     * @param string $shortname Field shortname.
     * @param string $name Field name.
     * @param string $description Field description.
     * @param int $maxlength Maximum length.
     * @return int|null Field ID or null.
     */
    protected function setup_customfield(
        string $component,
        string $area,
        string $shortname,
        string $name,
        string $description,
        int $maxlength = 255
    ): ?int {
        global $DB;

        $category_id = $this->get_or_create_customfield_category($component, $area, 'Sync Metadata');

        $field = $DB->get_record('customfield_field', [
            'shortname' => $shortname,
            'categoryid' => $category_id,
        ]);

        if ($field) {
            return $field->id;
        }

        if ($this->dry_run) {
            $this->log('dry_run', "Would create custom field: {$shortname}");
            return null;
        }

        $field = new \stdClass();
        $field->shortname = $shortname;
        $field->name = $name;
        $field->type = 'text';
        $field->description = $description;
        $field->descriptionformat = FORMAT_HTML;
        $field->categoryid = $category_id;
        $field->sortorder = $DB->count_records('customfield_field', ['categoryid' => $category_id]) + 1;
        $field->configdata = json_encode([
            'required' => 0,
            'uniquevalues' => 1,
            'locked' => 1,
            'visibility' => 0,
            'defaultvalue' => '',
            'displaysize' => $maxlength,
            'maxlength' => $maxlength,
            'ispassword' => 0,
            'link' => '',
        ]);
        $field->timecreated = time();
        $field->timemodified = time();

        $field->id = $DB->insert_record('customfield_field', $field);
        $this->log('info', "Created custom field: {$shortname}");

        return $field->id;
    }

    /**
     * Get or create a custom field category.
     *
     * @param string $component Component name.
     * @param string $area Area name.
     * @param string $name Category name.
     * @return int Category ID.
     */
    protected function get_or_create_customfield_category(string $component, string $area, string $name): int
    {
        global $DB;

        $category = $DB->get_record('customfield_category', [
            'component' => $component,
            'area' => $area,
            'name' => $name,
        ]);

        if ($category) {
            return $category->id;
        }

        if ($this->dry_run) {
            return -1;
        }

        $category = new \stdClass();
        $category->name = $name;
        $category->description = 'Automatisch verwaltete Metadaten vom Keycloak-Sync';
        $category->descriptionformat = FORMAT_HTML;
        $category->component = $component;
        $category->area = $area;
        $category->sortorder = 0;
        $category->contextid = \context_system::instance()->id;
        $category->timecreated = time();
        $category->timemodified = time();

        return $DB->insert_record('customfield_category', $category);
    }

    /**
     * Set Keycloak Group ID for a course.
     *
     * @param int $course_id Course ID.
     * @param string $keycloak_group_id Keycloak group ID.
     */
    protected function set_course_keycloak_group_id(int $course_id, string $keycloak_group_id): void
    {
        global $DB;

        if ($this->group_field_id === null || $this->dry_run) {
            return;
        }

        $context = \context_course::instance($course_id);

        $data = $DB->get_record('customfield_data', [
            'fieldid' => $this->group_field_id,
            'instanceid' => $course_id,
        ]);

        $charvalue = substr($keycloak_group_id, 0, 1333);

        if ($data) {
            if ($data->value !== $keycloak_group_id) {
                $data->value = $keycloak_group_id;
                $data->charvalue = $charvalue;
                $data->timemodified = time();
                $DB->update_record('customfield_data', $data);
            }
        } else {
            $data = new \stdClass();
            $data->fieldid = $this->group_field_id;
            $data->instanceid = $course_id;
            $data->contextid = $context->id;
            $data->value = $keycloak_group_id;
            $data->valueformat = 0;
            $data->charvalue = $charvalue;
            $data->intvalue = 0;
            $data->decvalue = null;
            $data->timecreated = time();
            $data->timemodified = time();
            $DB->insert_record('customfield_data', $data);
        }
    }

    /**
     * Get a course by Keycloak Group ID.
     *
     * @param string $keycloak_group_id Keycloak group ID.
     * @return \stdClass|null Course or null.
     */
    public function get_course_by_keycloak_group_id(string $keycloak_group_id): ?\stdClass
    {
        global $DB;

        $course = $DB->get_record_sql(
            "SELECT c.* FROM {course} c
             JOIN {customfield_data} d ON d.instanceid = c.id
             JOIN {customfield_field} f ON f.id = d.fieldid
             JOIN {customfield_category} cat ON cat.id = f.categoryid
             WHERE f.shortname = ?
             AND cat.component = 'core_course'
             AND cat.area = 'course'
             AND d.value = ?",
            [self::CUSTOM_FIELD_GROUP_ID, $keycloak_group_id]
        );

        return $course ?: null;
    }

    /**
     * Log a message.
     *
     * @param string $level Log level.
     * @param string $message Log message.
     */
    protected function log(string $level, string $message): void
    {
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
    public function get_results(): array
    {
        return [
            'success' => $this->stats['errors'] === 0,
            'stats' => $this->stats,
            'log' => $this->log,
            'synced_courses' => $this->synced_courses,
            'categories' => $this->categories,
        ];
    }

    /**
     * Get sync statistics.
     *
     * @return array Statistics.
     */
    public function get_stats(): array
    {
        return $this->stats;
    }

    /**
     * Get synced courses.
     *
     * @return array Synced courses [group_name => info].
     */
    public function get_synced_courses(): array
    {
        return $this->synced_courses;
    }

    /**
     * Get log messages.
     *
     * @return array Log entries.
     */
    public function get_log(): array
    {
        return $this->log;
    }

    /**
     * Get category cache.
     *
     * @return array Categories.
     */
    public function get_categories(): array
    {
        return $this->categories;
    }
}
