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
 * Category path resolver - creates Moodle categories from path strings.
 *
 * Takes category paths like "Klassen/Stufe 10" and ensures the category
 * structure exists, creating categories as needed.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves category paths and creates categories as needed.
 */
class category_path_resolver {

    /** @var array Cache of resolved category IDs [path => id] */
    protected array $cache = [];

    /** @var bool Dry run mode */
    protected bool $dry_run = false;

    /** @var int Parent category ID for all sync categories */
    protected int $parent_category_id = 0;

    /** @var string Parent category path (for cache key building) */
    protected string $parent_path = '';

    /** @var array Statistics */
    protected array $stats = [
        'categories_created' => 0,
        'categories_found' => 0,
    ];

    /** @var array Log of created categories */
    protected array $created_categories = [];

    /**
     * Constructor.
     *
     * @param int $parent_category_id Optional parent category for all sync categories.
     */
    public function __construct(int $parent_category_id = 0) {
        $this->parent_category_id = $parent_category_id;
        $this->load_existing_categories();
    }

    /**
     * Set dry run mode.
     *
     * @param bool $dry_run Whether to enable dry run.
     */
    public function set_dry_run(bool $dry_run): void {
        $this->dry_run = $dry_run;
    }

    /**
     * Load existing categories into cache.
     */
    protected function load_existing_categories(): void {
        global $DB;

        // Build category path cache from database.
        $categories = $DB->get_records('course_categories', [], 'parent, sortorder');

        $paths = [];
        foreach ($categories as $cat) {
            $paths[$cat->id] = $cat;
        }

        // Build full paths.
        foreach ($paths as $cat) {
            $path = $this->build_path($cat, $paths);
            $this->cache[$path] = $cat->id;
        }

        // Get parent path if parent category is set.
        if ($this->parent_category_id > 0 && isset($paths[$this->parent_category_id])) {
            $this->parent_path = $this->build_path($paths[$this->parent_category_id], $paths);
        }
    }

    /**
     * Build full path for a category.
     *
     * @param object $category Category object.
     * @param array $all_categories All categories indexed by ID.
     * @return string Full path.
     */
    protected function build_path(object $category, array $all_categories): string {
        $parts = [$category->name];
        $parent_id = $category->parent;

        while ($parent_id > 0 && isset($all_categories[$parent_id])) {
            array_unshift($parts, $all_categories[$parent_id]->name);
            $parent_id = $all_categories[$parent_id]->parent;
        }

        return implode('/', $parts);
    }

    /**
     * Resolve a category path to a category ID, creating as needed.
     *
     * @param string $path Category path (/ separated).
     * @return int Category ID (-1 in dry run if would be created).
     */
    public function resolve(string $path): int {
        // Normalize path.
        $path = trim($path, '/');
        if (empty($path)) {
            return $this->parent_category_id > 0 ? $this->parent_category_id : 1;
        }

        // Build full path including parent.
        $full_path = $this->parent_path ? $this->parent_path . '/' . $path : $path;

        // Check cache.
        if (isset($this->cache[$full_path])) {
            $this->stats['categories_found']++;
            return $this->cache[$full_path];
        }

        // Create the category path.
        return $this->create_path($path);
    }

    /**
     * Create a category path, creating intermediate categories as needed.
     *
     * @param string $path Category path (relative to parent).
     * @return int Final category ID.
     */
    protected function create_path(string $path): int {
        global $DB;

        $parts = explode('/', $path);
        $current_parent = $this->parent_category_id;
        $current_path = $this->parent_path;

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            $search_path = $current_path ? $current_path . '/' . $part : $part;

            // Check if this level exists in cache.
            if (isset($this->cache[$search_path])) {
                $current_parent = $this->cache[$search_path];
                $current_path = $search_path;
                continue;
            }

            // Check database.
            $existing = $DB->get_record('course_categories', [
                'name' => $part,
                'parent' => $current_parent,
            ]);

            if ($existing) {
                $this->cache[$search_path] = $existing->id;
                $current_parent = $existing->id;
                $current_path = $search_path;
                $this->stats['categories_found']++;
                continue;
            }

            // Create the category.
            if ($this->dry_run) {
                // Return placeholder in dry run.
                $this->created_categories[] = [
                    'path' => $search_path,
                    'name' => $part,
                    'parent' => $current_parent,
                    'dry_run' => true,
                ];
                return -1;
            }

            $new_cat = new \stdClass();
            $new_cat->name = $part;
            $new_cat->parent = $current_parent;
            $new_cat->description = 'Automatisch erstellt durch Keycloak-Sync';
            $new_cat->descriptionformat = FORMAT_HTML;
            $new_cat->visible = 1;

            try {
                $category = \core_course_category::create($new_cat);
                $this->cache[$search_path] = $category->id;
                $current_parent = $category->id;
                $current_path = $search_path;
                $this->stats['categories_created']++;

                $this->created_categories[] = [
                    'path' => $search_path,
                    'name' => $part,
                    'id' => $category->id,
                    'parent' => $new_cat->parent,
                ];
            } catch (\Exception $e) {
                // If creation fails, try to find again (race condition).
                $existing = $DB->get_record('course_categories', [
                    'name' => $part,
                    'parent' => $current_parent,
                ]);
                if ($existing) {
                    $this->cache[$search_path] = $existing->id;
                    $current_parent = $existing->id;
                    $current_path = $search_path;
                } else {
                    // Fall back to parent.
                    return $current_parent > 0 ? $current_parent : 1;
                }
            }
        }

        return $current_parent > 0 ? $current_parent : 1;
    }

    /**
     * Get statistics.
     *
     * @return array Stats.
     */
    public function get_stats(): array {
        return $this->stats;
    }

    /**
     * Get list of created categories.
     *
     * @return array Created categories.
     */
    public function get_created_categories(): array {
        return $this->created_categories;
    }

    /**
     * Get cached category paths.
     *
     * @return array Cache [path => id].
     */
    public function get_cache(): array {
        return $this->cache;
    }

    /**
     * Set parent category by ID.
     *
     * @param int $category_id Parent category ID.
     */
    public function set_parent_category(int $category_id): void {
        $this->parent_category_id = $category_id;
        $this->load_existing_categories();
    }

    /**
     * Reset statistics and created categories list.
     */
    public function reset_stats(): void {
        $this->stats = [
            'categories_created' => 0,
            'categories_found' => 0,
        ];
        $this->created_categories = [];
    }
}
