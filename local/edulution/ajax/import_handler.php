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
 * AJAX handler for import execution (runs import directly without CLI).
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../lib.php');

// Check access.
require_login();
$context = context_system::instance();
require_capability('local/edulution:import', $context);
require_sesskey();

// Increase limits.
@set_time_limit(0);
@ini_set('memory_limit', '1G');

header('Content-Type: application/json');

try {
    // Get parameters.
    $file = required_param('file', PARAM_PATH);
    $wwwroot = required_param('wwwroot', PARAM_URL);
    $importDatabase = optional_param('import_database', 1, PARAM_BOOL);
    $importMoodledata = optional_param('import_moodledata', 0, PARAM_BOOL);
    $skipPlugins = optional_param('skip_plugins', 0, PARAM_BOOL);
    $dryRun = optional_param('dry_run', 0, PARAM_BOOL);

    // Validate file exists.
    if (!file_exists($file)) {
        throw new Exception('Import file not found: ' . $file);
    }

    // Validate file is within allowed directory.
    $allowedDir = $CFG->dataroot . '/edulution/imports';
    if (strpos(realpath($file), realpath($allowedDir)) !== 0) {
        throw new Exception('Import file must be in the imports directory.');
    }

    // Generate job ID (without more_entropy to avoid dots that get filtered by PARAM_ALPHANUMEXT).
    $jobId = 'import_' . uniqid() . bin2hex(random_bytes(4));

    // Create progress directory.
    $progressDir = $CFG->dataroot . '/edulution/progress';
    if (!is_dir($progressDir)) {
        mkdir($progressDir, 0755, true);
    }
    $progressFile = $progressDir . '/import_' . $jobId . '.json';

    // Write initial progress file BEFORE sending response.
    $initialProgress = [
        'progress' => 5,
        'percentage' => 5,
        'phase' => 'Starting import...',
        'message' => 'Import job initialized',
        'log' => "Import job: {$jobId}\nFile: " . basename($file) . "\nMode: " . ($dryRun ? 'DRY RUN' : 'LIVE IMPORT') . "\n",
        'status' => 'running',
        'completed' => false,
        'complete' => false,
        'success' => true,
        'dry_run' => $dryRun,
        'wwwroot' => $wwwroot,
    ];
    file_put_contents($progressFile, json_encode($initialProgress));

    // Return job ID immediately.
    echo json_encode([
        'success' => true,
        'jobid' => $jobId,
        'message' => 'Import job started',
        'dry_run' => $dryRun,
    ]);

    // Flush to browser.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ob_end_flush();
        flush();
    }

    // Now perform the import.
    ignore_user_abort(true);

    // Progress update function.
    $updateProgress = function($percent, $phase, $log = '', $complete = false, $success = true) use ($progressFile, $wwwroot, $dryRun) {
        static $fullLog = '';
        if (!empty($log)) {
            $fullLog .= $log . "\n";
        }

        $data = [
            'progress' => $percent,
            'percentage' => $percent,
            'phase' => $phase,
            'message' => $phase,
            'log' => $fullLog,
            'status' => $complete ? ($success ? 'complete' : 'error') : 'running',
            'completed' => $complete,
            'complete' => $complete,
            'success' => $success,
            'dry_run' => $dryRun,
            'wwwroot' => $wwwroot,
        ];

        if ($complete && $success && !$dryRun) {
            $data['redirect'] = $wwwroot . '/login/index.php';
        }

        file_put_contents($progressFile, json_encode($data));
    };

    // Start import.
    $updateProgress(5, 'Starting import...', "Import job: {$jobId}\nFile: " . basename($file) . "\nMode: " . ($dryRun ? 'DRY RUN' : 'LIVE IMPORT'));

    // Extract ZIP to temp directory.
    $tempDir = $CFG->tempdir . '/edulution_import_' . $jobId;
    if (!mkdir($tempDir, 0755, true)) {
        throw new Exception('Cannot create temporary directory');
    }

    try {
        // ========================================
        // Phase 1: Extract package
        // ========================================
        $updateProgress(10, 'Extracting package...', 'Extracting import package...');

        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw new Exception('Cannot open ZIP file');
        }

        if (!$zip->extractTo($tempDir)) {
            $zip->close();
            throw new Exception('Failed to extract ZIP file');
        }
        $zip->close();

        $updateProgress(15, 'Package extracted', 'Package extracted successfully.');

        // ========================================
        // Phase 2: Validate manifest
        // ========================================
        $updateProgress(20, 'Validating manifest...', 'Checking manifest...');

        $manifestPath = $tempDir . '/manifest.json';
        if (!file_exists($manifestPath)) {
            throw new Exception('manifest.json not found in import package');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid manifest.json: ' . json_last_error_msg());
        }

        $oldWwwroot = $manifest['source_moodle']['wwwroot'] ?? '';
        $sourceVersion = $manifest['source_moodle']['release'] ?? 'unknown';

        $updateProgress(25, 'Manifest validated', "Source: {$sourceVersion}\nOld URL: {$oldWwwroot}");

        // ========================================
        // Phase 3: Import database
        // ========================================
        if ($importDatabase) {
            // Find database dump.
            $dumpFile = null;
            $isGzipped = false;

            if (file_exists($tempDir . '/database.sql.gz')) {
                $dumpFile = $tempDir . '/database.sql.gz';
                $isGzipped = true;
            } elseif (file_exists($tempDir . '/database.sql')) {
                $dumpFile = $tempDir . '/database.sql';
            }

            if (!$dumpFile) {
                $updateProgress(30, 'No database dump', 'No database dump found in package, skipping database import.');
            } else {
                $updateProgress(30, 'Importing database...', 'Starting database import (this may take a while)...');

                if ($dryRun) {
                    $updateProgress(60, '[DRY RUN] Database import', 'Would import database from: ' . basename($dumpFile));
                } else {
                    // Get database credentials.
                    $dbhost = $CFG->dbhost;
                    $dbname = $CFG->dbname;
                    $dbuser = $CFG->dbuser;
                    $dbpass = $CFG->dbpass;
                    $dbport = isset($CFG->dboptions['dbport']) ? $CFG->dboptions['dbport'] : 3306;

                    // Create credentials file.
                    $cnfFile = $tempDir . '/.my.cnf';
                    $cnfContent = "[client]\n";
                    $cnfContent .= "host={$dbhost}\n";
                    $cnfContent .= "port={$dbport}\n";
                    $cnfContent .= "user={$dbuser}\n";
                    $cnfContent .= "password=" . str_replace('"', '\\"', $dbpass) . "\n";
                    file_put_contents($cnfFile, $cnfContent);
                    chmod($cnfFile, 0600);

                    // Find mysql command.
                    $mysql = 'mysql';
                    $paths = ['/usr/bin/mysql', '/usr/local/bin/mysql', '/usr/local/mysql/bin/mysql'];
                    foreach ($paths as $path) {
                        if (file_exists($path) && is_executable($path)) {
                            $mysql = $path;
                            break;
                        }
                    }

                    $updateProgress(35, 'Dropping existing tables...', 'Dropping existing tables...');

                    // Drop existing tables first using direct connection.
                    global $DB;
                    $DB->execute("SET FOREIGN_KEY_CHECKS = 0");
                    $tables = $DB->get_tables();
                    foreach ($tables as $table) {
                        $DB->execute("DROP TABLE IF EXISTS {{$table}}");
                    }
                    $DB->execute("SET FOREIGN_KEY_CHECKS = 1");

                    $updateProgress(45, 'Running import...', 'Importing database dump...');

                    // Import using mysql command.
                    if ($isGzipped) {
                        $cmd = "gunzip -c " . escapeshellarg($dumpFile) .
                            " | {$mysql} --defaults-extra-file=" . escapeshellarg($cnfFile) .
                            " " . escapeshellarg($dbname) . " 2>&1";
                    } else {
                        $cmd = "{$mysql} --defaults-extra-file=" . escapeshellarg($cnfFile) .
                            " " . escapeshellarg($dbname) .
                            " < " . escapeshellarg($dumpFile) . " 2>&1";
                    }

                    $output = [];
                    $returnCode = 0;
                    exec($cmd, $output, $returnCode);

                    @unlink($cnfFile);

                    if ($returnCode !== 0) {
                        throw new Exception('Database import failed: ' . implode("\n", $output));
                    }

                    $updateProgress(55, 'Updating URLs...', 'Replacing old URLs with new...');

                    // Use direct MySQL to update config (more reliable after DB import).
                    $configTable = $CFG->prefix . 'config';
                    $sessionsTable = $CFG->prefix . 'sessions';
                    $escapedWwwroot = str_replace("'", "\\'", $wwwroot);
                    $escapedDataroot = str_replace("'", "\\'", $CFG->dataroot);

                    // Build MySQL commands for config updates and sessions table creation.
                    $sqlCommands = "
                        INSERT INTO {$configTable} (name, value) VALUES ('wwwroot', '{$escapedWwwroot}')
                        ON DUPLICATE KEY UPDATE value = '{$escapedWwwroot}';
                        INSERT INTO {$configTable} (name, value) VALUES ('dataroot', '{$escapedDataroot}')
                        ON DUPLICATE KEY UPDATE value = '{$escapedDataroot}';
                        UPDATE {$configTable} SET value = '{$escapedWwwroot}' WHERE name = 'sslwwwroot';
                        CREATE TABLE IF NOT EXISTS {$sessionsTable} (
                            id BIGINT(10) NOT NULL AUTO_INCREMENT,
                            state BIGINT(10) NOT NULL DEFAULT 0,
                            sid VARCHAR(128) NOT NULL DEFAULT '',
                            userid BIGINT(10) NOT NULL DEFAULT 0,
                            sessdata LONGTEXT,
                            timecreated BIGINT(10) NOT NULL DEFAULT 0,
                            timemodified BIGINT(10) NOT NULL DEFAULT 0,
                            firstip VARCHAR(45) DEFAULT NULL,
                            lastip VARCHAR(45) DEFAULT NULL,
                            PRIMARY KEY (id),
                            UNIQUE KEY sid (sid),
                            KEY userid (userid),
                            KEY state (state),
                            KEY timecreated (timecreated),
                            KEY timemodified (timemodified)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ";

                    // Create temporary cnf file for mysql command.
                    $tmpCnfFile = sys_get_temp_dir() . '/moodle_import_' . uniqid() . '.cnf';
                    file_put_contents($tmpCnfFile, $cnfContent);
                    chmod($tmpCnfFile, 0600);

                    $configCmd = "{$mysql} --defaults-extra-file=" . escapeshellarg($tmpCnfFile) .
                        " " . escapeshellarg($dbname) . " -e " . escapeshellarg($sqlCommands) . " 2>&1";

                    exec($configCmd, $configOutput, $configReturn);
                    @unlink($tmpCnfFile);

                    if ($configReturn !== 0) {
                        $updateProgress(58, 'Config update warning', 'Warning: Could not update config: ' . implode("\n", $configOutput));
                    }

                    $updateProgress(60, 'URLs updated', "URLs and sessions table updated successfully.\nwwwroot: {$wwwroot}\ndataroot: {$CFG->dataroot}");
                }
            }
        } else {
            $updateProgress(60, 'Database import skipped', 'Database import was not requested.');
        }

        // ========================================
        // Phase 4: Copy moodledata
        // ========================================
        if ($importMoodledata) {
            $moodledataDir = $tempDir . '/moodledata';
            if (is_dir($moodledataDir)) {
                $updateProgress(70, 'Copying moodledata...', 'Copying moodledata files...');

                if ($dryRun) {
                    $updateProgress(85, '[DRY RUN] Moodledata', 'Would copy moodledata files.');
                } else {
                    // Copy moodledata contents.
                    $cmd = "cp -r " . escapeshellarg($moodledataDir) . "/* " . escapeshellarg($CFG->dataroot) . "/ 2>&1";
                    exec($cmd, $cpOutput, $cpReturn);

                    if ($cpReturn === 0) {
                        $updateProgress(85, 'Moodledata copied', 'Moodledata files copied successfully.');
                    } else {
                        $updateProgress(85, 'Moodledata copy warning', 'Some files may not have been copied.');
                    }
                }
            } else {
                $updateProgress(85, 'No moodledata', 'No moodledata directory found in package.');
            }
        } else {
            $updateProgress(85, 'Moodledata skipped', 'Moodledata import was not requested.');
        }

        // ========================================
        // Phase 4b: Install plugins from package (if present)
        // ========================================
        $pluginsDir = $tempDir . '/plugins';
        if (is_dir($pluginsDir) && !$dryRun) {
            $updateProgress(86, 'Installing plugins...', 'Installing additional plugins from package...');

            $installedPlugins = 0;
            $pluginTypes = scandir($pluginsDir);

            foreach ($pluginTypes as $type) {
                if ($type === '.' || $type === '..') {
                    continue;
                }

                $typeDir = $pluginsDir . '/' . $type;
                if (!is_dir($typeDir)) {
                    continue;
                }

                // Determine target directory based on plugin type.
                $targetBase = null;
                switch ($type) {
                    case 'mod':
                        $targetBase = $CFG->dirroot . '/mod';
                        break;
                    case 'local':
                        $targetBase = $CFG->dirroot . '/local';
                        break;
                    case 'block':
                        $targetBase = $CFG->dirroot . '/blocks';
                        break;
                    case 'theme':
                        $targetBase = $CFG->dirroot . '/theme';
                        break;
                    case 'auth':
                        $targetBase = $CFG->dirroot . '/auth';
                        break;
                    case 'enrol':
                        $targetBase = $CFG->dirroot . '/enrol';
                        break;
                    case 'filter':
                        $targetBase = $CFG->dirroot . '/filter';
                        break;
                    case 'report':
                        $targetBase = $CFG->dirroot . '/report';
                        break;
                    case 'repository':
                        $targetBase = $CFG->dirroot . '/repository';
                        break;
                    case 'qtype':
                        $targetBase = $CFG->dirroot . '/question/type';
                        break;
                    case 'format':
                        $targetBase = $CFG->dirroot . '/course/format';
                        break;
                    case 'editor':
                        $targetBase = $CFG->dirroot . '/lib/editor';
                        break;
                    default:
                        // Try generic path.
                        $targetBase = $CFG->dirroot . '/' . $type;
                }

                if (!$targetBase || !is_dir($targetBase)) {
                    continue;
                }

                // Install each plugin of this type.
                $pluginNames = scandir($typeDir);
                foreach ($pluginNames as $name) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }

                    $sourceDir = $typeDir . '/' . $name;
                    $targetDir = $targetBase . '/' . $name;

                    if (!is_dir($sourceDir)) {
                        continue;
                    }

                    // Remove existing plugin if present.
                    if (is_dir($targetDir)) {
                        exec("rm -rf " . escapeshellarg($targetDir) . " 2>&1");
                    }

                    // Copy plugin.
                    $cpCmd = "cp -r " . escapeshellarg($sourceDir) . " " . escapeshellarg($targetDir) . " 2>&1";
                    exec($cpCmd, $cpOutput, $cpReturn);

                    if ($cpReturn === 0) {
                        $installedPlugins++;
                    }
                }
            }

            $updateProgress(88, 'Plugins installed', "Installed {$installedPlugins} additional plugins.");
        } elseif (is_dir($pluginsDir) && $dryRun) {
            $pluginCount = 0;
            $types = scandir($pluginsDir);
            foreach ($types as $t) {
                if ($t !== '.' && $t !== '..' && is_dir($pluginsDir . '/' . $t)) {
                    $plugins = scandir($pluginsDir . '/' . $t);
                    $pluginCount += count(array_filter($plugins, fn($p) => $p !== '.' && $p !== '..'));
                }
            }
            $updateProgress(88, '[DRY RUN] Plugins', "Would install {$pluginCount} additional plugins.");
        }

        // ========================================
        // Phase 5: Run upgrade and fix tables (if not dry run)
        // ========================================
        if (!$dryRun && $importDatabase) {
            $updateProgress(90, 'Running upgrade...', 'Running Moodle upgrade and fixing tables...');

            // Reconnect to database if not already connected.
            if (!isset($DB) || !$DB) {
                $DB = moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
                $DB->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->prefix, $CFG->dboptions);
            }

            // Create sessions table if it doesn't exist (often excluded from exports).
            $sessionsTableExists = false;
            try {
                $DB->count_records('sessions');
                $sessionsTableExists = true;
            } catch (Exception $e) {
                $sessionsTableExists = false;
            }

            if (!$sessionsTableExists) {
                $updateProgress(92, 'Creating sessions table...', 'Creating missing sessions table...');
                $DB->execute("
                    CREATE TABLE IF NOT EXISTS {sessions} (
                        id BIGINT(10) NOT NULL AUTO_INCREMENT,
                        state BIGINT(10) NOT NULL DEFAULT 0,
                        sid VARCHAR(128) NOT NULL DEFAULT '',
                        userid BIGINT(10) NOT NULL DEFAULT 0,
                        sessdata LONGTEXT,
                        timecreated BIGINT(10) NOT NULL DEFAULT 0,
                        timemodified BIGINT(10) NOT NULL DEFAULT 0,
                        firstip VARCHAR(45) DEFAULT NULL,
                        lastip VARCHAR(45) DEFAULT NULL,
                        PRIMARY KEY (id),
                        UNIQUE KEY sid (sid),
                        KEY userid (userid),
                        KEY state (state),
                        KEY timecreated (timecreated),
                        KEY timemodified (timemodified)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            // Purge caches first.
            $purgeScript = $CFG->dirroot . '/admin/cli/purge_caches.php';
            if (file_exists($purgeScript)) {
                exec('php ' . escapeshellarg($purgeScript) . ' 2>&1');
            }

            // Run Moodle upgrade to install new plugins.
            $updateProgress(93, 'Running Moodle upgrade...', 'Running database upgrade for new plugins...');
            $upgradeScript = $CFG->dirroot . '/admin/cli/upgrade.php';
            if (file_exists($upgradeScript)) {
                exec('php ' . escapeshellarg($upgradeScript) . ' --non-interactive 2>&1', $upgradeOutput, $upgradeReturn);
                if ($upgradeReturn !== 0) {
                    // Log warning but continue.
                    $updateProgress(94, 'Upgrade warning', 'Upgrade completed with warnings. Some plugins may need manual setup.');
                }
            }

            // Purge caches again after upgrade.
            exec('php ' . escapeshellarg($purgeScript) . ' 2>&1');

            $updateProgress(95, 'Upgrade complete', 'Upgrade and cache purge completed.');
        }

        // ========================================
        // Cleanup and complete
        // ========================================
        exec("rm -rf " . escapeshellarg($tempDir));

        if ($dryRun) {
            $updateProgress(100, 'Dry run complete!',
                "Dry run completed successfully!\n" .
                "No changes were made to the database.",
                true, true);
        } else {
            $updateProgress(100, 'Import complete!',
                "Import completed successfully!\n" .
                "Please log in with credentials from the imported database.\n" .
                "You will be redirected to the login page.",
                true, true);
        }

        // Log activity.
        local_edulution_log_activity_record('import', 'Import completed successfully', 'success', [
            'filename' => basename($file),
            'dry_run' => $dryRun,
        ]);

    } catch (Exception $e) {
        // Clean up on error.
        if (is_dir($tempDir)) {
            exec("rm -rf " . escapeshellarg($tempDir));
        }
        throw $e;
    }

} catch (Exception $e) {
    // Log error.
    if (isset($updateProgress)) {
        $updateProgress(0, 'Import failed', 'ERROR: ' . $e->getMessage(), true, false);
    }

    local_edulution_log_activity_record('import', 'Import failed with exception', 'failed', [
        'error' => $e->getMessage(),
    ]);

    // Only output JSON if we haven't already sent a response.
    if (!headers_sent()) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
}
