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
 * Abstract base class for data exporters.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

/**
 * Abstract base class that all exporters must extend.
 *
 * Provides common functionality for writing files, tracking progress,
 * and handling export options.
 */
abstract class base_exporter
{

    /** @var export_options Export options */
    protected export_options $options;

    /** @var progress_tracker Progress tracker */
    protected progress_tracker $tracker;

    /** @var string Base export directory */
    protected string $basedir;

    /** @var array Export statistics */
    protected array $stats = [];

    /** @var array Metadata about exported files */
    protected array $exported_files = [];

    /**
     * Constructor.
     *
     * @param export_options $options Export configuration.
     * @param progress_tracker $tracker Progress tracker.
     * @param string $basedir Base directory for export files.
     */
    public function __construct(export_options $options, progress_tracker $tracker, string $basedir)
    {
        $this->options = $options;
        $this->tracker = $tracker;
        $this->basedir = $basedir;
    }

    /**
     * Get the exporter name for progress reporting.
     *
     * @return string Human-readable name.
     */
    abstract public function get_name(): string;

    /**
     * Get the language string key for this exporter.
     *
     * @return string Language string key (without plugin prefix).
     */
    abstract public function get_string_key(): string;

    /**
     * Execute the export.
     *
     * @return array Exported data or metadata.
     * @throws \moodle_exception On export failure.
     */
    abstract public function export(): array;

    /**
     * Get the number of items to export (for progress tracking).
     *
     * @return int Total count of items.
     */
    abstract public function get_total_count(): int;

    /**
     * Get export statistics.
     *
     * @return array Statistics array.
     */
    public function get_stats(): array
    {
        return $this->stats;
    }

    /**
     * Get list of exported files with metadata.
     *
     * @return array Exported files array.
     */
    public function get_exported_files(): array
    {
        return $this->exported_files;
    }

    /**
     * Update progress tracker.
     *
     * @param int $current Current item number.
     * @param string $message Optional status message.
     */
    protected function update_progress(int $current, string $message = ''): void
    {
        $this->tracker->update($current, $message);
    }

    /**
     * Increment progress by one.
     *
     * @param string $message Optional status message.
     */
    protected function increment_progress(string $message = ''): void
    {
        $this->tracker->increment($message);
    }

    /**
     * Log a message.
     *
     * @param string $level Log level (info, warning, error, debug).
     * @param string $message Log message.
     */
    protected function log(string $level, string $message): void
    {
        $this->tracker->log($level, "[{$this->get_name()}] {$message}");
    }

    /**
     * Write data to JSON file.
     *
     * @param array $data Data to write.
     * @param string $filename Filename (relative to basedir).
     * @return string Full path to written file.
     */
    protected function write_json(array $data, string $filename): string
    {
        $path = $this->basedir . '/' . $filename;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json);

        // Track exported file.
        $this->exported_files[] = [
            'filename' => $filename,
            'path' => $path,
            'size' => strlen($json),
            'type' => 'json',
        ];

        $this->log('debug', "Wrote {$filename} (" . $this->format_size(strlen($json)) . ")");

        return $path;
    }

    /**
     * Write binary data to file.
     *
     * @param string $data Binary data.
     * @param string $filename Filename (relative to basedir).
     * @return string Full path to written file.
     */
    protected function write_file(string $data, string $filename): string
    {
        $path = $this->basedir . '/' . $filename;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $data);

        // Track exported file.
        $this->exported_files[] = [
            'filename' => $filename,
            'path' => $path,
            'size' => strlen($data),
            'type' => pathinfo($filename, PATHINFO_EXTENSION),
        ];

        return $path;
    }

    /**
     * Copy a file to the export directory.
     *
     * @param string $source Source file path.
     * @param string $destination Destination path (relative to basedir).
     * @return string|null Full path to copied file, or null on failure.
     */
    protected function copy_file(string $source, string $destination): ?string
    {
        if (!file_exists($source)) {
            $this->log('warning', "Source file not found: {$source}");
            return null;
        }

        $destPath = $this->basedir . '/' . $destination;
        $dir = dirname($destPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (copy($source, $destPath)) {
            $size = filesize($destPath);

            // Track exported file.
            $this->exported_files[] = [
                'filename' => $destination,
                'path' => $destPath,
                'size' => $size,
                'type' => pathinfo($destination, PATHINFO_EXTENSION),
            ];

            return $destPath;
        }

        $this->log('warning', "Failed to copy file: {$source}");
        return null;
    }

    /**
     * Get a sub-directory path, creating it if necessary.
     *
     * @param string $subdir Subdirectory name.
     * @return string Full path to subdirectory.
     */
    protected function get_subdir(string $subdir): string
    {
        $path = $this->basedir . '/' . $subdir;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        return $path;
    }

    /**
     * Format a timestamp for export.
     *
     * @param int $timestamp Unix timestamp.
     * @return string|null ISO 8601 formatted date, or null if timestamp is 0.
     */
    protected function format_timestamp(int $timestamp): ?string
    {
        if ($timestamp === 0) {
            return null;
        }
        return date('c', $timestamp);
    }

    /**
     * Format a date for export.
     *
     * @param int $timestamp Unix timestamp.
     * @return string|null Date string (Y-m-d), or null if timestamp is 0.
     */
    protected function format_date(int $timestamp): ?string
    {
        if ($timestamp === 0) {
            return null;
        }
        return date('Y-m-d', $timestamp);
    }

    /**
     * Format file size for display.
     *
     * @param int $bytes File size in bytes.
     * @return string Formatted size string.
     */
    protected function format_size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Check if a user should be exported based on filters.
     *
     * @param int $userid User ID.
     * @return bool True if user should be exported.
     */
    protected function should_export_user(int $userid): bool
    {
        if (empty($this->options->user_ids)) {
            return true;
        }
        return in_array($userid, $this->options->user_ids);
    }

    /**
     * Check if a course should be exported based on filters.
     *
     * @param int $courseid Course ID.
     * @return bool True if course should be exported.
     */
    protected function should_export_course(int $courseid): bool
    {
        if (empty($this->options->course_ids)) {
            return true;
        }
        return in_array($courseid, $this->options->course_ids);
    }

    /**
     * Check if a category should be exported based on filters.
     *
     * @param int $categoryid Category ID.
     * @return bool True if category should be exported.
     */
    protected function should_export_category(int $categoryid): bool
    {
        if (empty($this->options->category_ids)) {
            return true;
        }
        return in_array($categoryid, $this->options->category_ids);
    }

    /**
     * Get database table count with optional conditions.
     *
     * @param string $table Table name (without prefix).
     * @param array $conditions Optional conditions.
     * @return int Record count.
     */
    protected function get_count(string $table, array $conditions = []): int
    {
        global $DB;
        return $DB->count_records($table, $conditions);
    }

    /**
     * Get records with optional filtering.
     *
     * @param string $table Table name.
     * @param string $select WHERE clause.
     * @param array $params Query parameters.
     * @param string $sort Sort order.
     * @param string $fields Fields to select.
     * @param int $limitfrom Limit offset.
     * @param int $limitnum Limit count.
     * @return array Records.
     */
    protected function get_records(
        string $table,
        string $select = '',
        array $params = [],
        string $sort = '',
        string $fields = '*',
        int $limitfrom = 0,
        int $limitnum = 0
    ): array {
        global $DB;

        if (empty($select)) {
            return $DB->get_records($table, null, $sort, $fields, $limitfrom, $limitnum);
        }

        return $DB->get_records_select($table, $select, $params, $sort, $fields, $limitfrom, $limitnum);
    }

    /**
     * Calculate checksum for a file.
     *
     * @param string $filepath Path to file.
     * @param string $algo Hash algorithm (default: sha256).
     * @return string|null Checksum or null on failure.
     */
    protected function calculate_checksum(string $filepath, string $algo = 'sha256'): ?string
    {
        if (!file_exists($filepath)) {
            return null;
        }

        return hash_file($algo, $filepath);
    }

    /**
     * Add a statistic.
     *
     * @param string $key Statistic key.
     * @param mixed $value Statistic value.
     */
    protected function add_stat(string $key, $value): void
    {
        $this->stats[$key] = $value;
    }

    /**
     * Increment a numeric statistic.
     *
     * @param string $key Statistic key.
     * @param int $amount Amount to increment (default: 1).
     */
    protected function increment_stat(string $key, int $amount = 1): void
    {
        if (!isset($this->stats[$key])) {
            $this->stats[$key] = 0;
        }
        $this->stats[$key] += $amount;
    }
}
