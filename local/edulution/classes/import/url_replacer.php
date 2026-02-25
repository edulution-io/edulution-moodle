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
 * URL replacer - handles domain replacement in Moodle database.
 *
 * This class finds all URL columns in Moodle tables and replaces
 * the old domain with a new domain, handling serialized data carefully.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\import;

/**
 * URL replacer class for migrating URLs between domains.
 */
class url_replacer
{

    /** @var \mysqli Database connection */
    protected \mysqli $db;

    /** @var string Table prefix */
    protected string $prefix;

    /** @var array Tables and columns known to contain URLs */
    protected array $urlcolumns = [];

    /** @var array Tables and columns known to contain serialized data */
    protected array $serializedcolumns = [];

    /** @var int Total replacements made */
    protected int $totalreplacements = 0;

    /** @var array Detailed replacement log */
    protected array $replacementlog = [];

    /** @var callable|null Progress callback */
    protected $progresscallback = null;

    /**
     * Constructor.
     *
     * @param \mysqli $db Database connection.
     * @param string $prefix Table prefix.
     */
    public function __construct(\mysqli $db, string $prefix = 'mdl_')
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->init_known_columns();
    }

    /**
     * Initialize known URL and serialized columns.
     * These are the most common columns that contain URLs in Moodle.
     */
    protected function init_known_columns(): void
    {
        // Columns that commonly contain URLs (plain text)
        $this->urlcolumns = [
            'config' => ['value'], // Contains wwwroot, etc.
            'config_plugins' => ['value'],
            'course' => ['summary'],
            'course_sections' => ['summary'],
            'label' => ['intro'],
            'page' => ['content', 'intro'],
            'book_chapters' => ['content'],
            'lesson_pages' => ['contents'],
            'glossary_entries' => ['definition'],
            'forum_posts' => ['message'],
            'wiki_pages' => ['cachedcontent'],
            'data_content' => ['content'],
            'assign_submission_onlinetext' => ['onlinetext'],
            'assignfeedback_comments' => ['commenttext'],
            'block_instances' => ['configdata'], // Serialized
            'question' => ['questiontext', 'generalfeedback'],
            'question_answers' => ['answer', 'feedback'],
            'quiz_slots' => [],
            'url' => ['externalurl', 'intro'],
            'user' => ['description'],
            'grade_items' => ['calculation'],
            'grade_categories' => [],
            'feedback_item' => ['presentation'],
            'h5pactivity' => ['intro'],
            'scorm' => ['intro', 'reference'],
            'resource' => ['intro'],
            'folder' => ['intro'],
            'imscp' => ['intro'],
            'lti' => ['toolurl', 'securetoolurl', 'intro', 'instructorcustomparameters'],
            'bigbluebuttonbn' => ['intro', 'meetingid'],
            'hvp' => ['intro'],
            'chat' => ['intro'],
            'choice' => ['intro'],
            'workshop' => ['intro', 'instructauthors', 'instructreviewers', 'conclusion'],
        ];

        // Columns known to contain serialized PHP data
        $this->serializedcolumns = [
            'block_instances' => ['configdata'],
            'question_attempt_step_data' => ['value'],
            'config' => ['value'], // Some config values are serialized
            'config_plugins' => ['value'],
            'cache_config' => ['value'],
            'repository_instances' => ['config'],
            'user_preferences' => ['value'],
            'course_format_options' => ['value'],
            'grade_settings' => ['value'],
        ];
    }

    /**
     * Set progress callback.
     *
     * @param callable $callback Progress callback function.
     */
    public function set_progress_callback(callable $callback): void
    {
        $this->progresscallback = $callback;
    }

    /**
     * Report progress.
     *
     * @param string $message Progress message.
     */
    protected function progress(string $message): void
    {
        if ($this->progresscallback) {
            call_user_func($this->progresscallback, $message);
        }
    }

    /**
     * Replace all URLs in the database.
     *
     * @param string $oldurl Old URL to replace (e.g., 'https://old.example.com').
     * @param string $newurl New URL (e.g., 'https://new.example.com').
     * @return int Total number of replacements made.
     */
    public function replace_all(string $oldurl, string $newurl): int
    {
        $this->totalreplacements = 0;
        $this->replacementlog = [];

        // Normalize URLs (remove trailing slashes)
        $oldurl = rtrim($oldurl, '/');
        $newurl = rtrim($newurl, '/');

        if ($oldurl === $newurl) {
            return 0;
        }

        $this->progress("Starting URL replacement: {$oldurl} -> {$newurl}");

        // Get all tables with the prefix
        $tables = $this->get_all_tables();

        foreach ($tables as $table) {
            // Get text columns for this table
            $columns = $this->get_text_columns($table);

            if (empty($columns)) {
                continue;
            }

            // Check if this table has known URL columns
            $shorttable = str_replace($this->prefix, '', $table);

            foreach ($columns as $column) {
                // Check if this column might contain serialized data
                $isserialized = isset($this->serializedcolumns[$shorttable]) &&
                    in_array($column, $this->serializedcolumns[$shorttable]);

                // Replace URLs in this column
                $count = $this->replace_in_column($table, $column, $oldurl, $newurl, $isserialized);

                if ($count > 0) {
                    $this->replacementlog[] = [
                        'table' => $table,
                        'column' => $column,
                        'count' => $count,
                        'serialized' => $isserialized,
                    ];
                    $this->totalreplacements += $count;
                }
            }
        }

        $this->progress("URL replacement complete: {$this->totalreplacements} replacements");

        return $this->totalreplacements;
    }

    /**
     * Get all tables with the configured prefix.
     *
     * @return array List of table names.
     */
    protected function get_all_tables(): array
    {
        $tables = [];
        $result = $this->db->query("SHOW TABLES LIKE '{$this->prefix}%'");

        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }

        return $tables;
    }

    /**
     * Get text-type columns for a table.
     *
     * @param string $table Table name.
     * @return array List of column names.
     */
    protected function get_text_columns(string $table): array
    {
        $columns = [];
        $result = $this->db->query("DESCRIBE `{$table}`");

        if (!$result) {
            return $columns;
        }

        while ($row = $result->fetch_assoc()) {
            $type = strtolower($row['Type']);

            // Only process text-type columns
            if (
                strpos($type, 'text') !== false ||
                strpos($type, 'varchar') !== false ||
                strpos($type, 'char') !== false ||
                strpos($type, 'blob') !== false ||
                strpos($type, 'mediumtext') !== false ||
                strpos($type, 'longtext') !== false
            ) {
                $columns[] = $row['Field'];
            }
        }

        return $columns;
    }

    /**
     * Replace URLs in a specific column.
     *
     * @param string $table Table name.
     * @param string $column Column name.
     * @param string $oldurl Old URL.
     * @param string $newurl New URL.
     * @param bool $isserialized Whether to handle as serialized data.
     * @return int Number of replacements.
     */
    protected function replace_in_column(
        string $table,
        string $column,
        string $oldurl,
        string $newurl,
        bool $isserialized = false
    ): int {
        if ($isserialized) {
            return $this->replace_serialized($table, $column, $oldurl, $newurl);
        }

        // Simple string replacement using MySQL REPLACE
        $escapedold = $this->db->real_escape_string($oldurl);
        $escapednew = $this->db->real_escape_string($newurl);

        // Check if any rows contain the old URL
        $checkquery = "SELECT COUNT(*) as cnt FROM `{$table}` WHERE `{$column}` LIKE '%{$escapedold}%'";
        $result = $this->db->query($checkquery);

        if (!$result) {
            return 0;
        }

        $row = $result->fetch_assoc();
        if ($row['cnt'] == 0) {
            return 0;
        }

        // Perform the replacement
        $updatequery = "UPDATE `{$table}` SET `{$column}` = REPLACE(`{$column}`, '{$escapedold}', '{$escapednew}') WHERE `{$column}` LIKE '%{$escapedold}%'";

        if (!$this->db->query($updatequery)) {
            error_log("URL replacement failed in {$table}.{$column}: " . $this->db->error);
            return 0;
        }

        $affected = $this->db->affected_rows;

        if ($affected > 0) {
            $this->progress("  Replaced {$affected} rows in {$table}.{$column}");
        }

        return $affected;
    }

    /**
     * Replace URLs in serialized data.
     * This requires careful handling to maintain serialized string lengths.
     *
     * @param string $table Table name.
     * @param string $column Column name.
     * @param string $oldurl Old URL.
     * @param string $newurl New URL.
     * @return int Number of replacements.
     */
    protected function replace_serialized(
        string $table,
        string $column,
        string $oldurl,
        string $newurl
    ): int {
        $replacements = 0;

        // Get the primary key column
        $pkcolumn = $this->get_primary_key($table);
        if (!$pkcolumn) {
            $pkcolumn = 'id'; // Default assumption
        }

        // Find rows containing the old URL
        $escapedold = $this->db->real_escape_string($oldurl);
        $query = "SELECT `{$pkcolumn}`, `{$column}` FROM `{$table}` WHERE `{$column}` LIKE '%{$escapedold}%'";
        $result = $this->db->query($query);

        if (!$result) {
            return 0;
        }

        while ($row = $result->fetch_assoc()) {
            $pk = $row[$pkcolumn];
            $value = $row[$column];

            // Check if it's serialized data
            $unserialized = @unserialize($value);
            if ($unserialized !== false || $value === 'b:0;') {
                // It's serialized - do careful replacement
                $newvalue = $this->replace_in_serialized($value, $oldurl, $newurl);
            } else {
                // Not serialized - simple replacement
                $newvalue = str_replace($oldurl, $newurl, $value);
            }

            if ($newvalue !== $value) {
                $escapednew = $this->db->real_escape_string($newvalue);
                $updatequery = "UPDATE `{$table}` SET `{$column}` = '{$escapednew}' WHERE `{$pkcolumn}` = " .
                    (is_numeric($pk) ? $pk : "'{$this->db->real_escape_string($pk)}'");

                if ($this->db->query($updatequery)) {
                    $replacements++;
                }
            }
        }

        if ($replacements > 0) {
            $this->progress("  Replaced {$replacements} serialized rows in {$table}.{$column}");
        }

        return $replacements;
    }

    /**
     * Replace URLs within serialized data while maintaining correct string lengths.
     *
     * @param string $data Serialized data.
     * @param string $oldurl Old URL.
     * @param string $newurl New URL.
     * @return string Modified serialized data.
     */
    protected function replace_in_serialized(string $data, string $oldurl, string $newurl): string
    {
        // Pattern to match serialized strings containing the old URL
        // Format: s:LENGTH:"STRING";
        $pattern = '/s:(\d+):"([^"]*' . preg_quote($oldurl, '/') . '[^"]*)";/';

        return preg_replace_callback($pattern, function ($matches) use ($oldurl, $newurl) {
            $oldstring = $matches[2];
            $newstring = str_replace($oldurl, $newurl, $oldstring);
            $newlength = strlen($newstring);
            return 's:' . $newlength . ':"' . $newstring . '";';
        }, $data);
    }

    /**
     * Get the primary key column for a table.
     *
     * @param string $table Table name.
     * @return string|null Primary key column name or null.
     */
    protected function get_primary_key(string $table): ?string
    {
        $result = $this->db->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");

        if ($result && $row = $result->fetch_assoc()) {
            return $row['Column_name'];
        }

        return null;
    }

    /**
     * Replace URLs in specific tables and columns.
     *
     * @param string $oldurl Old URL.
     * @param string $newurl New URL.
     * @param array $targets Array of ['table' => 'column'] pairs.
     * @return int Total replacements.
     */
    public function replace_specific(string $oldurl, string $newurl, array $targets): int
    {
        $total = 0;

        foreach ($targets as $table => $columns) {
            $fulltable = $this->prefix . $table;

            // Check table exists
            $result = $this->db->query("SHOW TABLES LIKE '{$fulltable}'");
            if ($result->num_rows === 0) {
                continue;
            }

            $columns = is_array($columns) ? $columns : [$columns];

            foreach ($columns as $column) {
                $isserialized = isset($this->serializedcolumns[$table]) &&
                    in_array($column, $this->serializedcolumns[$table]);

                $count = $this->replace_in_column($fulltable, $column, $oldurl, $newurl, $isserialized);
                $total += $count;
            }
        }

        return $total;
    }

    /**
     * Replace wwwroot in config table.
     *
     * @param string $newurl New wwwroot URL.
     * @return bool Success.
     */
    public function update_wwwroot(string $newurl): bool
    {
        $newurl = rtrim($newurl, '/');
        $escaped = $this->db->real_escape_string($newurl);

        $query = "UPDATE `{$this->prefix}config` SET `value` = '{$escaped}' WHERE `name` = 'wwwroot'";
        return $this->db->query($query);
    }

    /**
     * Get the replacement log.
     *
     * @return array Replacement log entries.
     */
    public function get_replacement_log(): array
    {
        return $this->replacementlog;
    }

    /**
     * Get total replacements made.
     *
     * @return int Total count.
     */
    public function get_total_replacements(): int
    {
        return $this->totalreplacements;
    }

    /**
     * Preview replacements without making changes.
     *
     * @param string $oldurl Old URL.
     * @param string $newurl New URL.
     * @return array Preview results.
     */
    public function preview(string $oldurl, string $newurl): array
    {
        $oldurl = rtrim($oldurl, '/');
        $preview = [];

        $tables = $this->get_all_tables();

        foreach ($tables as $table) {
            $columns = $this->get_text_columns($table);

            if (empty($columns)) {
                continue;
            }

            foreach ($columns as $column) {
                $escapedold = $this->db->real_escape_string($oldurl);
                $checkquery = "SELECT COUNT(*) as cnt FROM `{$table}` WHERE `{$column}` LIKE '%{$escapedold}%'";
                $result = $this->db->query($checkquery);

                if ($result) {
                    $row = $result->fetch_assoc();
                    if ($row['cnt'] > 0) {
                        $preview[] = [
                            'table' => $table,
                            'column' => $column,
                            'count' => (int) $row['cnt'],
                        ];
                    }
                }
            }
        }

        return $preview;
    }

    /**
     * Add custom URL columns to check.
     *
     * @param string $table Table name (without prefix).
     * @param array $columns Column names.
     */
    public function add_url_columns(string $table, array $columns): void
    {
        if (!isset($this->urlcolumns[$table])) {
            $this->urlcolumns[$table] = [];
        }
        $this->urlcolumns[$table] = array_merge($this->urlcolumns[$table], $columns);
    }

    /**
     * Add custom serialized columns.
     *
     * @param string $table Table name (without prefix).
     * @param array $columns Column names.
     */
    public function add_serialized_columns(string $table, array $columns): void
    {
        if (!isset($this->serializedcolumns[$table])) {
            $this->serializedcolumns[$table] = [];
        }
        $this->serializedcolumns[$table] = array_merge($this->serializedcolumns[$table], $columns);
    }
}
