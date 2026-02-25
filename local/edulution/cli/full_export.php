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
 * CLI script for full export - companion to full_import.php.
 *
 * This script creates a complete export package that can be imported
 * using full_import.php. It requires Moodle to be properly installed
 * and configured.
 *
 * Usage:
 *   php full_export.php --output=/path/to/export.zip --full-db --include-moodledata
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Try to load Moodle config
// __DIR__ = .../local/edulution/cli, so we need 3 levels up to reach Moodle root
$configpath = dirname(dirname(dirname(__DIR__))) . '/config.php';
if (!file_exists($configpath)) {
    fwrite(STDERR, "Error: Moodle config.php not found. This script requires a working Moodle installation.\n");
    fwrite(STDERR, "Looking for: {$configpath}\n");
    exit(1);
}

require($configpath);
require_once($CFG->libdir . '/clilib.php');

// Ensure running as admin or from CLI
if (function_exists('is_siteadmin') && !is_siteadmin() && !defined('ABORT_AFTER_CONFIG')) {
    cli_error('This script must be run as a site administrator or from CLI.');
}

// Define CLI options
list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'output' => '',
    'full-db' => false,
    'include-moodledata' => false,
    'exclude-tables' => '',
    'compression' => 6,
    'progress-file' => '',
    'quiet' => false,
    'verbose' => false,
], [
    'h' => 'help',
    'o' => 'output',
    'f' => 'full-db',
    'm' => 'include-moodledata',
    'p' => 'progress-file',
    'q' => 'quiet',
    'v' => 'verbose',
]);

// Show help
if ($options['help']) {
    $help = <<<EOT

edulution Full Export CLI
=========================

Create a complete export package for migration to another Moodle instance.
This is the companion script to full_import.php.

USAGE:
    php full_export.php [options]

OPTIONS:
    --output=PATH, -o       Output file path (default: auto-generated in temp dir)
    --full-db, -f           Include complete database dump (required for full migration)
    --include-moodledata    Include moodledata files (filedir, lang, etc.)
    --exclude-tables=LIST   Comma-separated list of tables to exclude from dump
    --compression=LEVEL     Compression level 0-9 (default: 6)
    --progress-file=PATH    Write progress JSON to this file for UI tracking
    --quiet, -q             Minimal output
    --verbose, -v           Verbose output
    --help, -h              Show this help message

EXAMPLES:
    # Full export with database and moodledata
    php full_export.php --full-db --include-moodledata --output=/tmp/moodle-export.zip

    # Database-only export
    php full_export.php --full-db --output=/tmp/db-only-export.zip

    # Export excluding certain tables
    php full_export.php --full-db --exclude-tables=sessions,log,task_log

CONTENTS:
    The export package includes:
    - manifest.json     - Export metadata and checksums
    - database.sql.gz   - Compressed MySQL dump
    - plugins.json      - List of installed plugins with versions
    - config_backup.json - Site configuration (without passwords)
    - moodledata/       - Moodledata files (if --include-moodledata)

SECURITY WARNING:
    The full database export contains sensitive data including:
    - User password hashes
    - Authentication tokens
    - API keys and secrets

    Handle the export file with extreme care and delete after migration!

EOT;
    echo $help;
    exit(0);
}

// Check for unrecognized options
if (!empty($unrecognized)) {
    $unrecognizedStr = implode(', ', array_keys($unrecognized));
    cli_error("Unrecognized options: {$unrecognizedStr}. Use --help for usage information.");
}

// Validate options
if (!$options['full-db']) {
    cli_error("At least --full-db is required for a usable export. Use --help for more information.");
}

// Set default output path - use edulution exports directory in dataroot
if (empty($options['output'])) {
    $timestamp = date('Y-m-d_His');
    $sitename = preg_replace('/[^a-zA-Z0-9]/', '_', $SITE->shortname);
    $exportdir = $CFG->dataroot . '/edulution/exports';
    if (!is_dir($exportdir)) {
        mkdir($exportdir, 0755, true);
    }
    $options['output'] = $exportdir . "/edulution_export_{$sitename}_{$timestamp}.zip";
}

// Ensure output directory exists
$outputdir = dirname($options['output']);
if (!is_dir($outputdir)) {
    if (!mkdir($outputdir, 0755, true)) {
        cli_error("Cannot create output directory: {$outputdir}");
    }
}

// Helper functions
/**
 * Format file size.
 *
 * @param int $bytes Size in bytes.
 * @return string Formatted size.
 */
function local_edulution_format_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Find mysqldump binary.
 *
 * @return string|null Path to mysqldump or null.
 */
function local_edulution_find_mysqldump(): ?string
{
    $paths = [
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        '/usr/local/mysql/bin/mysqldump',
        '/opt/local/bin/mysqldump',
        '/opt/homebrew/bin/mysqldump',
        '/usr/bin/mariadb-dump',
        '/usr/local/bin/mariadb-dump',
    ];

    foreach ($paths as $path) {
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }

    // Try which command
    $output = [];
    exec('which mysqldump 2>/dev/null', $output);
    if (!empty($output[0]) && is_executable($output[0])) {
        return $output[0];
    }

    exec('which mariadb-dump 2>/dev/null', $output);
    if (!empty($output[0]) && is_executable($output[0])) {
        return $output[0];
    }

    return null;
}

/**
 * Copy directory recursively.
 *
 * @param string $source Source directory.
 * @param string $dest Destination directory.
 * @return array Stats with 'files' and 'size'.
 */
function local_edulution_copy_directory(string $source, string $dest): array
{
    $stats = ['files' => 0, 'size' => 0];

    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
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
 * Delete directory recursively.
 *
 * @param string $dir Directory path.
 */
function local_edulution_delete_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            local_edulution_delete_directory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

/**
 * Add directory to ZIP archive recursively.
 *
 * @param ZipArchive $zip ZipArchive instance.
 * @param string $dir Directory to add.
 * @param string $base Base path within archive.
 */
function local_edulution_add_to_zip(ZipArchive $zip, string $dir, string $base): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $archivePath = $base . $iterator->getSubPathname();

        if ($item->isDir()) {
            $zip->addEmptyDir($archivePath);
        } else {
            $zip->addFile($item->getPathname(), $archivePath);
        }
    }
}

// Global variable to accumulate log output for progress tracking.
$progresslog = '';

/**
 * Update progress file for UI feedback.
 *
 * @param int $percent Progress percentage (0-100).
 * @param string $phase Current phase description.
 * @param string $log Log message to append.
 * @param bool $complete Whether export is complete.
 * @param bool $success Whether export was successful (only used when complete=true).
 */
function local_edulution_update_progress(int $percent, string $phase, string $log = '', bool $complete = false, bool $success = true): void
{
    global $options, $progresslog;

    if (empty($options['progress-file'])) {
        return;
    }

    if (!empty($log)) {
        $progresslog .= $log . "\n";
    }

    $data = [
        'progress' => $percent,
        'percentage' => $percent,
        'phase' => $phase,
        'message' => $phase,
        'log' => $progresslog,
        'status' => $complete ? ($success ? 'complete' : 'error') : 'running',
        'completed' => $complete,
        'success' => $success,
    ];

    // Add download info when complete and successful.
    if ($complete && $success && !empty($options['output'])) {
        $data['filename'] = basename($options['output']);
        if (file_exists($options['output'])) {
            $data['filesize'] = filesize($options['output']);
        }
    }

    file_put_contents($options['progress-file'], json_encode($data));
}

/**
 * Export plugin information.
 *
 * @return array Plugin data.
 */
function local_edulution_export_plugins(): array
{
    global $CFG;

    $pluginManager = core_plugin_manager::instance();
    $allPlugins = $pluginManager->get_plugins();

    $plugins = [];
    foreach ($allPlugins as $type => $typePlugins) {
        foreach ($typePlugins as $name => $pluginInfo) {
            $component = $type . '_' . $name;
            $isCore = isset($pluginInfo->source) && $pluginInfo->source === 'core';

            // Try to determine if it's a core plugin
            if (!isset($pluginInfo->source) && method_exists($pluginInfo, 'is_standard')) {
                $isCore = $pluginInfo->is_standard();
            }

            $plugins[] = [
                'component' => $component,
                'type' => $type,
                'name' => $name,
                'version' => $pluginInfo->versiondb ?? $pluginInfo->versiondisk ?? null,
                'release' => $pluginInfo->release ?? null,
                'is_core' => $isCore,
                'requires' => $pluginInfo->versionrequires ?? null,
                'dependencies' => $pluginInfo->dependencies ?? [],
            ];
        }
    }

    // Sort by component name
    usort($plugins, function ($a, $b) {
        return strcmp($a['component'], $b['component']);
    });

    return [
        'moodle_version' => $CFG->version,
        'moodle_release' => $CFG->release,
        'php_version' => phpversion(),
        'export_timestamp' => date('c'),
        'plugins' => $plugins,
        'statistics' => [
            'total_plugins' => count($plugins),
            'additional_plugins' => count(array_filter($plugins, function ($p) {
                return !$p['is_core']; })),
            'core_plugins' => count(array_filter($plugins, function ($p) {
                return $p['is_core']; })),
        ],
    ];
}

// Print banner
if (!$options['quiet']) {
    cli_heading('edulution Full Export');
    mtrace('');
    mtrace('Configuration:');
    mtrace('  - Output: ' . $options['output']);
    mtrace('  - Full database: Yes');
    mtrace('  - Include moodledata: ' . ($options['include-moodledata'] ? 'Yes' : 'No'));
    mtrace('  - Compression level: ' . $options['compression']);
    if (!empty($options['exclude-tables'])) {
        mtrace('  - Excluded tables: ' . $options['exclude-tables']);
    }
    mtrace('');
    mtrace('Source Moodle:');
    mtrace('  - Site: ' . $SITE->fullname);
    mtrace('  - URL: ' . $CFG->wwwroot);
    mtrace('  - Version: ' . $CFG->release);
    mtrace('');

    mtrace('*** WARNING: Full database export contains sensitive data ***');
    mtrace('*** including password hashes. Handle with extreme care!  ***');
    mtrace('');
}

// Create temporary directory for export
$tempdir = $CFG->tempdir . '/edulution_export_' . uniqid();
if (!mkdir($tempdir, 0755, true)) {
    cli_error("Cannot create temporary directory: {$tempdir}");
}

$starttime = microtime(true);

// Initialize progress tracking.
local_edulution_update_progress(0, 'Starting export', 'Starting export process...');

try {
    // Phase 1: Create manifest (10%)
    if (!$options['quiet']) {
        mtrace('Phase 1: Creating manifest...');
    }
    local_edulution_update_progress(5, 'Creating manifest', '[Phase 1] Creating manifest...');

    // Phase 2: Export plugins (25%)
    if (!$options['quiet']) {
        mtrace('Phase 2: Exporting plugin information...');
    }
    local_edulution_update_progress(15, 'Exporting plugins', '[Phase 2] Exporting plugin information...');

    $pluginsdata = local_edulution_export_plugins();
    file_put_contents($tempdir . '/plugins.json', json_encode($pluginsdata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $additionalPlugins = $pluginsdata['statistics']['additional_plugins'];
    if (!$options['quiet']) {
        mtrace("  Plugins exported: {$pluginsdata['statistics']['total_plugins']} total, {$additionalPlugins} additional");
    }
    local_edulution_update_progress(25, 'Plugins exported', "Plugins exported: {$pluginsdata['statistics']['total_plugins']} total, {$additionalPlugins} additional");

    // Phase 3: Export database (40-70%)
    if (!$options['quiet']) {
        mtrace('Phase 3: Exporting database...');
    }
    local_edulution_update_progress(30, 'Exporting database', '[Phase 3] Exporting database (this may take a while)...');

    $mysqldump = local_edulution_find_mysqldump();
    if (!$mysqldump) {
        throw new Exception('mysqldump command not found. Please install MySQL client tools.');
    }

    // Build exclude arguments
    $excludeTables = ['sessions', 'cache_text', 'cache_flags', 'temp_log', 'upgrade_log', 'task_log'];
    if (!empty($options['exclude-tables'])) {
        $userExclude = array_map('trim', explode(',', $options['exclude-tables']));
        $excludeTables = array_merge($excludeTables, $userExclude);
    }

    $excludeArgs = '';
    foreach ($excludeTables as $table) {
        // Add prefix if not present
        if (strpos($table, $CFG->prefix) !== 0) {
            $table = $CFG->prefix . $table;
        }
        $fullTable = $CFG->dbname . '.' . $table;
        $excludeArgs .= ' --ignore-table=' . escapeshellarg($fullTable);
    }

    // Create temp config file for password
    $cnffile = tempnam(sys_get_temp_dir(), 'mysqldump');
    $cnfcontent = "[client]\n";
    $cnfcontent .= "host=" . ($CFG->dbhost ?? 'localhost') . "\n";
    if (!empty($CFG->dboptions['dbport'])) {
        $cnfcontent .= "port=" . $CFG->dboptions['dbport'] . "\n";
    }
    $cnfcontent .= "user={$CFG->dbuser}\n";
    $cnfcontent .= "password=" . str_replace('"', '\\"', $CFG->dbpass) . "\n";
    file_put_contents($cnffile, $cnfcontent);
    chmod($cnffile, 0600);

    $dumpfile = $tempdir . '/database.sql';
    $command = sprintf(
        '%s --defaults-extra-file=%s --single-transaction --routines --triggers %s %s > %s 2>&1',
        escapeshellcmd($mysqldump),
        escapeshellarg($cnffile),
        $excludeArgs,
        escapeshellarg($CFG->dbname),
        escapeshellarg($dumpfile)
    );

    $output = [];
    $returncode = 0;
    exec($command, $output, $returncode);
    unlink($cnffile);

    if ($returncode !== 0) {
        throw new Exception('mysqldump failed: ' . implode("\n", $output));
    }

    if (!file_exists($dumpfile) || filesize($dumpfile) === 0) {
        throw new Exception('mysqldump produced empty output');
    }

    // Count tables in dump
    $tablecount = 0;
    $handle = fopen($dumpfile, 'r');
    while (($line = fgets($handle)) !== false) {
        if (strpos($line, 'CREATE TABLE') !== false) {
            $tablecount++;
        }
    }
    fclose($handle);

    // Compress the dump
    if ($options['verbose']) {
        mtrace('  Compressing database dump...');
    }

    $gzfile = $dumpfile . '.gz';
    $fp = fopen($dumpfile, 'rb');
    $zp = gzopen($gzfile, 'wb' . $options['compression']);
    while (!feof($fp)) {
        gzwrite($zp, fread($fp, 524288));
    }
    fclose($fp);
    gzclose($zp);
    unlink($dumpfile);

    $dbsize = filesize($gzfile);

    if (!$options['quiet']) {
        mtrace('  Database exported: ' . local_edulution_format_size($dbsize) . " ({$tablecount} tables)");
    }
    local_edulution_update_progress(70, 'Database exported', "Database exported: " . local_edulution_format_size($dbsize) . " ({$tablecount} tables)");

    // Phase 4: Export moodledata (80%)
    $moodledataSize = 0;
    $moodledataFiles = 0;
    if ($options['include-moodledata']) {
        if (!$options['quiet']) {
            mtrace('Phase 4: Exporting moodledata...');
        }
        local_edulution_update_progress(72, 'Copying moodledata', '[Phase 4] Copying moodledata files...');

        $moodledataDir = $tempdir . '/moodledata';
        mkdir($moodledataDir, 0755, true);

        $dirs = ['filedir', 'lang', 'localcache'];
        foreach ($dirs as $dir) {
            $sourcePath = $CFG->dataroot . '/' . $dir;
            if (is_dir($sourcePath)) {
                if ($options['verbose']) {
                    mtrace("  Copying {$dir}...");
                }
                $destPath = $moodledataDir . '/' . $dir;
                $stats = local_edulution_copy_directory($sourcePath, $destPath);
                $moodledataSize += $stats['size'];
                $moodledataFiles += $stats['files'];

                if ($options['verbose']) {
                    mtrace("    " . local_edulution_format_size($stats['size']) . " ({$stats['files']} files)");
                }
            }
        }

        if (!$options['quiet']) {
            mtrace('  Moodledata exported: ' . local_edulution_format_size($moodledataSize) . " ({$moodledataFiles} files)");
        }
        local_edulution_update_progress(80, 'Moodledata exported', "Moodledata exported: " . local_edulution_format_size($moodledataSize) . " ({$moodledataFiles} files)");
    } else {
        if (!$options['quiet']) {
            mtrace('Phase 4: Skipping moodledata (not requested)');
        }
        local_edulution_update_progress(80, 'Skipping moodledata', 'Moodledata not requested, skipping...');
    }

    // Create config backup
    if (!$options['quiet']) {
        mtrace('Creating config backup...');
    }

    $configbackup = [
        'wwwroot' => $CFG->wwwroot,
        'dataroot' => $CFG->dataroot,
        'dirroot' => $CFG->dirroot,
        'dbtype' => $CFG->dbtype,
        'dbhost' => $CFG->dbhost,
        'dbname' => $CFG->dbname,
        'dbuser' => $CFG->dbuser,
        // Note: Password is NOT included for security
        'prefix' => $CFG->prefix,
        'dboptions' => $CFG->dboptions ?? [],
        'site_name' => $SITE->fullname,
        'site_shortname' => $SITE->shortname,
        'moodle_version' => $CFG->version,
        'moodle_release' => $CFG->release,
        'php_version' => PHP_VERSION,
        'export_timestamp' => date('c'),
    ];
    file_put_contents($tempdir . '/config_backup.json', json_encode($configbackup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Create manifest
    if (!$options['quiet']) {
        mtrace('Creating manifest...');
    }

    $manifest = [
        'export_version' => '1.0.0',
        'export_type' => 'full_database',
        'export_timestamp' => date('c'),
        'export_plugin' => 'local_edulution',

        'source_moodle' => [
            'version' => $CFG->version,
            'release' => $CFG->release,
            'wwwroot' => $CFG->wwwroot,
            'site_name' => $SITE->fullname,
            'dbtype' => $CFG->dbtype,
        ],

        'statistics' => [
            'database_size_bytes' => $dbsize,
            'database_size_formatted' => local_edulution_format_size($dbsize),
            'database_tables' => $tablecount,
            'moodledata_size_bytes' => $moodledataSize,
            'moodledata_size_formatted' => local_edulution_format_size($moodledataSize),
            'moodledata_files' => $moodledataFiles,
            'plugins_total' => $pluginsdata['statistics']['total_plugins'],
            'plugins_additional' => $additionalPlugins,
        ],

        'files' => [
            'database.sql.gz',
            'plugins.json',
            'config_backup.json',
        ],

        'security_warning' => 'This export contains sensitive data including password hashes. Handle with extreme care.',
    ];

    if ($options['include-moodledata']) {
        $manifest['files'][] = 'moodledata/';
    }

    file_put_contents($tempdir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Phase 5: Create ZIP archive (90%)
    if (!$options['quiet']) {
        mtrace('Phase 5: Creating ZIP archive...');
    }
    local_edulution_update_progress(85, 'Creating ZIP package', '[Phase 5] Creating ZIP package...');

    $zip = new ZipArchive();
    if ($zip->open($options['output'], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new Exception('Cannot create ZIP file: ' . $options['output']);
    }

    local_edulution_add_to_zip($zip, $tempdir, '');
    $zip->close();

    $zipsize = filesize($options['output']);
    local_edulution_update_progress(95, 'ZIP package created', "ZIP package created: " . local_edulution_format_size($zipsize));

    // Cleanup temp directory
    local_edulution_delete_directory($tempdir);

    // Print summary
    $duration = round(microtime(true) - $starttime, 2);

    if (!$options['quiet']) {
        mtrace('');
        cli_heading('Export Complete');
        mtrace('');
        mtrace('Output file: ' . $options['output']);
        mtrace('File size: ' . local_edulution_format_size($zipsize));
        mtrace('Duration: ' . $duration . ' seconds');
        mtrace('');
        mtrace('Contents:');
        mtrace('  - Database: ' . local_edulution_format_size($dbsize) . " ({$tablecount} tables)");
        if ($options['include-moodledata']) {
            mtrace('  - Moodledata: ' . local_edulution_format_size($moodledataSize) . " ({$moodledataFiles} files)");
        }
        mtrace('  - Plugins: ' . $pluginsdata['statistics']['total_plugins'] . " ({$additionalPlugins} additional)");
        mtrace('');
        mtrace('Import with:');
        mtrace('  php local/edulution/cli/full_import.php \\');
        mtrace('    --file=' . $options['output'] . ' \\');
        mtrace('    --wwwroot=https://new.example.com');
        mtrace('');
    }

    // Mark export as complete (100%)
    local_edulution_update_progress(100, 'Export complete', "Export completed successfully in {$duration} seconds!\nOutput: {$options['output']}\nSize: " . local_edulution_format_size($zipsize), true, true);

    exit(0);

} catch (Exception $e) {
    // Cleanup on error
    if (is_dir($tempdir)) {
        local_edulution_delete_directory($tempdir);
    }

    // Update progress file with error
    local_edulution_update_progress(0, 'Export failed', 'ERROR: ' . $e->getMessage(), true, false);

    cli_error('Export failed: ' . $e->getMessage());
}
