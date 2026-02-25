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
 * Group classifier for categorizing Keycloak groups.
 *
 * Classifies Keycloak groups by pattern matching:
 * - Class groups (/-students$/)
 * - Teacher groups (/-teachers$/)
 * - Project groups (/^p_/)
 * - Ignore patterns (/-parents$/)
 *
 * Patterns are configurable via plugin settings or JSON config file.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * Group classifier class.
 *
 * Categorizes Keycloak groups based on regex patterns.
 */
class group_classifier
{

    /** Group type constant: Class student group */
    public const TYPE_CLASS = 'class';

    /** Group type constant: Class teacher group */
    public const TYPE_TEACHER = 'teacher';

    /** Group type constant: Project group */
    public const TYPE_PROJECT = 'project';

    /** Group type constant: Ignored group */
    public const TYPE_IGNORE = 'ignore';

    /** Group type constant: Unknown/unmatched group */
    public const TYPE_UNKNOWN = 'unknown';

    /** @var array Category configurations */
    protected array $categories = [];

    /** @var string Configuration source ('json', 'env', 'config') */
    protected string $config_source = '';

    /** @var string|null Configuration file path if using JSON */
    protected ?string $config_path = null;

    /**
     * Constructor.
     *
     * @param array|null $categories Custom category configurations (or null to load from config).
     */
    public function __construct(?array $categories = null)
    {
        if ($categories !== null) {
            $this->categories = $categories;
            $this->config_source = 'custom';
        } else {
            $this->load_config();
        }
    }

    /**
     * Load configuration from available sources.
     *
     * Priority:
     * 1. JSON config file (if exists)
     * 2. Plugin settings
     * 3. Environment variables
     * 4. Defaults
     */
    protected function load_config(): void
    {
        // Try JSON config file first.
        $json_path = get_config('local_edulution', 'categories_config_path');
        if (empty($json_path)) {
            $json_path = '/sync-data/config/categories.json';
        }

        if (file_exists($json_path)) {
            $loaded = $this->load_from_json($json_path);
            if ($loaded) {
                return;
            }
        }

        // Try plugin settings.
        $loaded = $this->load_from_plugin_settings();
        if ($loaded) {
            return;
        }

        // Try environment variables.
        $loaded = $this->load_from_environment();
        if ($loaded) {
            return;
        }

        // Use defaults.
        $this->load_defaults();
    }

    /**
     * Load configuration from JSON file.
     *
     * @param string $path Path to JSON file.
     * @return bool True if loaded successfully.
     */
    protected function load_from_json(string $path): bool
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return false;
        }

        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        if (!isset($config['categories']) || !is_array($config['categories'])) {
            return false;
        }

        $valid = [];
        foreach ($config['categories'] as $cat) {
            if (!isset($cat['id'], $cat['name'], $cat['regex'])) {
                continue;
            }

            $patterns = $this->normalize_patterns($cat['regex']);
            if (empty($patterns)) {
                continue;
            }

            // Validate patterns.
            $valid_patterns = [];
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, '') !== false) {
                    $valid_patterns[] = $pattern;
                }
            }

            if (empty($valid_patterns)) {
                continue;
            }

            $valid[] = [
                'id' => (int) $cat['id'],
                'name' => (string) $cat['name'],
                'regex' => $valid_patterns,
                'color' => (string) ($cat['color'] ?? 'default'),
                'ignore' => (bool) ($cat['ignore'] ?? false),
                'type' => $cat['type'] ?? null,
            ];
        }

        if (empty($valid)) {
            return false;
        }

        $this->categories = $valid;
        $this->config_source = 'json';
        $this->config_path = $path;

        return true;
    }

    /**
     * Load configuration from plugin settings.
     *
     * @return bool True if loaded successfully.
     */
    protected function load_from_plugin_settings(): bool
    {
        $class_pattern = get_config('local_edulution', 'sync_class_pattern');
        $teacher_pattern = get_config('local_edulution', 'sync_teacher_pattern');
        $project_pattern = get_config('local_edulution', 'sync_project_pattern');
        $ignore_pattern = get_config('local_edulution', 'sync_ignore_pattern');

        // Check if any patterns are configured.
        if (
            empty($class_pattern) && empty($teacher_pattern) &&
            empty($project_pattern) && empty($ignore_pattern)
        ) {
            return false;
        }

        $this->categories = [];

        if (!empty($class_pattern)) {
            $this->categories[] = [
                'id' => 1,
                'name' => 'Klassen',
                'regex' => $this->normalize_patterns($class_pattern),
                'color' => 'blue',
                'ignore' => false,
                'type' => self::TYPE_CLASS,
            ];
        }

        if (!empty($teacher_pattern)) {
            $this->categories[] = [
                'id' => 2,
                'name' => 'Lehrer',
                'regex' => $this->normalize_patterns($teacher_pattern),
                'color' => 'green',
                'ignore' => false,
                'type' => self::TYPE_TEACHER,
            ];
        }

        if (!empty($project_pattern)) {
            $this->categories[] = [
                'id' => 3,
                'name' => 'Projekte',
                'regex' => $this->normalize_patterns($project_pattern),
                'color' => 'purple',
                'ignore' => false,
                'type' => self::TYPE_PROJECT,
            ];
        }

        if (!empty($ignore_pattern)) {
            $this->categories[] = [
                'id' => 4,
                'name' => 'Ignorieren',
                'regex' => $this->normalize_patterns($ignore_pattern),
                'color' => 'gray',
                'ignore' => true,
                'type' => self::TYPE_IGNORE,
            ];
        }

        $this->config_source = 'config';
        return !empty($this->categories);
    }

    /**
     * Load configuration from environment variables.
     *
     * @return bool True if loaded successfully.
     */
    protected function load_from_environment(): bool
    {
        $class_pattern = getenv('SYNC_CLASS_PATTERN');
        $teacher_pattern = getenv('SYNC_TEACHER_PATTERN');
        $project_pattern = getenv('SYNC_PROJECT_PATTERN');
        $ignore_pattern = getenv('SYNC_IGNORE_PATTERN');

        // Check if any patterns are set.
        if (
            empty($class_pattern) && empty($teacher_pattern) &&
            empty($project_pattern) && empty($ignore_pattern)
        ) {
            return false;
        }

        $this->categories = [];

        if (!empty($class_pattern)) {
            $this->categories[] = [
                'id' => 1,
                'name' => 'Klassen',
                'regex' => $this->normalize_patterns($class_pattern),
                'color' => 'blue',
                'ignore' => false,
                'type' => self::TYPE_CLASS,
            ];
        }

        if (!empty($teacher_pattern)) {
            $this->categories[] = [
                'id' => 2,
                'name' => 'Lehrer',
                'regex' => $this->normalize_patterns($teacher_pattern),
                'color' => 'green',
                'ignore' => false,
                'type' => self::TYPE_TEACHER,
            ];
        }

        if (!empty($project_pattern)) {
            $this->categories[] = [
                'id' => 3,
                'name' => 'Projekte',
                'regex' => $this->normalize_patterns($project_pattern),
                'color' => 'purple',
                'ignore' => false,
                'type' => self::TYPE_PROJECT,
            ];
        }

        if (!empty($ignore_pattern)) {
            $this->categories[] = [
                'id' => 4,
                'name' => 'Ignorieren',
                'regex' => $this->normalize_patterns($ignore_pattern),
                'color' => 'gray',
                'ignore' => true,
                'type' => self::TYPE_IGNORE,
            ];
        }

        $this->config_source = 'env';
        return !empty($this->categories);
    }

    /**
     * Load default configuration.
     */
    protected function load_defaults(): void
    {
        $this->categories = [
            [
                'id' => 1,
                'name' => 'Klassen',
                'regex' => ['/-students$/'],
                'color' => 'blue',
                'ignore' => false,
                'type' => self::TYPE_CLASS,
            ],
            [
                'id' => 2,
                'name' => 'Lehrer',
                'regex' => ['/-teachers$/'],
                'color' => 'green',
                'ignore' => false,
                'type' => self::TYPE_TEACHER,
            ],
            [
                'id' => 3,
                'name' => 'Projekte',
                'regex' => ['/^p_/'],
                'color' => 'purple',
                'ignore' => false,
                'type' => self::TYPE_PROJECT,
            ],
            [
                'id' => 4,
                'name' => 'Ignorieren',
                'regex' => ['/-parents$/'],
                'color' => 'gray',
                'ignore' => true,
                'type' => self::TYPE_IGNORE,
            ],
        ];

        $this->config_source = 'defaults';
    }

    /**
     * Normalize regex patterns to array format.
     *
     * Supports:
     * - Single regex string: "/-students$/"
     * - Array of regexes: ["/-students$/", "/-schueler$/"]
     * - Newline-separated string: "/-students$/\n/-schueler$/"
     *
     * @param mixed $regex The regex input.
     * @return array Array of regex patterns.
     */
    protected function normalize_patterns($regex): array
    {
        if (is_array($regex)) {
            return array_values(array_filter(array_map('trim', $regex)));
        }

        if (is_string($regex)) {
            if (strpos($regex, "\n") !== false) {
                $patterns = explode("\n", $regex);
                return array_values(array_filter(array_map('trim', $patterns)));
            }

            $trimmed = trim($regex);
            return $trimmed !== '' ? [$trimmed] : [];
        }

        return [];
    }

    /**
     * Classify a group by name.
     *
     * @param string $group_name The group name to classify.
     * @return array|null Matching category or null if no match.
     */
    public function classify(string $group_name): ?array
    {
        foreach ($this->categories as $category) {
            $patterns = $category['regex'];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $group_name)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Get the type of a group.
     *
     * @param string $group_name The group name.
     * @return string Group type (TYPE_* constant).
     */
    public function get_type(string $group_name): string
    {
        $category = $this->classify($group_name);

        if ($category === null) {
            return self::TYPE_UNKNOWN;
        }

        if ($category['ignore']) {
            return self::TYPE_IGNORE;
        }

        // If type is explicitly set, use it.
        if (!empty($category['type'])) {
            return $category['type'];
        }

        // Infer type from category name.
        return $this->infer_type_from_name($category['name']);
    }

    /**
     * Infer group type from category name.
     *
     * @param string $name Category name.
     * @return string Inferred type.
     */
    protected function infer_type_from_name(string $name): string
    {
        $name_lower = strtolower($name);

        // Class patterns.
        if (
            strpos($name_lower, 'class') !== false ||
            strpos($name_lower, 'klasse') !== false ||
            strpos($name_lower, 'student') !== false ||
            strpos($name_lower, 'schüler') !== false ||
            strpos($name_lower, 'schueler') !== false
        ) {
            return self::TYPE_CLASS;
        }

        // Teacher patterns.
        if (
            strpos($name_lower, 'teach') !== false ||
            strpos($name_lower, 'lehrer') !== false
        ) {
            return self::TYPE_TEACHER;
        }

        // Project patterns.
        if (
            strpos($name_lower, 'project') !== false ||
            strpos($name_lower, 'projekt') !== false
        ) {
            return self::TYPE_PROJECT;
        }

        // Ignore patterns.
        if (
            strpos($name_lower, 'ignor') !== false ||
            strpos($name_lower, 'parent') !== false ||
            strpos($name_lower, 'eltern') !== false
        ) {
            return self::TYPE_IGNORE;
        }

        // Default to project (safest for unknown).
        return self::TYPE_PROJECT;
    }

    /**
     * Check if a group should be ignored.
     *
     * @param string $group_name The group name.
     * @return bool True if should be ignored.
     */
    public function should_ignore(string $group_name): bool
    {
        return $this->get_type($group_name) === self::TYPE_IGNORE;
    }

    /**
     * Check if a group is a class group.
     *
     * @param string $group_name The group name.
     * @return bool True if class group.
     */
    public function is_class_group(string $group_name): bool
    {
        return $this->get_type($group_name) === self::TYPE_CLASS;
    }

    /**
     * Check if a group is a teacher group.
     *
     * @param string $group_name The group name.
     * @return bool True if teacher group.
     */
    public function is_teacher_group(string $group_name): bool
    {
        return $this->get_type($group_name) === self::TYPE_TEACHER;
    }

    /**
     * Check if a group is a project group.
     *
     * @param string $group_name The group name.
     * @return bool True if project group.
     */
    public function is_project_group(string $group_name): bool
    {
        return $this->get_type($group_name) === self::TYPE_PROJECT;
    }

    /**
     * Classify multiple groups.
     *
     * @param array $groups Array of groups (with 'name' key).
     * @return array Classified groups by type.
     */
    public function classify_groups(array $groups): array
    {
        $result = [
            self::TYPE_CLASS => [],
            self::TYPE_TEACHER => [],
            self::TYPE_PROJECT => [],
            self::TYPE_IGNORE => [],
            self::TYPE_UNKNOWN => [],
        ];

        foreach ($groups as $group) {
            $name = $group['name'] ?? '';
            $type = $this->get_type($name);
            $group['_category'] = $this->classify($name);
            $group['_type'] = $type;
            $result[$type][] = $group;
        }

        return $result;
    }

    /**
     * Extract base name from group name (remove suffix).
     *
     * For class groups like "10a-students", returns "10a".
     *
     * @param string $group_name The group name.
     * @return string Base name.
     */
    public function extract_base_name(string $group_name): string
    {
        $category = $this->classify($group_name);

        if ($category === null) {
            return $group_name;
        }

        // Try to remove matching pattern from the name.
        foreach ($category['regex'] as $pattern) {
            $cleaned = preg_replace($pattern, '', $group_name);
            if ($cleaned !== $group_name && $cleaned !== '') {
                return trim($cleaned);
            }
        }

        return $group_name;
    }

    /**
     * Find the corresponding teacher group for a class group.
     *
     * @param string $class_group_name Class group name (e.g., "10a-students").
     * @param array $available_groups Available groups to search.
     * @return array|null Matching teacher group or null.
     */
    public function find_teacher_group(string $class_group_name, array $available_groups): ?array
    {
        $base_name = $this->extract_base_name($class_group_name);

        // Get teacher category patterns.
        $teacher_cat = null;
        foreach ($this->categories as $cat) {
            if (($cat['type'] ?? '') === self::TYPE_TEACHER) {
                $teacher_cat = $cat;
                break;
            }
        }

        if (!$teacher_cat) {
            // Default: try appending -teachers.
            $expected_name = $base_name . '-teachers';
        } else {
            // Build expected name from teacher pattern.
            $pattern = $teacher_cat['regex'][0] ?? '/-teachers$/';
            // Extract suffix from pattern (e.g., "/-teachers$/" -> "-teachers").
            $suffix = preg_replace('/^\/|\/[a-z]*$/i', '', $pattern);
            $suffix = preg_replace('/^\^|\$/', '', $suffix);
            $expected_name = $base_name . $suffix;
        }

        // Search for the teacher group.
        foreach ($available_groups as $group) {
            $name = $group['name'] ?? '';
            if ($name === $expected_name || strcasecmp($name, $expected_name) === 0) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Get all configured categories.
     *
     * @return array Category configurations.
     */
    public function get_categories(): array
    {
        return $this->categories;
    }

    /**
     * Get configuration source.
     *
     * @return string Source identifier.
     */
    public function get_config_source(): string
    {
        return $this->config_source;
    }

    /**
     * Get configuration file path (if using JSON).
     *
     * @return string|null File path or null.
     */
    public function get_config_path(): ?string
    {
        return $this->config_path;
    }

    /**
     * Get configuration summary for display.
     *
     * @return array Configuration summary.
     */
    public function get_config_summary(): array
    {
        $summary = [
            'source' => $this->config_source,
            'path' => $this->config_path,
            'categories' => [],
        ];

        foreach ($this->categories as $cat) {
            $summary['categories'][] = [
                'name' => $cat['name'],
                'type' => $cat['type'] ?? $this->infer_type_from_name($cat['name']),
                'patterns' => $cat['regex'],
                'ignore' => $cat['ignore'],
            ];
        }

        return $summary;
    }

    /**
     * Test patterns against a list of group names.
     *
     * @param array $group_names Array of group names.
     * @return array Test results by type.
     */
    public function test_patterns(array $group_names): array
    {
        $results = [
            self::TYPE_CLASS => [],
            self::TYPE_TEACHER => [],
            self::TYPE_PROJECT => [],
            self::TYPE_IGNORE => [],
            self::TYPE_UNKNOWN => [],
            'counts' => [
                self::TYPE_CLASS => 0,
                self::TYPE_TEACHER => 0,
                self::TYPE_PROJECT => 0,
                self::TYPE_IGNORE => 0,
                self::TYPE_UNKNOWN => 0,
            ],
        ];

        foreach ($group_names as $name) {
            $type = $this->get_type($name);
            $results[$type][] = $name;
            $results['counts'][$type]++;
        }

        return $results;
    }
}
