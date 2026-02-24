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
 * Full database exporter using mysqldump.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

/**
 * Exporter for complete database dump using mysqldump.
 *
 * This exporter creates a full MySQL/MariaDB dump of the Moodle database,
 * excluding session and cache tables by default. The dump is compressed
 * with gzip.
 *
 * WARNING: This export contains sensitive data including password hashes.
 * Handle the export file with extreme care and delete after migration.
 */
class database_exporter extends base_exporter {

    /** @var array Default tables to exclude from dump */
    protected const DEFAULT_EXCLUDE_TABLES = [
        'sessions',
        'cache_text',
        'cache_flags',
        'temp_log',
        'upgrade_log',
        'task_log',
        'log',
        'logstore_standard_log',
        'task_adhoc',
    ];

    /** @var array Tables to exclude (computed at runtime) */
    protected array $exclude_tables = [];

    /** @var string|null Path to mysqldump binary */
    protected ?string $mysqldump_path = null;

    /**
     * Get the exporter name.
     *
     * @return string Human-readable name.
     */
    public function get_name(): string {
        return get_string('exporter_database', 'local_edulution');
    }

    /**
     * Get the language string key.
     *
     * @return string Language string key.
     */
    public function get_string_key(): string {
        return 'database';
    }

    /**
     * Get total count for progress tracking.
     *
     * @return int Number of steps.
     */
    public function get_total_count(): int {
        // Steps: Find mysqldump, create dump, compress (if enabled), verify.
        return $this->options->compress_database ? 4 : 3;
    }

    /**
     * Execute the full database export.
     *
     * @return array Exported data metadata.
     * @throws \moodle_exception On export failure.
     */
    public function export(): array {
        global $CFG;

        $this->log('info', 'Starting full database export...');
        $this->log('warning', get_string('database_security_warning', 'local_edulution'));

        // Step 1: Find mysqldump.
        $this->update_progress(1, 'Locating mysqldump binary...');
        $this->mysqldump_path = $this->find_mysqldump();
        if (!$this->mysqldump_path) {
            throw new \moodle_exception('error_mysqldump_not_found', 'local_edulution');
        }
        $this->log('info', "Found mysqldump at: {$this->mysqldump_path}");

        // Build exclude tables list.
        $this->exclude_tables = $this->get_exclude_tables();

        // Step 2: Create database dump.
        $this->update_progress(2, 'Creating database dump...');
        $dumpResult = $this->create_database_dump();

        // Step 3: Compress if enabled.
        if ($this->options->compress_database && !empty($dumpResult['file'])) {
            $this->update_progress(3, 'Compressing database dump...');
            $dumpResult = $this->compress_dump($dumpResult);
        }

        // Final step: Verify and create metadata.
        $this->update_progress($this->get_total_count(), 'Verifying export...');

        // Build result data.
        $data = [
            'export_type' => 'full_database',
            'export_timestamp' => date('c'),
            'security_notice' => get_string('database_security_warning', 'local_edulution'),
            'database' => $dumpResult,
            'excluded_tables' => $this->exclude_tables,
            'source' => [
                'dbtype' => $CFG->dbtype,
                'dbname' => $CFG->dbname,
                'dbhost' => $CFG->dbhost,
                'prefix' => $CFG->prefix,
            ],
        ];

        // Write metadata file.
        $this->write_json($data, 'database/metadata.json');

        // Update statistics.
        $this->stats = [
            'database_size' => $dumpResult['size_bytes'] ?? 0,
            'database_size_formatted' => $dumpResult['size_formatted'] ?? '0 B',
            'tables_exported' => $dumpResult['tables_count'] ?? 0,
            'tables_excluded' => count($this->exclude_tables),
            'compression' => $dumpResult['compression'] ?? 'none',
            'checksum' => $dumpResult['checksum'] ?? null,
        ];

        $this->log('info', sprintf(
            'Database export complete: %s (%d tables)',
            $this->stats['database_size_formatted'],
            $this->stats['tables_exported']
        ));

        return $data;
    }

    /**
     * Get tables to exclude from dump.
     *
     * @return array List of table names to exclude (with prefix).
     */
    protected function get_exclude_tables(): array {
        global $CFG;

        $exclude = self::DEFAULT_EXCLUDE_TABLES;

        // Add user-configured exclusions.
        if (!empty($this->options->exclude_tables)) {
            $userExclude = array_map('trim', explode(',', $this->options->exclude_tables));
            $exclude = array_merge($exclude, $userExclude);
        }

        // Normalize table names with prefix.
        $prefix = $CFG->prefix;
        $normalized = [];

        foreach ($exclude as $table) {
            $table = trim($table);
            if (empty($table)) {
                continue;
            }

            // Remove prefix if present, then re-add it.
            if (strpos($table, $prefix) === 0) {
                $table = substr($table, strlen($prefix));
            }

            $normalized[] = $prefix . $table;
        }

        return array_unique($normalized);
    }

    /**
     * Create the MySQL database dump.
     *
     * @return array Result metadata.
     * @throws \moodle_exception If dump fails.
     */
    protected function create_database_dump(): array {
        global $CFG;

        // Ensure database subdirectory exists.
        $dbDir = $this->get_subdir('database');
        $dumpFile = $dbDir . '/database.sql';

        // Get database credentials.
        $dbhost = $CFG->dbhost;
        $dbname = $CFG->dbname;
        $dbuser = $CFG->dbuser;
        $dbpass = $CFG->dbpass;
        $dbport = !empty($CFG->dboptions['dbport']) ? (int) $CFG->dboptions['dbport'] : 3306;

        // Create temporary credentials file (avoid password in process list).
        $tempCnfFile = tempnam(sys_get_temp_dir(), 'mysqldump_');
        $cnfContent = "[client]\n";
        $cnfContent .= "user=" . $dbuser . "\n";
        $cnfContent .= "password=" . str_replace(['\\', '"'], ['\\\\', '\\"'], $dbpass) . "\n";
        $cnfContent .= "host=" . $dbhost . "\n";
        $cnfContent .= "port=" . $dbport . "\n";

        file_put_contents($tempCnfFile, $cnfContent);
        chmod($tempCnfFile, 0600);

        try {
            // Build mysqldump command.
            $excludeArgs = '';
            foreach ($this->exclude_tables as $table) {
                $excludeArgs .= ' --ignore-table=' . escapeshellarg($dbname . '.' . $table);
            }

            // mysqldump options for reliable exports.
            $options = [
                '--defaults-extra-file=' . escapeshellarg($tempCnfFile),
                '--single-transaction',  // Consistent snapshot for InnoDB.
                '--quick',               // Don't buffer entire tables in memory.
                '--lock-tables=false',   // Don't lock tables.
                '--routines',            // Include stored procedures.
                '--triggers',            // Include triggers.
                '--events',              // Include events.
                '--add-drop-table',      // Add DROP TABLE statements.
                '--create-options',      // Include all CREATE TABLE options.
                '--extended-insert',     // Use multi-value INSERT statements.
                '--set-charset',         // Add SET NAMES statement.
                '--disable-keys',        // Disable keys during insert.
                '--hex-blob',            // Dump binary columns as hex.
            ];

            $command = sprintf(
                '%s %s %s %s > %s 2>&1',
                escapeshellcmd($this->mysqldump_path),
                implode(' ', $options),
                $excludeArgs,
                escapeshellarg($dbname),
                escapeshellarg($dumpFile)
            );

            $this->log('debug', 'Executing mysqldump...');

            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            // Clean up temp credentials file immediately.
            unlink($tempCnfFile);

            if ($returnCode !== 0) {
                $errorMsg = implode("\n", $output);
                throw new \moodle_exception('error_mysqldump_failed', 'local_edulution', '', $errorMsg);
            }

            if (!file_exists($dumpFile) || filesize($dumpFile) === 0) {
                throw new \moodle_exception('error_mysqldump_empty', 'local_edulution');
            }

            // Count tables in dump.
            $tablesCount = $this->count_tables_in_dump($dumpFile);
            $size = filesize($dumpFile);

            $this->log('info', sprintf(
                'Database dump created: %s (%d tables)',
                $this->format_size($size),
                $tablesCount
            ));

            return [
                'file' => 'database/database.sql',
                'full_path' => $dumpFile,
                'size_bytes' => $size,
                'size_formatted' => $this->format_size($size),
                'tables_count' => $tablesCount,
                'compression' => 'none',
                'checksum' => $this->calculate_checksum($dumpFile),
            ];

        } catch (\Exception $e) {
            // Clean up temp file on error.
            if (file_exists($tempCnfFile)) {
                unlink($tempCnfFile);
            }
            throw $e;
        }
    }

    /**
     * Compress the database dump with gzip.
     *
     * @param array $dumpResult Original dump result.
     * @return array Updated result with compressed file info.
     * @throws \moodle_exception If compression fails.
     */
    protected function compress_dump(array $dumpResult): array {
        $sourcePath = $dumpResult['full_path'];
        $gzipPath = $sourcePath . '.gz';

        $this->log('debug', 'Compressing database dump with gzip...');

        // Open source file.
        $sourceHandle = fopen($sourcePath, 'rb');
        if (!$sourceHandle) {
            throw new \moodle_exception('error_file_read', 'local_edulution', '', $sourcePath);
        }

        // Open gzip file with maximum compression.
        $gzHandle = gzopen($gzipPath, 'wb9');
        if (!$gzHandle) {
            fclose($sourceHandle);
            throw new \moodle_exception('error_file_write', 'local_edulution', '', $gzipPath);
        }

        // Stream compress in chunks (512KB).
        while (!feof($sourceHandle)) {
            $buffer = fread($sourceHandle, 1024 * 512);
            gzwrite($gzHandle, $buffer);
        }

        fclose($sourceHandle);
        gzclose($gzHandle);

        // Remove uncompressed dump.
        unlink($sourcePath);

        $size = filesize($gzipPath);
        $originalSize = $dumpResult['size_bytes'];
        $compressionRatio = $originalSize > 0 ? round((1 - ($size / $originalSize)) * 100, 1) : 0;

        $this->log('info', sprintf(
            'Compression complete: %s -> %s (%.1f%% reduction)',
            $this->format_size($originalSize),
            $this->format_size($size),
            $compressionRatio
        ));

        return [
            'file' => 'database/database.sql.gz',
            'full_path' => $gzipPath,
            'size_bytes' => $size,
            'size_formatted' => $this->format_size($size),
            'original_size_bytes' => $originalSize,
            'original_size_formatted' => $dumpResult['size_formatted'],
            'compression_ratio' => $compressionRatio . '%',
            'tables_count' => $dumpResult['tables_count'],
            'compression' => 'gzip',
            'checksum' => $this->calculate_checksum($gzipPath),
        ];
    }

    /**
     * Find mysqldump binary.
     *
     * @return string|null Path to mysqldump, or null if not found.
     */
    protected function find_mysqldump(): ?string {
        // Common locations for mysqldump/mariadb-dump.
        $paths = [
            // Linux standard paths.
            '/usr/bin/mysqldump',
            '/usr/bin/mariadb-dump',
            '/usr/local/bin/mysqldump',
            '/usr/local/bin/mariadb-dump',
            // MySQL-specific paths.
            '/usr/local/mysql/bin/mysqldump',
            '/opt/mysql/bin/mysqldump',
            // MariaDB-specific paths.
            '/usr/local/mariadb/bin/mariadb-dump',
            '/opt/mariadb/bin/mariadb-dump',
            // macOS Homebrew paths.
            '/opt/homebrew/bin/mysqldump',
            '/opt/homebrew/bin/mariadb-dump',
            '/opt/homebrew/opt/mysql/bin/mysqldump',
            '/opt/homebrew/opt/mariadb/bin/mariadb-dump',
            // macOS MacPorts paths.
            '/opt/local/bin/mysqldump',
            '/opt/local/bin/mariadb-dump',
            // Windows paths (common).
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
            'C:\\Program Files\\MariaDB 10.6\\bin\\mariadb-dump.exe',
        ];

        // Check each path.
        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try to find via 'which' command (Unix-like systems).
        foreach (['mysqldump', 'mariadb-dump'] as $binary) {
            $output = [];
            $returnCode = 0;
            exec("which {$binary} 2>/dev/null", $output, $returnCode);
            if ($returnCode === 0 && !empty($output[0]) && is_executable($output[0])) {
                return $output[0];
            }
        }

        // Try 'where' command (Windows).
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['mysqldump', 'mariadb-dump'] as $binary) {
                $output = [];
                $returnCode = 0;
                exec("where {$binary} 2>nul", $output, $returnCode);
                if ($returnCode === 0 && !empty($output[0]) && is_executable($output[0])) {
                    return $output[0];
                }
            }
        }

        return null;
    }

    /**
     * Count tables in a SQL dump file.
     *
     * @param string $dumpFile Path to dump file.
     * @return int Number of CREATE TABLE statements.
     */
    protected function count_tables_in_dump(string $dumpFile): int {
        $count = 0;
        $handle = fopen($dumpFile, 'r');

        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (stripos($line, 'CREATE TABLE') !== false) {
                    $count++;
                }
            }
            fclose($handle);
        }

        return $count;
    }

    /**
     * Get database statistics.
     *
     * @return array Database statistics.
     */
    public function get_database_stats(): array {
        global $DB, $CFG;

        $stats = [
            'type' => $CFG->dbtype,
            'name' => $CFG->dbname,
            'tables' => 0,
            'total_rows' => 0,
            'estimated_size' => 0,
        ];

        try {
            // Get table count and basic stats.
            if ($CFG->dbtype === 'mysqli' || $CFG->dbtype === 'mariadb') {
                $sql = "SELECT COUNT(*) as tables,
                               SUM(TABLE_ROWS) as total_rows,
                               SUM(DATA_LENGTH + INDEX_LENGTH) as total_size
                        FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = ?";
                $result = $DB->get_record_sql($sql, [$CFG->dbname]);

                if ($result) {
                    $stats['tables'] = (int) $result->tables;
                    $stats['total_rows'] = (int) $result->total_rows;
                    $stats['estimated_size'] = (int) $result->total_size;
                    $stats['estimated_size_formatted'] = $this->format_size($stats['estimated_size']);
                }
            }
        } catch (\Exception $e) {
            $this->log('warning', 'Could not retrieve database statistics: ' . $e->getMessage());
        }

        return $stats;
    }
}
