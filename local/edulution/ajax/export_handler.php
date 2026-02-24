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
 * AJAX handler to perform export directly (without CLI).
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

// Require login and capability.
require_login();
require_capability('local/edulution:export', context_system::instance());
require_sesskey();

// Increase limits for export.
@set_time_limit(0);
@ini_set('memory_limit', '1G');

// Set JSON header.
header('Content-Type: application/json');

try {
    // Get export options from POST.
    $options = [
        'full_db_export' => optional_param('full_db_export', 1, PARAM_INT),
        'include_moodledata' => optional_param('include_moodledata', 0, PARAM_INT),
        'include_plugins' => optional_param('include_plugins', 1, PARAM_INT),
        'include_plugin_code' => optional_param('include_plugin_code', 0, PARAM_INT),
        'compression_level' => optional_param('compression_level', 6, PARAM_INT),
        'exclude_tables' => optional_param('exclude_tables', '', PARAM_TEXT),
    ];

    // Validate compression level.
    if ($options['compression_level'] < 0 || $options['compression_level'] > 9) {
        $options['compression_level'] = 6;
    }

    // Generate a unique job ID (without more_entropy to avoid dots that get filtered by PARAM_ALPHANUMEXT).
    $jobId = 'export_' . uniqid() . bin2hex(random_bytes(4));

    // Create export directory if it doesn't exist.
    $exportDir = $CFG->dataroot . '/edulution/exports';
    if (!is_dir($exportDir)) {
        if (!mkdir($exportDir, 0755, true)) {
            throw new Exception('Cannot create export directory: ' . $exportDir);
        }
    }

    // Create progress directory.
    $progressDir = $CFG->dataroot . '/edulution/progress';
    if (!is_dir($progressDir)) {
        mkdir($progressDir, 0755, true);
    }

    // Generate output filename.
    $timestamp = date('Y-m-d_His');
    $sitename = preg_replace('/[^a-zA-Z0-9]/', '_', $SITE->shortname);
    $outputFile = $exportDir . "/edulution_export_{$sitename}_{$timestamp}.zip";

    // Create progress file path.
    $progressFile = $progressDir . '/export_' . $jobId . '.json';

    // Write initial progress file BEFORE sending response.
    $initialProgress = [
        'progress' => 5,
        'percentage' => 5,
        'phase' => 'Starting export...',
        'message' => 'Export job initialized',
        'log' => "Export job: {$jobId}\nOutput: " . basename($outputFile) . "\n",
        'status' => 'running',
        'completed' => false,
        'success' => true,
        'output_file' => $outputFile,
    ];
    file_put_contents($progressFile, json_encode($initialProgress));

    // Return job ID immediately.
    echo json_encode([
        'success' => true,
        'jobid' => $jobId,
        'message' => 'Export job started',
        'output_file' => basename($outputFile),
    ]);

    // Debug log file.
    $debugLog = $progressDir . '/export_debug_' . $jobId . '.log';
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Export handler started\n", FILE_APPEND);
    file_put_contents($debugLog, "Progress file: $progressFile\n", FILE_APPEND);
    file_put_contents($debugLog, "Output file: $outputFile\n", FILE_APPEND);

    // Flush output to browser.
    if (function_exists('fastcgi_finish_request')) {
        file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Using fastcgi_finish_request()\n", FILE_APPEND);
        fastcgi_finish_request();
    } else {
        file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Using ob_end_flush()\n", FILE_APPEND);
        ob_end_flush();
        flush();
    }

    file_put_contents($debugLog, date('Y-m-d H:i:s') . " - After flush, continuing...\n", FILE_APPEND);

    // Now perform the export.
    ignore_user_abort(true);

    file_put_contents($debugLog, date('Y-m-d H:i:s') . " - ignore_user_abort set, starting export\n", FILE_APPEND);

    // Update progress function.
    $updateProgress = function($percent, $phase, $log = '', $complete = false, $success = true) use ($progressFile, $outputFile, $jobId) {
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
            'success' => $success,
        ];

        if ($complete && $success && file_exists($outputFile)) {
            $data['filename'] = basename($outputFile);
            $data['filesize'] = filesize($outputFile);
            $data['download_url'] = (new moodle_url('/local/edulution/ajax/download.php', [
                'file' => basename($outputFile),
                'sesskey' => sesskey(),
            ]))->out(false);
        }

        file_put_contents($progressFile, json_encode($data));
    };

    // Start export.
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Calling updateProgress\n", FILE_APPEND);
    $updateProgress(5, 'Starting export...', "Export job: {$jobId}\nOutput: " . basename($outputFile));
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " - updateProgress called\n", FILE_APPEND);

    // Create temporary directory.
    $tempDir = $CFG->tempdir . '/edulution_export_' . $jobId;
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Creating temp dir: $tempDir\n", FILE_APPEND);
    if (!mkdir($tempDir, 0755, true)) {
        file_put_contents($debugLog, date('Y-m-d H:i:s') . " - ERROR: Cannot create temp dir\n", FILE_APPEND);
        throw new Exception('Cannot create temporary directory');
    }
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Temp dir created\n", FILE_APPEND);

    try {
        file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Entering main try block\n", FILE_APPEND);
        // ========================================
        // Phase 1: Create manifest
        // ========================================
        $updateProgress(10, 'Creating manifest...', 'Creating export manifest...');

        $manifest = [
            'export_version' => '1.0.0',
            'export_type' => $options['full_db_export'] ? 'full' : 'metadata',
            'export_timestamp' => date('c'),
            'export_time' => date('Y-m-d H:i:s'),
            'source_moodle' => [
                'version' => $CFG->version,
                'release' => $CFG->release,
                'site_name' => $SITE->fullname,
                'shortname' => $SITE->shortname,
                'wwwroot' => $CFG->wwwroot,
                'dataroot' => $CFG->dataroot,
                'dbtype' => $CFG->dbtype,
            ],
            'statistics' => [],
            'options' => $options,
        ];

        file_put_contents($tempDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        $updateProgress(15, 'Manifest created', 'Manifest created successfully.');

        // ========================================
        // Phase 2: Export plugins list
        // ========================================
        if ($options['include_plugins']) {
            $updateProgress(20, 'Exporting plugins list...', 'Collecting plugin information...');

            $pluginManager = core_plugin_manager::instance();
            $allPlugins = $pluginManager->get_plugins();

            $plugins = [];
            $coreCount = 0;
            $additionalCount = 0;

            foreach ($allPlugins as $type => $typePlugins) {
                foreach ($typePlugins as $name => $info) {
                    $isCore = $info->is_standard();
                    $plugin = [
                        'component' => $type . '_' . $name,
                        'type' => $type,
                        'name' => $name,
                        'version' => $info->versiondb ?? $info->versiondisk ?? null,
                        'release' => $info->release ?? null,
                        'is_core' => $isCore,
                    ];
                    $plugins[] = $plugin;

                    if ($isCore) {
                        $coreCount++;
                    } else {
                        $additionalCount++;
                    }
                }
            }

            $pluginData = [
                'export_time' => date('c'),
                'moodle_version' => $CFG->version,
                'moodle_release' => $CFG->release,
                'total_plugins' => count($plugins),
                'core_plugins' => $coreCount,
                'additional_plugins' => $additionalCount,
                'plugins' => $plugins,
            ];

            file_put_contents($tempDir . '/plugins.json', json_encode($pluginData, JSON_PRETTY_PRINT));
            $manifest['statistics']['plugins_total'] = count($plugins);
            $manifest['statistics']['plugins_additional'] = $additionalCount;
            $updateProgress(25, 'Plugins exported', "Exported " . count($plugins) . " plugins ($additionalCount additional).");
        }

        // ========================================
        // Phase 2b: Export plugin code (additional plugins only)
        // ========================================
        if ($options['include_plugin_code']) {
            $updateProgress(27, 'Exporting plugin code...', 'Copying additional plugin files...');

            $pluginManager = core_plugin_manager::instance();
            $allPlugins = $pluginManager->get_plugins();
            $pluginsDir = $tempDir . '/plugins';
            mkdir($pluginsDir, 0755, true);

            $exportedPlugins = 0;
            $totalSize = 0;

            foreach ($allPlugins as $type => $typePlugins) {
                foreach ($typePlugins as $name => $info) {
                    // Only export non-core (additional) plugins.
                    if ($info->is_standard()) {
                        continue;
                    }

                    // Get plugin directory.
                    $pluginDir = $info->rootdir ?? null;
                    if (!$pluginDir || !is_dir($pluginDir)) {
                        continue;
                    }

                    // Create target directory structure: plugins/type/name/
                    $targetDir = $pluginsDir . '/' . $type . '/' . $name;
                    if (!is_dir(dirname($targetDir))) {
                        mkdir(dirname($targetDir), 0755, true);
                    }

                    // Copy plugin directory.
                    $cpCmd = "cp -r " . escapeshellarg($pluginDir) . " " . escapeshellarg($targetDir) . " 2>&1";
                    exec($cpCmd, $cpOutput, $cpReturn);

                    if ($cpReturn === 0) {
                        $exportedPlugins++;
                        // Get directory size.
                        $sizeCmd = "du -sb " . escapeshellarg($targetDir) . " 2>/dev/null | cut -f1";
                        $size = (int)trim(shell_exec($sizeCmd));
                        $totalSize += $size;
                    }
                }
            }

            $manifest['statistics']['plugins_code_exported'] = $exportedPlugins;
            $manifest['statistics']['plugins_code_size'] = $totalSize;
            $updateProgress(29, 'Plugin code exported', "Exported code for {$exportedPlugins} additional plugins (" . local_edulution_format_filesize($totalSize) . ").");
        }

        // ========================================
        // Phase 3: Export database
        // ========================================
        if ($options['full_db_export']) {
            $updateProgress(30, 'Exporting database...', 'Starting database export (this may take a while)...');

            // Get database credentials from config.
            $dbhost = $CFG->dbhost;
            $dbname = $CFG->dbname;
            $dbuser = $CFG->dbuser;
            $dbpass = $CFG->dbpass;
            $dbport = isset($CFG->dboptions['dbport']) ? $CFG->dboptions['dbport'] : 3306;

            // Build exclude tables list.
            $excludeTables = [];
            if (!empty($options['exclude_tables'])) {
                $excludeTables = array_map('trim', explode(',', $options['exclude_tables']));
                $excludeTables = array_filter($excludeTables);
            }

            // Always exclude some tables that shouldn't be migrated.
            $defaultExclude = ['sessions', 'task_log', 'upgrade_log'];
            $excludeTables = array_unique(array_merge($excludeTables, $defaultExclude));

            // Build mysqldump command.
            $dumpFile = $tempDir . '/database.sql';

            // Create credentials file for security.
            $cnfFile = $tempDir . '/.my.cnf';
            $cnfContent = "[client]\n";
            $cnfContent .= "host={$dbhost}\n";
            $cnfContent .= "port={$dbport}\n";
            $cnfContent .= "user={$dbuser}\n";
            $cnfContent .= "password=" . str_replace('"', '\\"', $dbpass) . "\n";
            file_put_contents($cnfFile, $cnfContent);
            chmod($cnfFile, 0600);

            // Build mysqldump command.
            $mysqldump = 'mysqldump';
            // Try to find mysqldump.
            $paths = ['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/local/mysql/bin/mysqldump'];
            foreach ($paths as $path) {
                if (file_exists($path) && is_executable($path)) {
                    $mysqldump = $path;
                    break;
                }
            }

            $cmd = $mysqldump . ' --defaults-extra-file=' . escapeshellarg($cnfFile);
            $cmd .= ' --single-transaction --quick --lock-tables=false';
            $cmd .= ' --routines --triggers --events';

            // Add excluded tables.
            foreach ($excludeTables as $table) {
                $fullTableName = $CFG->prefix . $table;
                $cmd .= ' --ignore-table=' . escapeshellarg($dbname . '.' . $fullTableName);
            }

            $cmd .= ' ' . escapeshellarg($dbname);
            $cmd .= ' > ' . escapeshellarg($dumpFile);
            $cmd .= ' 2>&1';

            $updateProgress(40, 'Running mysqldump...', 'Dumping database...');

            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            // Remove credentials file.
            @unlink($cnfFile);

            if ($returnCode !== 0) {
                throw new Exception('Database dump failed: ' . implode("\n", $output));
            }

            // Compress the dump.
            $updateProgress(55, 'Compressing database...', 'Compressing database dump...');

            $gzFile = $dumpFile . '.gz';
            $fp = gzopen($gzFile, 'w' . $options['compression_level']);
            $handle = fopen($dumpFile, 'r');
            while (!feof($handle)) {
                gzwrite($fp, fread($handle, 1024 * 512)); // 512KB chunks.
            }
            fclose($handle);
            gzclose($fp);

            // Remove uncompressed dump.
            @unlink($dumpFile);

            $dumpSize = filesize($gzFile);
            $manifest['statistics']['database_size'] = $dumpSize;
            $manifest['statistics']['database_size_formatted'] = local_edulution_format_filesize($dumpSize);

            // Count tables.
            global $DB;
            $tables = $DB->get_tables();
            $manifest['statistics']['database_tables'] = count($tables);

            $updateProgress(65, 'Database exported', "Database exported (" . local_edulution_format_filesize($dumpSize) . " compressed).");
        }

        // ========================================
        // Phase 4: Copy moodledata (if requested)
        // ========================================
        if ($options['include_moodledata']) {
            $updateProgress(70, 'Copying moodledata...', 'Copying moodledata files (this may take a while)...');

            $moodledataDir = $tempDir . '/moodledata';
            mkdir($moodledataDir, 0755, true);

            // Only copy essential directories.
            $dirsToInclude = ['filedir', 'lang'];
            $filesCopied = 0;
            $totalSize = 0;

            foreach ($dirsToInclude as $dir) {
                $srcDir = $CFG->dataroot . '/' . $dir;
                $dstDir = $moodledataDir . '/' . $dir;

                if (is_dir($srcDir)) {
                    // Use system copy for speed.
                    $cpCmd = "cp -r " . escapeshellarg($srcDir) . " " . escapeshellarg($dstDir) . " 2>&1";
                    exec($cpCmd, $cpOutput, $cpReturn);

                    if ($cpReturn === 0) {
                        // Count files (approximate).
                        $countCmd = "find " . escapeshellarg($dstDir) . " -type f | wc -l";
                        $count = trim(shell_exec($countCmd));
                        $filesCopied += (int)$count;
                    }
                }
            }

            $manifest['statistics']['moodledata_files'] = $filesCopied;
            $updateProgress(80, 'Moodledata copied', "Copied moodledata ({$filesCopied} files).");
        }

        // ========================================
        // Phase 5: Update manifest and create ZIP
        // ========================================
        $updateProgress(85, 'Creating ZIP package...', 'Creating final ZIP package...');

        // Update manifest with final statistics.
        file_put_contents($tempDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        // Create ZIP file.
        $zip = new ZipArchive();
        if ($zip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Cannot create ZIP file');
        }

        // Add all files from temp directory.
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $fileCount = 0;
        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($tempDir) + 1);

                // Skip hidden files.
                if (strpos($relativePath, '.') === 0) {
                    continue;
                }

                $zip->addFile($filePath, $relativePath);
                $fileCount++;
            }
        }

        $zip->close();

        $updateProgress(95, 'Cleaning up...', "ZIP created with {$fileCount} files.");

        // Clean up temp directory.
        $cleanCmd = "rm -rf " . escapeshellarg($tempDir);
        exec($cleanCmd);

        // ========================================
        // Complete
        // ========================================
        $finalSize = filesize($outputFile);
        $updateProgress(100, 'Export complete!',
            "Export completed successfully!\n" .
            "File: " . basename($outputFile) . "\n" .
            "Size: " . local_edulution_format_filesize($finalSize),
            true, true);

        // Log activity.
        local_edulution_log_activity_record('export', 'Export completed successfully', 'success', [
            'filename' => basename($outputFile),
            'filesize' => $finalSize,
        ]);

    } catch (Exception $e) {
        // Clean up on error.
        file_put_contents($debugLog, date('Y-m-d H:i:s') . " - INNER EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
        if (is_dir($tempDir)) {
            exec("rm -rf " . escapeshellarg($tempDir));
        }
        throw $e;
    }

    file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Export completed successfully!\n", FILE_APPEND);

} catch (Exception $e) {
    // Log error.
    if (isset($debugLog)) {
        file_put_contents($debugLog, date('Y-m-d H:i:s') . " - OUTER EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
        file_put_contents($debugLog, "Stack trace:\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
    }

    if (isset($updateProgress)) {
        $updateProgress(0, 'Export failed', 'ERROR: ' . $e->getMessage(), true, false);
    }

    local_edulution_log_activity_record('export', 'Export failed with exception', 'failed', [
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
