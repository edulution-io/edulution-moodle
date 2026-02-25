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
 * Export options configuration class.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

/**
 * Class representing export configuration options.
 *
 * This class holds all configuration settings for an export operation,
 * including what data to export, filtering options, and output settings.
 */
class export_options
{

    // Export mode flags.
    /** @var bool Full database export mode (mysqldump + moodledata) */
    public bool $full_db = false;

    /** @var bool Include moodledata in export */
    public bool $include_moodledata = true;

    // Data selection flags.
    /** @var bool Export users */
    public bool $include_users = true;

    /** @var bool Export courses (with .mbz backups) */
    public bool $include_courses = true;

    /** @var bool Export categories */
    public bool $include_categories = true;

    /** @var bool Export enrollments */
    public bool $include_enrollments = true;

    /** @var bool Export groups and groupings */
    public bool $include_groups = true;

    /** @var bool Export plugin information */
    public bool $include_plugins = true;

    /** @var bool Export configuration settings */
    public bool $include_config = true;

    /** @var bool Export files (profile pictures, etc.) */
    public bool $include_files = true;

    /** @var bool Export grades */
    public bool $include_grades = false;

    /** @var bool Export completion data */
    public bool $include_completions = false;

    /** @var bool Export roles and capabilities */
    public bool $include_roles = true;

    // Selective export filters.
    /** @var array Category IDs to export (empty = all) */
    public array $category_ids = [];

    /** @var array Course IDs to export (empty = all) */
    public array $course_ids = [];

    /** @var array User IDs to export (empty = all) */
    public array $user_ids = [];

    // Database export options.
    /** @var string Tables to exclude from database dump (comma-separated) */
    public string $exclude_tables = '';

    /** @var bool Compress database dump with gzip */
    public bool $compress_database = true;

    // Course backup options.
    /** @var bool Include course files in .mbz backups */
    public bool $include_course_files = true;

    /** @var bool Include user data (attempts, submissions) in course backups */
    public bool $include_user_data = false;

    /** @var bool Skip .mbz backup generation (metadata only) */
    public bool $skip_course_backups = false;

    // User export options.
    /** @var bool Anonymize user data */
    public bool $anonymize_users = false;

    /** @var bool Export user profile pictures */
    public bool $export_profile_pictures = true;

    // Config export options.
    /** @var bool Sanitize config (remove passwords/secrets) */
    public bool $sanitize_config = true;

    /** @var bool Export theme settings */
    public bool $include_theme_settings = true;

    // Compression and output options.
    /** @var int Compression level (0-9, 0 = no compression) */
    public int $compression_level = 6;

    /** @var bool Split large files */
    public bool $split_large_files = true;

    /** @var int Split threshold in MB */
    public int $split_threshold_mb = 500;

    /** @var string Output filename (auto-generated if empty) */
    public string $output_filename = '';

    /** @var string Output directory */
    public string $output_directory = '';

    // Runtime flags.
    /** @var bool Validate only mode (no actual export) */
    public bool $validate_only = false;

    /** @var bool Verbose output */
    public bool $verbose = false;

    /** @var bool Quiet mode (minimal output) */
    public bool $quiet = false;

    /** @var bool Dry run (simulate export without writing files) */
    public bool $dry_run = false;

    /**
     * Create options from form data.
     *
     * @param array|object $formdata Form submission data.
     * @return self New export_options instance.
     */
    public static function from_form($formdata): self
    {
        $formdata = (array) $formdata;
        $options = new self();

        // Export mode.
        $options->full_db = !empty($formdata['full_db']);
        $options->include_moodledata = !empty($formdata['include_moodledata']);

        // If full database export, adjust individual flags.
        if ($options->full_db) {
            $options->include_plugins = true; // Keep for compatibility check.
            $options->include_config = true;
            // Other individual exports are handled by database dump.
            $options->include_users = false;
            $options->include_courses = false;
            $options->include_categories = false;
            $options->include_enrollments = false;
            $options->include_groups = false;
            $options->include_files = false;
        } else {
            // Data selection.
            $options->include_users = !empty($formdata['include_users']);
            $options->include_courses = !empty($formdata['include_courses']);
            $options->include_categories = !empty($formdata['include_categories']);
            $options->include_enrollments = !empty($formdata['include_enrollments']);
            $options->include_groups = !empty($formdata['include_groups']);
            $options->include_plugins = !empty($formdata['include_plugins']);
            $options->include_config = !empty($formdata['include_config']);
            $options->include_files = !empty($formdata['include_files']);
            $options->include_grades = !empty($formdata['include_grades']);
            $options->include_completions = !empty($formdata['include_completions']);
            $options->include_roles = !empty($formdata['include_roles']);
        }

        // Selective filters.
        $options->category_ids = self::parse_id_list($formdata['category_ids'] ?? '');
        $options->course_ids = self::parse_id_list($formdata['course_ids'] ?? '');
        $options->user_ids = self::parse_id_list($formdata['user_ids'] ?? '');

        // Database options.
        if (!empty($formdata['exclude_tables'])) {
            $options->exclude_tables = trim($formdata['exclude_tables']);
        }
        $options->compress_database = $formdata['compress_database'] ?? true;

        // Course backup options.
        $options->include_course_files = !empty($formdata['include_course_files']);
        $options->include_user_data = !empty($formdata['include_user_data']);
        $options->skip_course_backups = !empty($formdata['skip_course_backups']);

        // User options.
        $options->anonymize_users = !empty($formdata['anonymize_users']);
        $options->export_profile_pictures = !empty($formdata['export_profile_pictures']);

        // Config options.
        $options->sanitize_config = $formdata['sanitize_config'] ?? true;
        $options->include_theme_settings = !empty($formdata['include_theme_settings']);

        // Compression.
        if (isset($formdata['compression_level'])) {
            $options->compression_level = max(0, min(9, (int) $formdata['compression_level']));
        }

        return $options;
    }

    /**
     * Create options from CLI arguments.
     *
     * @param array $args CLI arguments from get_cli_args().
     * @return self New export_options instance.
     */
    public static function from_cli(array $args): self
    {
        $options = new self();

        // Check for full database mode.
        if (!empty($args['full-db'])) {
            $options->full_db = true;
            $options->include_plugins = true;
            $options->include_config = true;
            $options->include_moodledata = !isset($args['no-moodledata']);

            if (!empty($args['exclude-tables'])) {
                $options->exclude_tables = $args['exclude-tables'];
            }

            return $options;
        }

        // Check for --all flag.
        $exportAll = !empty($args['all']);

        // Default all flags to false unless --all is specified.
        if (!$exportAll) {
            $options->include_users = false;
            $options->include_courses = false;
            $options->include_categories = false;
            $options->include_enrollments = false;
            $options->include_groups = false;
            $options->include_plugins = false;
            $options->include_config = false;
            $options->include_files = false;
            $options->include_roles = false;
        }

        // Individual flags.
        if (!empty($args['users'])) {
            $options->include_users = true;
        }
        if (!empty($args['courses'])) {
            $options->include_courses = true;
        }
        if (!empty($args['categories'])) {
            $options->include_categories = true;
        }
        if (!empty($args['enrollments'])) {
            $options->include_enrollments = true;
        }
        if (!empty($args['groups'])) {
            $options->include_groups = true;
        }
        if (!empty($args['plugins'])) {
            $options->include_plugins = true;
        }
        if (!empty($args['config'])) {
            $options->include_config = true;
        }
        if (!empty($args['files'])) {
            $options->include_files = true;
        }
        if (!empty($args['grades'])) {
            $options->include_grades = true;
        }
        if (!empty($args['completions'])) {
            $options->include_completions = true;
        }
        if (!empty($args['roles'])) {
            $options->include_roles = true;
        }

        // Selective filters.
        if (!empty($args['category'])) {
            $options->category_ids = array_map('intval', explode(',', $args['category']));
        }
        if (!empty($args['course'])) {
            $options->course_ids = array_map('intval', explode(',', $args['course']));
        }
        if (!empty($args['user'])) {
            $options->user_ids = array_map('intval', explode(',', $args['user']));
        }

        // Output options.
        if (!empty($args['output'])) {
            $path = $args['output'];
            if (is_dir($path)) {
                $options->output_directory = $path;
            } else {
                $options->output_directory = dirname($path);
                $options->output_filename = basename($path);
            }
        }

        // Additional options.
        if (isset($args['compression'])) {
            $options->compression_level = max(0, min(9, (int) $args['compression']));
        }

        $options->anonymize_users = !empty($args['anonymize']);
        $options->skip_course_backups = !empty($args['no-backups']);
        $options->validate_only = !empty($args['validate-only']);
        $options->verbose = !empty($args['verbose']);
        $options->quiet = !empty($args['quiet']);
        $options->dry_run = !empty($args['dry-run']);

        return $options;
    }

    /**
     * Create options from an array.
     *
     * @param array $data Options array.
     * @return self New export_options instance.
     */
    public static function from_array(array $data): self
    {
        $options = new self();

        foreach ($data as $key => $value) {
            if (property_exists($options, $key)) {
                $options->$key = $value;
            }
        }

        return $options;
    }

    /**
     * Validate options configuration.
     *
     * @return array Array of validation errors (empty if valid).
     */
    public function validate(): array
    {
        $errors = [];

        // Check that at least one data type is selected.
        if (!$this->full_db && !$this->has_any_export_enabled()) {
            $errors[] = get_string('error_no_data_selected', 'local_edulution');
        }

        // Validate compression level.
        if ($this->compression_level < 0 || $this->compression_level > 9) {
            $errors[] = get_string('error_invalid_compression', 'local_edulution');
        }

        // Validate split threshold.
        if ($this->split_threshold_mb < 1) {
            $errors[] = get_string('error_invalid_split_threshold', 'local_edulution');
        }

        // Validate output directory.
        if (!empty($this->output_directory)) {
            if (!is_dir($this->output_directory)) {
                $errors[] = get_string('error_output_dir_not_exists', 'local_edulution', $this->output_directory);
            } elseif (!is_writable($this->output_directory)) {
                $errors[] = get_string('error_output_dir_not_writable', 'local_edulution', $this->output_directory);
            }
        }

        // Validate ID filters contain valid integers.
        foreach (['category_ids', 'course_ids', 'user_ids'] as $field) {
            foreach ($this->$field as $id) {
                if (!is_int($id) || $id < 1) {
                    $errors[] = get_string('error_invalid_id_filter', 'local_edulution', $field);
                    break;
                }
            }
        }

        // Mutual exclusivity checks.
        if ($this->verbose && $this->quiet) {
            $errors[] = get_string('error_verbose_quiet_conflict', 'local_edulution');
        }

        return $errors;
    }

    /**
     * Check if any export type is enabled.
     *
     * @return bool True if at least one export type is enabled.
     */
    public function has_any_export_enabled(): bool
    {
        return $this->include_users
            || $this->include_courses
            || $this->include_categories
            || $this->include_enrollments
            || $this->include_groups
            || $this->include_plugins
            || $this->include_config
            || $this->include_files
            || $this->include_grades
            || $this->include_completions
            || $this->include_roles;
    }

    /**
     * Get output directory, using default if not set.
     *
     * @return string Output directory path.
     */
    public function get_output_directory(): string
    {
        global $CFG;

        if (!empty($this->output_directory)) {
            return $this->output_directory;
        }

        // Use plugin config or fallback to dataroot.
        $dir = get_config('local_edulution', 'export_dir');
        if (empty($dir)) {
            $dir = $CFG->dataroot . '/edulution_exports';
        }

        // Create directory if it doesn't exist.
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Get output filename, generating one if not set.
     *
     * @return string Output filename.
     */
    public function get_output_filename(): string
    {
        if (!empty($this->output_filename)) {
            return $this->output_filename;
        }

        $prefix = $this->full_db ? 'edulution_full_export' : 'edulution_export';
        return $prefix . '_' . date('Y-m-d_His') . '.zip';
    }

    /**
     * Get full output path.
     *
     * @return string Full path to output file.
     */
    public function get_output_path(): string
    {
        return $this->get_output_directory() . '/' . $this->get_output_filename();
    }

    /**
     * Convert options to array for serialization.
     *
     * @return array Options as associative array.
     */
    public function to_array(): array
    {
        return [
            // Export mode.
            'full_db' => $this->full_db,
            'include_moodledata' => $this->include_moodledata,

            // Data selection.
            'include_users' => $this->include_users,
            'include_courses' => $this->include_courses,
            'include_categories' => $this->include_categories,
            'include_enrollments' => $this->include_enrollments,
            'include_groups' => $this->include_groups,
            'include_plugins' => $this->include_plugins,
            'include_config' => $this->include_config,
            'include_files' => $this->include_files,
            'include_grades' => $this->include_grades,
            'include_completions' => $this->include_completions,
            'include_roles' => $this->include_roles,

            // Filters.
            'category_ids' => $this->category_ids,
            'course_ids' => $this->course_ids,
            'user_ids' => $this->user_ids,

            // Database options.
            'exclude_tables' => $this->exclude_tables,
            'compress_database' => $this->compress_database,

            // Course backup options.
            'include_course_files' => $this->include_course_files,
            'include_user_data' => $this->include_user_data,
            'skip_course_backups' => $this->skip_course_backups,

            // User options.
            'anonymize_users' => $this->anonymize_users,
            'export_profile_pictures' => $this->export_profile_pictures,

            // Config options.
            'sanitize_config' => $this->sanitize_config,
            'include_theme_settings' => $this->include_theme_settings,

            // Compression.
            'compression_level' => $this->compression_level,
            'split_large_files' => $this->split_large_files,
            'split_threshold_mb' => $this->split_threshold_mb,
        ];
    }

    /**
     * Parse a comma-separated list of IDs.
     *
     * @param string|array $value Value to parse.
     * @return array Array of integers.
     */
    private static function parse_id_list($value): array
    {
        if (empty($value)) {
            return [];
        }

        if (is_array($value)) {
            return array_map('intval', array_filter($value));
        }

        return array_map('intval', array_filter(explode(',', $value)));
    }
}
