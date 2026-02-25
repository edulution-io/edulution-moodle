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
 * Naming schema rule for transforming Keycloak groups to Moodle courses.
 *
 * A schema defines how to parse a Keycloak group name using regex patterns
 * with named capture groups, then applies templates to generate course names
 * and determine category placement.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * Represents a single naming schema rule.
 */
class naming_schema
{

    /** @var string Unique identifier */
    protected string $id;

    /** @var string Human-readable description */
    protected string $description;

    /** @var int Priority (lower = higher priority) */
    protected int $priority;

    /** @var string PCRE regex pattern with named capture groups */
    protected string $pattern;

    /** @var string Template for course fullname */
    protected string $course_name_template;

    /** @var string Template for course shortname */
    protected string $course_shortname_template;

    /** @var string Template for category path */
    protected string $category_path_template;

    /** @var string Prefix for course idnumber */
    protected string $idnumber_prefix;

    /** @var array Role mapping configuration */
    protected array $role_map;

    /** @var bool Whether schema is enabled */
    protected bool $enabled;

    /**
     * Constructor.
     *
     * @param array $config Schema configuration from JSON.
     */
    public function __construct(array $config)
    {
        $this->id = $config['id'] ?? '';
        $this->description = $config['description'] ?? '';
        $this->priority = (int) ($config['priority'] ?? 999);
        $this->pattern = $config['pattern'] ?? '';
        $this->course_name_template = $config['course_name'] ?? '{name}';
        $this->course_shortname_template = $config['course_shortname'] ?? '{name}';
        $this->category_path_template = $config['category_path'] ?? 'Sonstiges';
        $this->idnumber_prefix = $config['course_idnumber_prefix'] ?? 'kc_';
        $this->role_map = $config['role_map'] ?? ['default' => 'student'];
        $this->enabled = (bool) ($config['enabled'] ?? true);
    }

    /**
     * Check if this schema matches a group name.
     *
     * @param string $group_name The group name to test.
     * @return bool True if the pattern matches.
     */
    public function matches(string $group_name): bool
    {
        if (!$this->enabled || empty($this->pattern)) {
            return false;
        }

        $regex = $this->build_regex();
        return @preg_match($regex, $group_name) === 1;
    }

    /**
     * Extract captured groups from the group name.
     *
     * @param string $group_name The group name to parse.
     * @return array|null Captured groups or null if no match.
     */
    public function extract(string $group_name): ?array
    {
        $regex = $this->build_regex();

        if (preg_match($regex, $group_name, $matches) !== 1) {
            return null;
        }

        // Filter to named groups only (remove numeric keys).
        $named_groups = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $named_groups[$key] = $value;
            }
        }

        // Also add special variables.
        $named_groups['_original'] = $group_name;
        $named_groups['_full_match'] = $matches[0] ?? $group_name;

        return $named_groups;
    }

    /**
     * Build the PCRE regex from the pattern.
     *
     * @return string PCRE regex with delimiters.
     */
    protected function build_regex(): string
    {
        $delimiter = substr($this->pattern, 0, 1) === '/' ? '' : '/';
        return $delimiter . $this->pattern . $delimiter . 'u';
    }

    /**
     * Generate the course fullname from captured groups.
     *
     * @param array $groups Captured named groups.
     * @param template_transformer $transformer Transformer instance.
     * @return string Generated course name.
     */
    public function generate_course_name(array $groups, template_transformer $transformer): string
    {
        return $transformer->apply($this->course_name_template, $groups);
    }

    /**
     * Generate the course shortname from captured groups.
     *
     * @param array $groups Captured named groups.
     * @param template_transformer $transformer Transformer instance.
     * @return string Generated shortname.
     */
    public function generate_course_shortname(array $groups, template_transformer $transformer): string
    {
        $shortname = $transformer->apply($this->course_shortname_template, $groups);
        // Ensure shortname is valid (no spaces, limited characters).
        $shortname = preg_replace('/[^a-zA-Z0-9_-]/', '_', $shortname);
        return substr($shortname, 0, 100);
    }

    /**
     * Generate the category path from captured groups.
     *
     * @param array $groups Captured named groups.
     * @param template_transformer $transformer Transformer instance.
     * @return string Category path (/ separated).
     */
    public function generate_category_path(array $groups, template_transformer $transformer): string
    {
        return $transformer->apply($this->category_path_template, $groups);
    }

    /**
     * Generate the course idnumber.
     *
     * @param string $group_name Original group name.
     * @return string Course idnumber.
     */
    public function generate_idnumber(string $group_name): string
    {
        $safe_name = preg_replace('/[^a-z0-9_-]/', '_', strtolower($group_name));
        return $this->idnumber_prefix . $safe_name;
    }

    // Getters.
    public function get_id(): string
    {
        return $this->id;
    }

    public function get_description(): string
    {
        return $this->description;
    }

    public function get_priority(): int
    {
        return $this->priority;
    }

    public function get_pattern(): string
    {
        return $this->pattern;
    }

    public function get_role_map(): array
    {
        return $this->role_map;
    }

    public function is_enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Export schema configuration for display/editing.
     *
     * @return array Schema configuration.
     */
    public function export(): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'priority' => $this->priority,
            'pattern' => $this->pattern,
            'course_name' => $this->course_name_template,
            'course_shortname' => $this->course_shortname_template,
            'category_path' => $this->category_path_template,
            'course_idnumber_prefix' => $this->idnumber_prefix,
            'role_map' => $this->role_map,
            'enabled' => $this->enabled,
        ];
    }
}
