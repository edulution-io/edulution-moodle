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
 * Full importer - orchestrates complete Moodle site import from Edulution export.
 *
 * This class can run BEFORE Moodle is fully installed, using minimal PHP
 * and direct MySQL connections where needed.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\import;

/**
 * Full importer class that handles complete site import.
 *
 * Phases:
 * 1. Preparation - Extract ZIP, validate manifest, check disk space
 * 2. Database Import - Drop tables, import SQL, URL replacement
 * 3. Moodledata - Copy all files from backup
 * 4. Plugin Installation - Download and install missing plugins
 * 5. Finalization - Config generation, upgrade, cache purge, health check
 */
class full_importer {

    /** @var string Path to the export ZIP file */
    protected string $zipfile;

    /** @var string Target wwwroot URL */
    protected string $wwwroot;

    /** @var string Target dataroot path */
    protected string $dataroot;

    /** @var string Database host */
    protected string $dbhost;

    /** @var string Database name */
    protected string $dbname;

    /** @var string Database user */
    protected string $dbuser;

    /** @var string Database password */
    protected string $dbpass;

    /** @var int Database port */
    protected int $dbport = 3306;

    /** @var string Database prefix */
    protected string $dbprefix = 'mdl_';

    /** @var string|null New admin password */
    protected ?string $adminpass = null;

    /** @var bool Skip license agreement */
    protected bool $agreelicense = false;

    /** @var bool Skip plugin installation */
    protected bool $skipplugins = false;

    /** @var bool Dry run mode */
    protected bool $dryrun = false;

    /** @var string Temporary extraction directory */
    protected string $tempdir;

    /** @var array Manifest data from export */
    protected array $manifest = [];

    /** @var callable|null Progress callback */
    protected $progresscallback = null;

    /** @var array Rollback log for reverting changes */
    protected array $rollbacklog = [];

    /** @var mysqli|null Database connection for pre-Moodle operations */
    protected ?\mysqli $db = null;

    /** @var string Moodle dirroot path */
    protected string $dirroot;

    /** @var string Original wwwroot from export */
    protected string $oldwwwroot = '';

    /**
     * Constructor.
     *
     * @param array $options Import options.
     */
    public function __construct(array $options) {
        $this->zipfile = $options['file'] ?? '';
        $this->wwwroot = rtrim($options['wwwroot'] ?? '', '/');
        $this->dataroot = rtrim($options['dataroot'] ?? '/var/moodledata', '/');
        $this->dbhost = $options['dbhost'] ?? getenv('MOODLE_DOCKER_DBHOST') ?: 'localhost';
        $this->dbname = $options['dbname'] ?? getenv('MOODLE_DOCKER_DBNAME') ?: 'moodle';
        $this->dbuser = $options['dbuser'] ?? getenv('MOODLE_DOCKER_DBUSER') ?: 'moodle';
        $this->dbpass = $options['dbpass'] ?? getenv('MOODLE_DOCKER_DBPASS') ?: '';
        $this->dbport = (int)($options['dbport'] ?? getenv('MOODLE_DOCKER_DBPORT') ?: 3306);
        $this->dbprefix = $options['dbprefix'] ?? 'mdl_';
        $this->adminpass = $options['adminpass'] ?? null;
        $this->agreelicense = !empty($options['agree-license']);
        $this->skipplugins = !empty($options['skip-plugins']);
        $this->dryrun = !empty($options['dry-run']);
        $this->dirroot = $options['dirroot'] ?? dirname(dirname(dirname(dirname(__DIR__))));
    }

    /**
     * Set progress callback function.
     *
     * @param callable $callback Function that receives (phase, step, message).
     */
    public function set_progress_callback(callable $callback): void {
        $this->progresscallback = $callback;
    }

    /**
     * Report progress.
     *
     * @param string $phase Current phase.
     * @param int $step Step number.
     * @param string $message Progress message.
     */
    protected function progress(string $phase, int $step, string $message): void {
        if ($this->progresscallback) {
            call_user_func($this->progresscallback, $phase, $step, $message);
        }
    }

    /**
     * Execute the full import process.
     *
     * @return array Import results.
     * @throws \Exception On failure.
     */
    public function execute(): array {
        $starttime = time();
        $results = [
            'success' => false,
            'phases' => [],
            'errors' => [],
            'warnings' => [],
        ];

        try {
            // Phase 1: Preparation
            $results['phases']['preparation'] = $this->phase_preparation();

            // Phase 2: Database Import
            $results['phases']['database'] = $this->phase_database_import();

            // Phase 3: Moodledata
            $results['phases']['moodledata'] = $this->phase_moodledata();

            // Phase 4: Plugin Installation
            if (!$this->skipplugins) {
                $results['phases']['plugins'] = $this->phase_plugin_installation();
            }

            // Phase 5: Finalization
            $results['phases']['finalization'] = $this->phase_finalization();

            $results['success'] = true;
            $results['duration_seconds'] = time() - $starttime;

        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
            $results['success'] = false;

            // Attempt rollback if not in dry run mode
            if (!$this->dryrun) {
                $this->rollback();
            }

            throw $e;
        } finally {
            // Cleanup
            $this->cleanup();
        }

        return $results;
    }

    /**
     * Phase 1: Preparation
     * - Extract ZIP to temp directory
     * - Validate manifest.json
     * - Check disk space
     * - Test database connection
     *
     * @return array Phase results.
     * @throws \Exception On failure.
     */
    protected function phase_preparation(): array {
        $this->progress('preparation', 1, 'Starting preparation phase...');

        $result = [
            'status' => 'started',
            'steps' => [],
        ];

        // Step 1: Validate input file exists
        $this->progress('preparation', 1, 'Validating export file...');
        if (!file_exists($this->zipfile)) {
            throw new \Exception("Export file not found: {$this->zipfile}");
        }
        $result['steps'][] = 'Export file validated';

        // Step 2: Create temp directory
        $this->progress('preparation', 2, 'Creating temporary directory...');
        $this->tempdir = sys_get_temp_dir() . '/edulution_import_' . uniqid();
        if (!mkdir($this->tempdir, 0755, true)) {
            throw new \Exception("Failed to create temporary directory: {$this->tempdir}");
        }
        $this->rollbacklog['tempdir'] = $this->tempdir;
        $result['steps'][] = 'Temporary directory created';

        // Step 3: Check disk space
        $this->progress('preparation', 3, 'Checking disk space...');
        $zipsize = filesize($this->zipfile);
        $requiredspace = $zipsize * 3; // Estimate: ZIP + extracted + safety margin
        $freespace = disk_free_space($this->tempdir);
        if ($freespace < $requiredspace) {
            throw new \Exception(sprintf(
                "Insufficient disk space. Required: %s, Available: %s",
                $this->format_size($requiredspace),
                $this->format_size($freespace)
            ));
        }
        $result['steps'][] = 'Disk space verified';

        // Step 4: Extract ZIP
        $this->progress('preparation', 4, 'Extracting export file...');
        if (!$this->dryrun) {
            $zip = new \ZipArchive();
            if ($zip->open($this->zipfile) !== true) {
                throw new \Exception("Failed to open ZIP file: {$this->zipfile}");
            }
            if (!$zip->extractTo($this->tempdir)) {
                $zip->close();
                throw new \Exception("Failed to extract ZIP file");
            }
            $zip->close();
        }
        $result['steps'][] = 'Export file extracted';

        // Step 5: Validate manifest
        $this->progress('preparation', 5, 'Validating manifest...');
        $manifestpath = $this->tempdir . '/manifest.json';
        if (!file_exists($manifestpath) && !$this->dryrun) {
            throw new \Exception("manifest.json not found in export");
        }

        if (!$this->dryrun) {
            $manifestcontent = file_get_contents($manifestpath);
            $this->manifest = json_decode($manifestcontent, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Invalid manifest.json: " . json_last_error_msg());
            }

            // Extract old wwwroot for URL replacement
            $this->oldwwwroot = $this->manifest['source_moodle']['wwwroot'] ?? '';
        }
        $result['steps'][] = 'Manifest validated';

        // Step 6: Test database connection
        $this->progress('preparation', 6, 'Testing database connection...');
        if (!$this->dryrun) {
            $this->test_database_connection();
        }
        $result['steps'][] = 'Database connection verified';

        // Step 7: Verify dataroot is writable
        $this->progress('preparation', 7, 'Verifying dataroot...');
        if (!$this->dryrun) {
            if (!is_dir($this->dataroot)) {
                if (!mkdir($this->dataroot, 0755, true)) {
                    throw new \Exception("Cannot create dataroot: {$this->dataroot}");
                }
            }
            if (!is_writable($this->dataroot)) {
                throw new \Exception("Dataroot is not writable: {$this->dataroot}");
            }
        }
        $result['steps'][] = 'Dataroot verified';

        $result['status'] = 'completed';
        $result['manifest'] = $this->manifest;

        return $result;
    }

    /**
     * Phase 2: Database Import
     * - Drop existing tables (with confirmation)
     * - Import SQL dump
     * - URL replacement
     * - Optional: Reset admin password
     *
     * @return array Phase results.
     * @throws \Exception On failure.
     */
    protected function phase_database_import(): array {
        $this->progress('database', 1, 'Starting database import phase...');

        $result = [
            'status' => 'started',
            'steps' => [],
            'tables_imported' => 0,
        ];

        if ($this->dryrun) {
            $result['status'] = 'skipped (dry run)';
            return $result;
        }

        // Connect to database
        $this->connect_database();

        // Step 1: Drop existing tables
        $this->progress('database', 1, 'Dropping existing tables...');
        $droppedtables = $this->drop_all_tables();
        $result['steps'][] = "Dropped {$droppedtables} existing tables";
        $this->rollbacklog['database_dropped'] = true;

        // Step 2: Import SQL dump
        $this->progress('database', 2, 'Importing database dump...');
        $dumpfile = $this->tempdir . '/database.sql.gz';
        if (!file_exists($dumpfile)) {
            $dumpfile = $this->tempdir . '/database.sql';
        }

        if (!file_exists($dumpfile)) {
            throw new \Exception("Database dump not found in export");
        }

        $tablesimported = $this->import_sql_dump($dumpfile);
        $result['tables_imported'] = $tablesimported;
        $result['steps'][] = "Imported {$tablesimported} tables";

        // Step 3: URL replacement
        if (!empty($this->oldwwwroot) && $this->oldwwwroot !== $this->wwwroot) {
            $this->progress('database', 3, 'Replacing URLs...');
            $replacer = new url_replacer($this->db, $this->dbprefix);
            $replacements = $replacer->replace_all($this->oldwwwroot, $this->wwwroot);
            $result['steps'][] = "Replaced URLs in {$replacements} fields";
            $result['url_replacements'] = $replacements;
        }

        // Step 4: Reset admin password if specified
        if (!empty($this->adminpass)) {
            $this->progress('database', 4, 'Resetting admin password...');
            $this->reset_admin_password();
            $result['steps'][] = 'Admin password reset';
        }

        // Step 5: Update site configuration
        $this->progress('database', 5, 'Updating site configuration...');
        $this->update_site_config();
        $result['steps'][] = 'Site configuration updated';

        $this->disconnect_database();

        $result['status'] = 'completed';
        return $result;
    }

    /**
     * Phase 3: Moodledata
     * - Clear target dataroot (preserve .htaccess)
     * - Copy all files from backup
     * - Set permissions
     *
     * @return array Phase results.
     * @throws \Exception On failure.
     */
    protected function phase_moodledata(): array {
        $this->progress('moodledata', 1, 'Starting moodledata phase...');

        $result = [
            'status' => 'started',
            'steps' => [],
            'files_copied' => 0,
            'size_copied' => 0,
        ];

        if ($this->dryrun) {
            $result['status'] = 'skipped (dry run)';
            return $result;
        }

        $sourcemoodledata = $this->tempdir . '/moodledata';
        if (!is_dir($sourcemoodledata)) {
            $this->progress('moodledata', 1, 'No moodledata directory in export, skipping...');
            $result['status'] = 'skipped (no data)';
            return $result;
        }

        // Step 1: Backup .htaccess if exists
        $htaccesspath = $this->dataroot . '/.htaccess';
        $htaccessbackup = null;
        if (file_exists($htaccesspath)) {
            $htaccessbackup = file_get_contents($htaccesspath);
        }
        $result['steps'][] = '.htaccess preserved';

        // Step 2: Clear target dataroot (but preserve directory)
        $this->progress('moodledata', 2, 'Clearing target dataroot...');
        $this->clear_directory($this->dataroot);
        $result['steps'][] = 'Target dataroot cleared';

        // Step 3: Copy files from backup
        $this->progress('moodledata', 3, 'Copying moodledata files...');
        $copyresult = $this->copy_directory_recursive($sourcemoodledata, $this->dataroot);
        $result['files_copied'] = $copyresult['files'];
        $result['size_copied'] = $copyresult['size'];
        $result['steps'][] = "Copied {$copyresult['files']} files ({$this->format_size($copyresult['size'])})";

        // Step 4: Restore .htaccess
        if ($htaccessbackup !== null) {
            file_put_contents($htaccesspath, $htaccessbackup);
        } else {
            // Create default .htaccess
            $defaulthtaccess = "order deny,allow\ndeny from all\n";
            file_put_contents($htaccesspath, $defaulthtaccess);
        }
        $result['steps'][] = '.htaccess restored';

        // Step 5: Set permissions
        $this->progress('moodledata', 4, 'Setting permissions...');
        $this->set_directory_permissions($this->dataroot);
        $result['steps'][] = 'Permissions set';

        $result['status'] = 'completed';
        return $result;
    }

    /**
     * Phase 4: Plugin Installation
     * - Compare installed.json with current plugins
     * - Download missing plugins from sources.json URLs
     * - Extract to correct directories
     *
     * @return array Phase results.
     * @throws \Exception On failure.
     */
    protected function phase_plugin_installation(): array {
        $this->progress('plugins', 1, 'Starting plugin installation phase...');

        $result = [
            'status' => 'started',
            'steps' => [],
            'plugins_installed' => 0,
            'plugins_skipped' => 0,
        ];

        if ($this->dryrun) {
            $result['status'] = 'skipped (dry run)';
            return $result;
        }

        // Load plugins.json from export
        $pluginsjson = $this->tempdir . '/plugins.json';
        if (!file_exists($pluginsjson)) {
            $this->progress('plugins', 1, 'No plugins.json in export, skipping...');
            $result['status'] = 'skipped (no plugin data)';
            return $result;
        }

        $pluginsdata = json_decode(file_get_contents($pluginsjson), true);
        if (!isset($pluginsdata['plugins'])) {
            $result['status'] = 'skipped (invalid plugin data)';
            return $result;
        }

        // Filter to non-core plugins only
        $additionalPlugins = array_filter($pluginsdata['plugins'], function($p) {
            return !($p['is_core'] ?? true);
        });

        if (empty($additionalPlugins)) {
            $result['status'] = 'completed (no additional plugins)';
            return $result;
        }

        $this->progress('plugins', 2, 'Installing ' . count($additionalPlugins) . ' additional plugins...');

        // Use plugin installer
        $installer = new plugin_installer($this->dirroot);
        $installer->set_progress_callback(function($step, $message) {
            $this->progress('plugins', $step, $message);
        });

        foreach ($additionalPlugins as $plugin) {
            try {
                $installed = $installer->install_plugin($plugin);
                if ($installed) {
                    $result['plugins_installed']++;
                } else {
                    $result['plugins_skipped']++;
                }
            } catch (\Exception $e) {
                $result['warnings'][] = "Failed to install {$plugin['component']}: " . $e->getMessage();
                $result['plugins_skipped']++;
            }
        }

        $result['steps'][] = "Installed {$result['plugins_installed']} plugins, skipped {$result['plugins_skipped']}";
        $result['status'] = 'completed';

        return $result;
    }

    /**
     * Phase 5: Finalization
     * - Generate config.php from template
     * - Run upgrade.php
     * - Purge caches
     * - Run cron once
     * - Health check
     *
     * @return array Phase results.
     * @throws \Exception On failure.
     */
    protected function phase_finalization(): array {
        $this->progress('finalization', 1, 'Starting finalization phase...');

        $result = [
            'status' => 'started',
            'steps' => [],
        ];

        if ($this->dryrun) {
            $result['status'] = 'skipped (dry run)';
            return $result;
        }

        // Step 1: Generate config.php
        $this->progress('finalization', 1, 'Generating config.php...');
        $this->generate_config_php();
        $result['steps'][] = 'config.php generated';

        // Step 2: Run upgrade.php
        $this->progress('finalization', 2, 'Running Moodle upgrade...');
        $upgraderesult = $this->run_cli_script('admin/cli/upgrade.php', ['--non-interactive']);
        $result['steps'][] = 'Upgrade completed';
        $result['upgrade_output'] = $upgraderesult;

        // Step 3: Purge caches
        $this->progress('finalization', 3, 'Purging caches...');
        $this->run_cli_script('admin/cli/purge_caches.php');
        $result['steps'][] = 'Caches purged';

        // Step 4: Run cron once
        $this->progress('finalization', 4, 'Running cron...');
        $this->run_cli_script('admin/cli/cron.php', [], 60); // 60 second timeout
        $result['steps'][] = 'Cron executed';

        // Step 5: Health check
        $this->progress('finalization', 5, 'Running health check...');
        $healthcheck = $this->perform_health_check();
        $result['health_check'] = $healthcheck;
        $result['steps'][] = 'Health check completed';

        $result['status'] = 'completed';
        return $result;
    }

    /**
     * Test database connection.
     *
     * @throws \Exception If connection fails.
     */
    protected function test_database_connection(): void {
        $conn = @new \mysqli($this->dbhost, $this->dbuser, $this->dbpass, $this->dbname, $this->dbport);
        if ($conn->connect_error) {
            throw new \Exception("Database connection failed: " . $conn->connect_error);
        }
        $conn->close();
    }

    /**
     * Connect to database.
     *
     * @throws \Exception If connection fails.
     */
    protected function connect_database(): void {
        $this->db = new \mysqli($this->dbhost, $this->dbuser, $this->dbpass, $this->dbname, $this->dbport);
        if ($this->db->connect_error) {
            throw new \Exception("Database connection failed: " . $this->db->connect_error);
        }
        $this->db->set_charset('utf8mb4');
    }

    /**
     * Disconnect from database.
     */
    protected function disconnect_database(): void {
        if ($this->db) {
            $this->db->close();
            $this->db = null;
        }
    }

    /**
     * Drop all tables with the configured prefix.
     *
     * @return int Number of tables dropped.
     */
    protected function drop_all_tables(): int {
        $count = 0;

        // Disable foreign key checks
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");

        // Get all tables with our prefix
        $result = $this->db->query("SHOW TABLES LIKE '{$this->dbprefix}%'");
        while ($row = $result->fetch_array()) {
            $table = $row[0];
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
            $count++;
        }

        // Re-enable foreign key checks
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        return $count;
    }

    /**
     * Import SQL dump file.
     *
     * @param string $dumpfile Path to SQL dump (can be .gz compressed).
     * @return int Number of tables created.
     * @throws \Exception On import failure.
     */
    protected function import_sql_dump(string $dumpfile): int {
        $isgzipped = substr($dumpfile, -3) === '.gz';

        // Use mysql command for import (more reliable for large files)
        $mysqlcmd = $this->find_mysql_command();
        if (!$mysqlcmd) {
            // Fall back to PHP-based import
            return $this->import_sql_dump_php($dumpfile);
        }

        // Create temp credentials file
        $cnffile = tempnam(sys_get_temp_dir(), 'mysql');
        $cnfcontent = "[client]\n";
        $cnfcontent .= "host={$this->dbhost}\n";
        $cnfcontent .= "port={$this->dbport}\n";
        $cnfcontent .= "user={$this->dbuser}\n";
        $cnfcontent .= "password=" . str_replace('"', '\\"', $this->dbpass) . "\n";
        file_put_contents($cnffile, $cnfcontent);
        chmod($cnffile, 0600);

        try {
            if ($isgzipped) {
                $command = "gunzip -c " . escapeshellarg($dumpfile) .
                    " | {$mysqlcmd} --defaults-extra-file=" . escapeshellarg($cnffile) .
                    " " . escapeshellarg($this->dbname) . " 2>&1";
            } else {
                $command = "{$mysqlcmd} --defaults-extra-file=" . escapeshellarg($cnffile) .
                    " " . escapeshellarg($this->dbname) .
                    " < " . escapeshellarg($dumpfile) . " 2>&1";
            }

            $output = [];
            $returncode = 0;
            exec($command, $output, $returncode);

            unlink($cnffile);

            if ($returncode !== 0) {
                throw new \Exception("SQL import failed: " . implode("\n", $output));
            }

            // Count tables after import
            $result = $this->db->query("SHOW TABLES LIKE '{$this->dbprefix}%'");
            return $result->num_rows;

        } catch (\Exception $e) {
            if (file_exists($cnffile)) {
                unlink($cnffile);
            }
            throw $e;
        }
    }

    /**
     * Import SQL dump using PHP (fallback method).
     *
     * @param string $dumpfile Path to SQL dump.
     * @return int Number of tables created.
     */
    protected function import_sql_dump_php(string $dumpfile): int {
        $isgzipped = substr($dumpfile, -3) === '.gz';

        if ($isgzipped) {
            $handle = gzopen($dumpfile, 'r');
        } else {
            $handle = fopen($dumpfile, 'r');
        }

        if (!$handle) {
            throw new \Exception("Cannot open dump file: {$dumpfile}");
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

        $query = '';
        $tablecount = 0;

        while (!($isgzipped ? gzeof($handle) : feof($handle))) {
            $line = $isgzipped ? gzgets($handle) : fgets($handle);

            // Skip comments and empty lines
            $trimmedline = trim($line);
            if (empty($trimmedline) || strpos($trimmedline, '--') === 0 || strpos($trimmedline, '/*') === 0) {
                continue;
            }

            $query .= $line;

            // Check if this is the end of a statement
            if (preg_match('/;\s*$/', $trimmedline)) {
                if (!$this->db->query($query)) {
                    // Log error but continue
                    error_log("SQL Error: " . $this->db->error . " in query: " . substr($query, 0, 200));
                }

                if (stripos($query, 'CREATE TABLE') !== false) {
                    $tablecount++;
                }

                $query = '';
            }
        }

        if ($isgzipped) {
            gzclose($handle);
        } else {
            fclose($handle);
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        return $tablecount;
    }

    /**
     * Find mysql command path.
     *
     * @return string|null Path to mysql command.
     */
    protected function find_mysql_command(): ?string {
        $paths = [
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
            '/usr/local/mysql/bin/mysql',
            '/opt/local/bin/mysql',
            '/opt/homebrew/bin/mysql',
            '/usr/bin/mariadb',
            '/usr/local/bin/mariadb',
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try which command
        $output = [];
        exec('which mysql 2>/dev/null', $output);
        if (!empty($output[0]) && is_executable($output[0])) {
            return $output[0];
        }

        return null;
    }

    /**
     * Reset admin user password.
     */
    protected function reset_admin_password(): void {
        // Generate password hash using Moodle's format
        $hash = password_hash($this->adminpass, PASSWORD_BCRYPT);

        $stmt = $this->db->prepare(
            "UPDATE {$this->dbprefix}user SET password = ? WHERE username = 'admin'"
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update site configuration in database.
     */
    protected function update_site_config(): void {
        // Update wwwroot
        $stmt = $this->db->prepare(
            "UPDATE {$this->dbprefix}config SET value = ? WHERE name = 'wwwroot'"
        );
        $stmt->bind_param('s', $this->wwwroot);
        $stmt->execute();
        $stmt->close();

        // Update dataroot
        $stmt = $this->db->prepare(
            "UPDATE {$this->dbprefix}config SET value = ? WHERE name = 'dataroot'"
        );
        $stmt->bind_param('s', $this->dataroot);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Clear a directory but preserve the directory itself.
     *
     * @param string $dir Directory path.
     */
    protected function clear_directory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }

    /**
     * Recursively copy a directory.
     *
     * @param string $source Source directory.
     * @param string $dest Destination directory.
     * @return array Copy statistics.
     */
    protected function copy_directory_recursive(string $source, string $dest): array {
        $stats = ['files' => 0, 'size' => 0];

        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destpath = $dest . '/' . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($destpath)) {
                    mkdir($destpath, 0755, true);
                }
            } else {
                if (copy($item->getPathname(), $destpath)) {
                    $stats['files']++;
                    $stats['size'] += $item->getSize();
                }
            }
        }

        return $stats;
    }

    /**
     * Set directory permissions (recursively).
     *
     * @param string $dir Directory path.
     */
    protected function set_directory_permissions(string $dir): void {
        // Try to set www-data ownership if running as root
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            exec("chown -R www-data:www-data " . escapeshellarg($dir));
        }

        // Set directory permissions to 755 and file permissions to 644
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @chmod($item->getPathname(), 0755);
            } else {
                @chmod($item->getPathname(), 0644);
            }
        }
    }

    /**
     * Generate config.php file.
     */
    protected function generate_config_php(): void {
        $configpath = $this->dirroot . '/config.php';

        $config = "<?php  // Moodle configuration file\n\n";
        $config .= "unset(\$CFG);\n";
        $config .= "global \$CFG;\n";
        $config .= "\$CFG = new stdClass();\n\n";

        $config .= "\$CFG->dbtype    = 'mariadb';\n";
        $config .= "\$CFG->dblibrary = 'native';\n";
        $config .= "\$CFG->dbhost    = " . var_export($this->dbhost, true) . ";\n";
        $config .= "\$CFG->dbname    = " . var_export($this->dbname, true) . ";\n";
        $config .= "\$CFG->dbuser    = " . var_export($this->dbuser, true) . ";\n";
        $config .= "\$CFG->dbpass    = " . var_export($this->dbpass, true) . ";\n";
        $config .= "\$CFG->prefix    = " . var_export($this->dbprefix, true) . ";\n";
        $config .= "\$CFG->dboptions = array(\n";
        $config .= "    'dbport' => {$this->dbport},\n";
        $config .= "    'dbcollation' => 'utf8mb4_unicode_ci',\n";
        $config .= ");\n\n";

        $config .= "\$CFG->wwwroot   = " . var_export($this->wwwroot, true) . ";\n";
        $config .= "\$CFG->dataroot  = " . var_export($this->dataroot, true) . ";\n";
        $config .= "\$CFG->admin     = 'admin';\n\n";

        $config .= "\$CFG->directorypermissions = 0755;\n\n";

        $config .= "require_once(__DIR__ . '/lib/setup.php');\n\n";

        $config .= "// There is no php closing tag in this file,\n";
        $config .= "// it is intentional because it prevents trailing whitespace problems!\n";

        file_put_contents($configpath, $config);
    }

    /**
     * Run a Moodle CLI script.
     *
     * @param string $script Relative path to script from dirroot.
     * @param array $args Arguments to pass.
     * @param int $timeout Timeout in seconds.
     * @return string Output from script.
     */
    protected function run_cli_script(string $script, array $args = [], int $timeout = 300): string {
        $scriptpath = $this->dirroot . '/' . $script;
        if (!file_exists($scriptpath)) {
            throw new \Exception("CLI script not found: {$scriptpath}");
        }

        $command = 'php ' . escapeshellarg($scriptpath);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $command .= ' 2>&1';

        $output = [];
        $returncode = 0;

        // Set timeout using proc_open for better control
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);

            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $starttime = time();
            $stdout = '';
            $stderr = '';

            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }

                if ((time() - $starttime) > $timeout) {
                    proc_terminate($process);
                    throw new \Exception("CLI script timed out after {$timeout} seconds");
                }

                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);

                usleep(100000); // 100ms
            }

            // Get remaining output
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            fclose($pipes[1]);
            fclose($pipes[2]);

            $returncode = proc_close($process);

            if ($returncode !== 0 && strpos($stderr, 'error') !== false) {
                throw new \Exception("CLI script failed: " . $stderr);
            }

            return $stdout . $stderr;
        }

        return '';
    }

    /**
     * Perform health check after import.
     *
     * @return array Health check results.
     */
    protected function perform_health_check(): array {
        $results = [
            'passed' => true,
            'checks' => [],
        ];

        // Check 1: Config file exists
        $configpath = $this->dirroot . '/config.php';
        $results['checks']['config_exists'] = file_exists($configpath);
        if (!$results['checks']['config_exists']) {
            $results['passed'] = false;
        }

        // Check 2: Database connection works
        try {
            $this->test_database_connection();
            $results['checks']['database_connection'] = true;
        } catch (\Exception $e) {
            $results['checks']['database_connection'] = false;
            $results['passed'] = false;
        }

        // Check 3: Dataroot is writable
        $results['checks']['dataroot_writable'] = is_writable($this->dataroot);
        if (!$results['checks']['dataroot_writable']) {
            $results['passed'] = false;
        }

        // Check 4: Try to load Moodle's config
        $results['checks']['moodle_config_loads'] = true;
        try {
            // Simple syntax check
            $configcontent = file_get_contents($configpath);
            if (strpos($configcontent, '$CFG->wwwroot') === false) {
                $results['checks']['moodle_config_loads'] = false;
                $results['passed'] = false;
            }
        } catch (\Exception $e) {
            $results['checks']['moodle_config_loads'] = false;
            $results['passed'] = false;
        }

        // Check 5: Test HTTP connectivity if possible
        if (function_exists('curl_init')) {
            $ch = curl_init($this->wwwroot . '/login/index.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $results['checks']['http_accessible'] = ($httpcode >= 200 && $httpcode < 400);
        } else {
            $results['checks']['http_accessible'] = 'not_tested';
        }

        return $results;
    }

    /**
     * Attempt to rollback changes made during import.
     */
    protected function rollback(): void {
        $this->progress('rollback', 1, 'Attempting rollback...');

        // Note: Full rollback is not always possible, especially after database changes
        // This is a best-effort attempt

        if (isset($this->rollbacklog['database_dropped'])) {
            // Cannot restore dropped database - warn user
            error_log("WARNING: Database tables were dropped but import failed. Manual restore required.");
        }

        // Cleanup temp directory
        if (isset($this->rollbacklog['tempdir']) && is_dir($this->rollbacklog['tempdir'])) {
            $this->delete_directory_recursive($this->rollbacklog['tempdir']);
        }
    }

    /**
     * Cleanup temporary files.
     */
    protected function cleanup(): void {
        if (!empty($this->tempdir) && is_dir($this->tempdir)) {
            $this->delete_directory_recursive($this->tempdir);
        }

        $this->disconnect_database();
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir Directory path.
     */
    protected function delete_directory_recursive(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_directory_recursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Format file size for display.
     *
     * @param int $bytes Size in bytes.
     * @return string Formatted size.
     */
    protected function format_size(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Get the current manifest data.
     *
     * @return array
     */
    public function get_manifest(): array {
        return $this->manifest;
    }

    /**
     * Validate options without executing.
     *
     * @return array Array of validation errors.
     */
    public function validate(): array {
        $errors = [];

        if (empty($this->zipfile)) {
            $errors[] = 'Export file path is required (--file)';
        } elseif (!file_exists($this->zipfile)) {
            $errors[] = "Export file not found: {$this->zipfile}";
        }

        if (empty($this->wwwroot)) {
            $errors[] = 'Site URL is required (--wwwroot)';
        } elseif (!filter_var($this->wwwroot, FILTER_VALIDATE_URL)) {
            $errors[] = "Invalid site URL: {$this->wwwroot}";
        }

        if (empty($this->dbname)) {
            $errors[] = 'Database name is required (--dbname)';
        }

        if (empty($this->dbuser)) {
            $errors[] = 'Database user is required (--dbuser)';
        }

        return $errors;
    }
}
