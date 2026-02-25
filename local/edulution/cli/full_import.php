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
 * Full database import CLI script for edulution.
 *
 * This script can run BEFORE Moodle is fully installed.
 * It imports a complete database dump and moodledata from an export ZIP.
 *
 * Usage:
 *   php full_import.php --file=/path/to/export.zip --wwwroot=https://new-site.com
 *   php full_import.php --file=/path/to/export.zip --wwwroot=https://new-site.com \
 *       --dbhost=db --dbname=moodle --dbuser=moodle --dbpass=secret
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This script runs STANDALONE - do not require Moodle config.
define('CLI_SCRIPT', true);
define('ABORT_AFTER_CONFIG', true);

// Define console colors for terminal output.
define('CLI_RED', "\033[31m");
define('CLI_GREEN', "\033[32m");
define('CLI_YELLOW', "\033[33m");
define('CLI_BLUE', "\033[34m");
define('CLI_CYAN', "\033[36m");
define('CLI_RESET', "\033[0m");
define('CLI_BOLD', "\033[1m");

// Check PHP version.
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    fwrite(STDERR, "Error: PHP 7.4 or higher is required.\n");
    exit(1);
}

// Check required extensions.
$requiredExtensions = ['mysqli', 'json', 'zip', 'zlib'];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "Error: PHP extension '{$ext}' is required.\n");
        exit(1);
    }
}

// Parse CLI arguments manually (no Moodle libraries available).
$shortopts = 'hqv';
$longopts = [
    'file:',
    'wwwroot:',
    'dataroot:',
    'dirroot:',
    'dbhost:',
    'dbname:',
    'dbuser:',
    'dbpass:',
    'dbport:',
    'dbprefix:',
    'adminpass:',
    'agree-license',
    'skip-plugins',
    'skip-upgrade',
    'skip-moodledata',
    'progress-file:',
    'dry-run',
    'no-color',
    'verbose',
    'quiet',
    'help',
];

$options = getopt($shortopts, $longopts);

// Print help.
if (isset($options['help']) || isset($options['h'])) {
    echo <<<EOF
Full database import for edulution migration.

This script imports a complete Moodle export package. It can run
BEFORE Moodle is installed and will:
1. Extract the export package
2. Import the database dump
3. Replace old URLs with new wwwroot
4. Copy moodledata files
5. Generate config.php
6. Run Moodle upgrade

USAGE:
    php full_import.php [options]

REQUIRED OPTIONS:
    --file=PATH         Path to export ZIP file (required)
    --wwwroot=URL       New site URL (required)

DATABASE OPTIONS:
    --dbhost=HOST       Database host (default: localhost or env)
    --dbname=NAME       Database name (default: moodle or env)
    --dbuser=USER       Database user (default: moodle or env)
    --dbpass=PASS       Database password (default: env)
    --dbport=PORT       Database port (default: 3306)
    --dbprefix=PREFIX   Table prefix (default: mdl_)

PATHS:
    --dataroot=PATH     Moodledata directory (default: /var/moodledata)
    --dirroot=PATH      Moodle installation directory (default: auto-detect)

ADDITIONAL OPTIONS:
    --adminpass=PASS    Set new admin password after import
    --agree-license     Skip confirmation prompt
    --skip-plugins      Skip plugin installation
    --skip-upgrade      Skip Moodle upgrade at end
    --skip-moodledata   Skip copying moodledata files
    --progress-file=PATH Write progress JSON to file for UI tracking
    --dry-run           Preview actions without making changes
    --no-color          Disable colored output
    -q, --quiet         Minimal output
    -v, --verbose       Verbose output
    -h, --help          Show this help message

ENVIRONMENT VARIABLES:
    MOODLE_DOCKER_DBHOST    Database host
    MOODLE_DOCKER_DBNAME    Database name
    MOODLE_DOCKER_DBUSER    Database user
    MOODLE_DOCKER_DBPASS    Database password

EXAMPLES:
    # Basic import
    php full_import.php --file=/tmp/export.zip \\
        --wwwroot=https://new-site.com

    # Import with explicit database settings
    php full_import.php --file=/tmp/export.zip \\
        --wwwroot=https://new-site.com \\
        --dbhost=db --dbname=moodle \\
        --dbuser=moodle --dbpass=secret

    # Preview what would be done
    php full_import.php --file=/tmp/export.zip \\
        --wwwroot=https://new-site.com --dry-run

WARNING:
    This script will DROP ALL EXISTING TABLES in the target database
    and CLEAR ALL FILES in the target dataroot. Make sure you have
    backups before proceeding!

EOF;
    exit(0);
}

// Configuration from CLI options and environment.
$config = [
    'file' => $options['file'] ?? '',
    'wwwroot' => rtrim($options['wwwroot'] ?? '', '/'),
    'dataroot' => rtrim($options['dataroot'] ?? '/var/moodledata', '/'),
    'dirroot' => $options['dirroot'] ?? dirname(dirname(dirname(__DIR__))),
    'dbhost' => $options['dbhost'] ?? getenv('MOODLE_DOCKER_DBHOST') ?: 'localhost',
    'dbname' => $options['dbname'] ?? getenv('MOODLE_DOCKER_DBNAME') ?: 'moodle',
    'dbuser' => $options['dbuser'] ?? getenv('MOODLE_DOCKER_DBUSER') ?: 'moodle',
    'dbpass' => $options['dbpass'] ?? getenv('MOODLE_DOCKER_DBPASS') ?: '',
    'dbport' => (int) ($options['dbport'] ?? getenv('MOODLE_DOCKER_DBPORT') ?: 3306),
    'dbprefix' => $options['dbprefix'] ?? 'mdl_',
    'adminpass' => $options['adminpass'] ?? null,
    'agree_license' => isset($options['agree-license']),
    'skip_plugins' => isset($options['skip-plugins']),
    'skip_upgrade' => isset($options['skip-upgrade']),
    'skip_moodledata' => isset($options['skip-moodledata']),
    'progress_file' => $options['progress-file'] ?? '',
    'dry_run' => isset($options['dry-run']),
    'no_color' => isset($options['no-color']),
    'verbose' => isset($options['verbose']) || isset($options['v']),
    'quiet' => isset($options['quiet']) || isset($options['q']),
];

// Determine if we should use colors.
$usecolor = !$config['no_color'] && function_exists('posix_isatty') && posix_isatty(STDOUT);

/**
 * Format a message with optional color.
 *
 * @param string $message The message.
 * @param string $color Color code.
 * @return string Formatted message.
 */
function fmt(string $message, string $color = ''): string
{
    global $usecolor;
    if (!$usecolor || empty($color)) {
        return $message;
    }
    return $color . $message . CLI_RESET;
}

/**
 * Output a message.
 *
 * @param string $message The message to output.
 * @param bool $error Whether this is an error.
 */
function output(string $message, bool $error = false): void
{
    global $config;
    if ($config['quiet'] && !$error) {
        return;
    }
    if ($error) {
        fwrite(STDERR, fmt("ERROR: ", CLI_RED) . $message . "\n");
    } else {
        echo $message . "\n";
    }
}

/**
 * Output verbose message.
 *
 * @param string $message The message.
 */
function verbose(string $message): void
{
    global $config;
    if ($config['verbose'] && !$config['quiet']) {
        echo fmt("  [*] ", CLI_CYAN) . $message . "\n";
    }
}

/**
 * Print a phase header.
 *
 * @param int $num Phase number.
 * @param string $name Phase name.
 */
function phase(int $num, string $name): void
{
    global $config;
    if ($config['quiet']) {
        return;
    }
    echo "\n" . fmt("[Phase {$num}] ", CLI_BLUE . CLI_BOLD) . fmt($name, CLI_BOLD) . "\n";
    echo fmt(str_repeat('-', 50), CLI_BLUE) . "\n";
}

/**
 * Print a success message.
 *
 * @param string $message The message.
 */
function success(string $message): void
{
    global $config;
    if ($config['quiet']) {
        return;
    }
    echo fmt("  [OK] ", CLI_GREEN) . $message . "\n";
}

/**
 * Print a warning message.
 *
 * @param string $message The message.
 */
function warning(string $message): void
{
    global $config;
    if ($config['quiet']) {
        return;
    }
    echo fmt("  [!] ", CLI_YELLOW) . $message . "\n";
}

/**
 * Update progress file for UI feedback.
 *
 * @param int $percent Progress percentage.
 * @param string $status Status text.
 * @param string $log Log message to append.
 * @param bool $complete Whether import is complete.
 */
function update_progress(int $percent, string $status, string $log = '', bool $complete = false): void
{
    global $config, $progresslog;

    if (empty($config['progress_file'])) {
        return;
    }

    if (!isset($progresslog)) {
        $progresslog = '';
    }

    if (!empty($log)) {
        $progresslog .= $log . "\n";
    }

    $data = [
        'progress' => $percent,
        'status' => $status,
        'log' => $progresslog,
        'complete' => $complete,
    ];

    file_put_contents($config['progress_file'], json_encode($data));
}

/**
 * Format file size.
 *
 * @param int $bytes Size in bytes.
 * @return string Formatted size.
 */
function format_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Delete directory recursively.
 *
 * @param string $dir Directory path.
 */
function delete_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            delete_directory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

/**
 * Copy directory recursively.
 *
 * @param string $src Source directory.
 * @param string $dst Destination directory.
 * @return array Copy statistics.
 */
function copy_directory(string $src, string $dst): array
{
    $stats = ['files' => 0, 'size' => 0];

    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $target = $dst . '/' . $iterator->getSubPathname();

        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0755, true);
            }
        } else {
            if (copy($item->getPathname(), $target)) {
                $stats['files']++;
                $stats['size'] += $item->getSize();
            }
        }
    }

    return $stats;
}

/**
 * Find mysql command.
 *
 * @return string|null Path to mysql command.
 */
function find_mysql(): ?string
{
    $paths = [
        '/usr/bin/mysql',
        '/usr/local/bin/mysql',
        '/usr/local/mysql/bin/mysql',
        '/opt/local/bin/mysql',
        '/opt/homebrew/bin/mysql',
        '/usr/bin/mariadb',
    ];

    foreach ($paths as $path) {
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }

    $output = [];
    exec('which mysql 2>/dev/null', $output);
    if (!empty($output[0]) && is_executable($output[0])) {
        return $output[0];
    }

    return null;
}

/**
 * Confirm with user.
 *
 * @param string $message The prompt message.
 * @param bool $default Default answer.
 * @return bool User's response.
 */
function confirm(string $message, bool $default = false): bool
{
    $prompt = $default ? '[Y/n]' : '[y/N]';
    echo "\n{$message} {$prompt}: ";

    $handle = fopen('php://stdin', 'r');
    $line = trim(fgets($handle));
    fclose($handle);

    if (empty($line)) {
        return $default;
    }

    return strtolower($line[0]) === 'y';
}

// =============================================================================
// MAIN SCRIPT
// =============================================================================

// Validate required options.
$errors = [];
if (empty($config['file'])) {
    $errors[] = "Export file path is required (--file=/path/to/export.zip)";
} elseif (!file_exists($config['file'])) {
    $errors[] = "Export file not found: {$config['file']}";
}

if (empty($config['wwwroot'])) {
    $errors[] = "Site URL is required (--wwwroot=https://example.com)";
} elseif (!filter_var($config['wwwroot'], FILTER_VALIDATE_URL)) {
    $errors[] = "Invalid URL: {$config['wwwroot']}";
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        output($error, true);
    }
    output("\nUse --help for usage information.", true);
    exit(1);
}

// Print header.
if (!$config['quiet']) {
    echo "\n";
    echo fmt("=", CLI_CYAN) . str_repeat(fmt("=", CLI_CYAN), 58) . "\n";
    echo fmt("  EDULUTION FULL IMPORT", CLI_CYAN . CLI_BOLD) . "\n";
    echo fmt("=", CLI_CYAN) . str_repeat(fmt("=", CLI_CYAN), 58) . "\n";
    echo "\n";

    echo "Configuration:\n";
    echo fmt("  Export file: ", CLI_BLUE) . $config['file'] . "\n";
    echo fmt("  File size: ", CLI_BLUE) . format_size(filesize($config['file'])) . "\n";
    echo fmt("  Target URL: ", CLI_BLUE) . $config['wwwroot'] . "\n";
    echo fmt("  Dataroot: ", CLI_BLUE) . $config['dataroot'] . "\n";
    echo fmt("  Database: ", CLI_BLUE) . $config['dbuser'] . "@" . $config['dbhost'] . "/" . $config['dbname'] . "\n";

    if ($config['dry_run']) {
        echo "\n";
        warning("DRY RUN MODE - No changes will be made");
    }
}

// Confirmation prompt.
if (!$config['agree_license'] && !$config['dry_run']) {
    echo "\n";
    echo fmt("WARNING:", CLI_YELLOW . CLI_BOLD) . "\n";
    echo "This script will:\n";
    echo "  - DROP ALL EXISTING TABLES in database '{$config['dbname']}'\n";
    echo "  - CLEAR ALL FILES in dataroot '{$config['dataroot']}'\n";
    echo "  - OVERWRITE config.php\n";
    echo "\nMake sure you have backups!\n";

    if (!confirm("Do you want to continue?", false)) {
        output("Import cancelled.");
        exit(0);
    }
}

// Initialize progress tracking.
$progresslog = '';
update_progress(5, 'starting', 'Starting import process...');

// Start import.
$starttime = microtime(true);

// Create temporary directory.
$tempdir = sys_get_temp_dir() . '/edulution_import_' . uniqid();
if (!$config['dry_run']) {
    if (!mkdir($tempdir, 0755, true)) {
        output("Cannot create temporary directory: {$tempdir}", true);
        exit(1);
    }
}
verbose("Temporary directory: {$tempdir}");

try {
    // =========================================================================
    // PHASE 1: Extract package
    // =========================================================================
    phase(1, "Extracting export package");
    update_progress(10, 'extracting', '[Phase 1] Extracting export package...');

    if (!$config['dry_run']) {
        $zip = new ZipArchive();
        if ($zip->open($config['file']) !== true) {
            throw new Exception("Cannot open ZIP file: {$config['file']}");
        }

        verbose("Extracting " . $zip->numFiles . " files...");
        if (!$zip->extractTo($tempdir)) {
            $zip->close();
            throw new Exception("Failed to extract ZIP file");
        }
        $zip->close();
        success("Package extracted successfully");
    } else {
        success("[DRY RUN] Would extract package");
    }

    // =========================================================================
    // PHASE 2: Validate manifest
    // =========================================================================
    phase(2, "Validating manifest");
    update_progress(20, 'validating', '[Phase 2] Validating manifest...');

    $manifestpath = $tempdir . '/manifest.json';
    $manifest = [];
    $oldwwwroot = '';

    if (!$config['dry_run']) {
        if (!file_exists($manifestpath)) {
            throw new Exception("manifest.json not found in export package");
        }

        $content = file_get_contents($manifestpath);
        $manifest = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid manifest.json: " . json_last_error_msg());
        }

        $oldwwwroot = $manifest['source_moodle']['wwwroot'] ?? '';
        $moodleversion = $manifest['source_moodle']['release'] ?? 'unknown';
        $exporttime = $manifest['export_time'] ?? 'unknown';

        verbose("Source Moodle: {$moodleversion}");
        verbose("Source URL: {$oldwwwroot}");
        verbose("Export time: {$exporttime}");
        success("Manifest validated");
    } else {
        success("[DRY RUN] Would validate manifest");
    }

    // =========================================================================
    // PHASE 3: Database import
    // =========================================================================
    phase(3, "Importing database");
    update_progress(30, 'importing_database', '[Phase 3] Importing database (this may take a while)...');

    if (!$config['dry_run']) {
        // Connect to database.
        verbose("Connecting to database...");
        $db = @new mysqli(
            $config['dbhost'],
            $config['dbuser'],
            $config['dbpass'],
            $config['dbname'],
            $config['dbport']
        );

        if ($db->connect_error) {
            throw new Exception("Database connection failed: " . $db->connect_error);
        }
        $db->set_charset('utf8mb4');
        success("Database connection established");

        // Drop existing tables.
        verbose("Dropping existing tables...");
        $db->query("SET FOREIGN_KEY_CHECKS = 0");

        $result = $db->query("SHOW TABLES LIKE '{$config['dbprefix']}%'");
        $tablecount = 0;
        while ($row = $result->fetch_array()) {
            $table = $row[0];
            $db->query("DROP TABLE IF EXISTS `{$table}`");
            $tablecount++;
        }

        $db->query("SET FOREIGN_KEY_CHECKS = 1");
        success("Dropped {$tablecount} existing tables");

        // Find database dump.
        $dumpfile = $tempdir . '/database.sql.gz';
        if (!file_exists($dumpfile)) {
            $dumpfile = $tempdir . '/database.sql';
        }

        if (!file_exists($dumpfile)) {
            throw new Exception("Database dump not found in export");
        }

        $isgzipped = substr($dumpfile, -3) === '.gz';
        verbose("Importing database dump (" . ($isgzipped ? "compressed" : "uncompressed") . ")...");

        // Try using mysql command.
        $mysql = find_mysql();
        $importedviashell = false;

        if ($mysql) {
            verbose("Using mysql command for import...");

            $cnffile = tempnam(sys_get_temp_dir(), 'mysql');
            $cnfcontent = "[client]\n";
            $cnfcontent .= "host={$config['dbhost']}\n";
            $cnfcontent .= "port={$config['dbport']}\n";
            $cnfcontent .= "user={$config['dbuser']}\n";
            $cnfcontent .= "password=" . str_replace('"', '\\"', $config['dbpass']) . "\n";
            file_put_contents($cnffile, $cnfcontent);
            chmod($cnffile, 0600);

            if ($isgzipped) {
                $command = "gunzip -c " . escapeshellarg($dumpfile) .
                    " | {$mysql} --defaults-extra-file=" . escapeshellarg($cnffile) .
                    " " . escapeshellarg($config['dbname']) . " 2>&1";
            } else {
                $command = "{$mysql} --defaults-extra-file=" . escapeshellarg($cnffile) .
                    " " . escapeshellarg($config['dbname']) .
                    " < " . escapeshellarg($dumpfile) . " 2>&1";
            }

            $output_lines = [];
            $returncode = 0;
            exec($command, $output_lines, $returncode);

            unlink($cnffile);

            if ($returncode === 0) {
                $importedviashell = true;
            } else {
                verbose("Shell import failed, using PHP fallback...");
            }
        }

        // Fallback to PHP-based import.
        if (!$importedviashell) {
            verbose("Using PHP for database import...");

            if ($isgzipped) {
                $handle = gzopen($dumpfile, 'r');
            } else {
                $handle = fopen($dumpfile, 'r');
            }

            if (!$handle) {
                throw new Exception("Cannot open database dump");
            }

            $db->query("SET FOREIGN_KEY_CHECKS = 0");
            $db->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

            $query = '';
            $linenum = 0;

            while (!($isgzipped ? gzeof($handle) : feof($handle))) {
                $line = $isgzipped ? gzgets($handle) : fgets($handle);
                $linenum++;

                $trimmed = trim($line);
                if (empty($trimmed) || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
                    continue;
                }

                $query .= $line;

                if (preg_match('/;\s*$/', $trimmed)) {
                    if (!$db->query($query)) {
                        if ($config['verbose']) {
                            warning("SQL warning at line {$linenum}: " . $db->error);
                        }
                    }
                    $query = '';
                }
            }

            if ($isgzipped) {
                gzclose($handle);
            } else {
                fclose($handle);
            }

            $db->query("SET FOREIGN_KEY_CHECKS = 1");
        }

        // Count imported tables.
        $result = $db->query("SHOW TABLES LIKE '{$config['dbprefix']}%'");
        $importedtables = $result->num_rows;
        success("Imported {$importedtables} tables");

        // Replace URLs.
        if (!empty($oldwwwroot) && $oldwwwroot !== $config['wwwroot']) {
            verbose("Replacing URLs: {$oldwwwroot} -> {$config['wwwroot']}");

            $replacements = 0;
            $urlcolumns = [
                'config' => ['value'],
                'config_plugins' => ['value'],
                'course' => ['summary'],
                'course_sections' => ['summary'],
                'page' => ['content'],
                'url' => ['externalurl'],
                'label' => ['intro'],
                'forum_posts' => ['message'],
                'assign' => ['intro'],
                'quiz' => ['intro'],
            ];

            foreach ($urlcolumns as $table => $columns) {
                $fulltable = $config['dbprefix'] . $table;

                $check = $db->query("SHOW TABLES LIKE '{$fulltable}'");
                if ($check->num_rows === 0) {
                    continue;
                }

                foreach ($columns as $column) {
                    $colcheck = $db->query("SHOW COLUMNS FROM `{$fulltable}` LIKE '{$column}'");
                    if ($colcheck->num_rows === 0) {
                        continue;
                    }

                    $escaped_old = $db->real_escape_string($oldwwwroot);
                    $escaped_new = $db->real_escape_string($config['wwwroot']);

                    $sql = "UPDATE `{$fulltable}` SET `{$column}` = REPLACE(`{$column}`, '{$escaped_old}', '{$escaped_new}') WHERE `{$column}` LIKE '%{$escaped_old}%'";
                    $db->query($sql);
                    $replacements += $db->affected_rows;
                }
            }

            success("Replaced URLs in {$replacements} fields");
        }

        // Update config table.
        verbose("Updating configuration...");
        $configtable = $config['dbprefix'] . 'config';

        $escaped = $db->real_escape_string($config['wwwroot']);
        $db->query("UPDATE `{$configtable}` SET value = '{$escaped}' WHERE name = 'wwwroot'");

        $escaped = $db->real_escape_string($config['dataroot']);
        $db->query("UPDATE `{$configtable}` SET value = '{$escaped}' WHERE name = 'dataroot'");

        success("Configuration updated");

        // Reset admin password if specified.
        if (!empty($config['adminpass'])) {
            verbose("Resetting admin password...");
            $hash = password_hash($config['adminpass'], PASSWORD_BCRYPT);
            $usertable = $config['dbprefix'] . 'user';
            $escaped = $db->real_escape_string($hash);
            $db->query("UPDATE `{$usertable}` SET password = '{$escaped}' WHERE username = 'admin'");
            success("Admin password updated");
        }

        $db->close();
    } else {
        success("[DRY RUN] Would import database");
    }

    // =========================================================================
    // PHASE 4: Copy moodledata
    // =========================================================================
    phase(4, "Copying moodledata");
    update_progress(60, 'copying_files', '[Phase 4] Copying moodledata files...');

    $moodledatadir = $tempdir . '/moodledata';
    if ($config['skip_moodledata']) {
        success("Skipping moodledata (--skip-moodledata specified)");
        update_progress(70, 'skipped_moodledata', 'Moodledata copy skipped.');
    } else if (is_dir($moodledatadir) && !$config['dry_run']) {
        // Backup .htaccess if exists.
        $htaccesspath = $config['dataroot'] . '/.htaccess';
        $htaccessbackup = null;
        if (file_exists($htaccesspath)) {
            $htaccessbackup = file_get_contents($htaccesspath);
        }

        // Ensure dataroot exists.
        if (!is_dir($config['dataroot'])) {
            mkdir($config['dataroot'], 0755, true);
        }

        verbose("Copying files to {$config['dataroot']}...");
        $stats = copy_directory($moodledatadir, $config['dataroot']);
        success("Copied {$stats['files']} files (" . format_size($stats['size']) . ")");

        // Restore or create .htaccess.
        if ($htaccessbackup !== null) {
            file_put_contents($htaccesspath, $htaccessbackup);
        } elseif (!file_exists($htaccesspath)) {
            file_put_contents($htaccesspath, "order deny,allow\ndeny from all\n");
        }
        verbose(".htaccess configured");
    } elseif ($config['dry_run']) {
        success("[DRY RUN] Would copy moodledata");
    } else {
        warning("No moodledata in export, skipping");
    }

    // =========================================================================
    // PHASE 5: Generate config.php
    // =========================================================================
    phase(5, "Generating config.php");
    update_progress(80, 'generating_config', '[Phase 5] Generating config.php...');

    $configpath = $config['dirroot'] . '/config.php';

    if (!$config['dry_run']) {
        $configcontent = "<?php  // Moodle configuration file\n\n";
        $configcontent .= "unset(\$CFG);\n";
        $configcontent .= "global \$CFG;\n";
        $configcontent .= "\$CFG = new stdClass();\n\n";

        $configcontent .= "\$CFG->dbtype    = 'mariadb';\n";
        $configcontent .= "\$CFG->dblibrary = 'native';\n";
        $configcontent .= "\$CFG->dbhost    = " . var_export($config['dbhost'], true) . ";\n";
        $configcontent .= "\$CFG->dbname    = " . var_export($config['dbname'], true) . ";\n";
        $configcontent .= "\$CFG->dbuser    = " . var_export($config['dbuser'], true) . ";\n";
        $configcontent .= "\$CFG->dbpass    = " . var_export($config['dbpass'], true) . ";\n";
        $configcontent .= "\$CFG->prefix    = " . var_export($config['dbprefix'], true) . ";\n";
        $configcontent .= "\$CFG->dboptions = array(\n";
        $configcontent .= "    'dbport' => {$config['dbport']},\n";
        $configcontent .= "    'dbcollation' => 'utf8mb4_unicode_ci',\n";
        $configcontent .= ");\n\n";

        $configcontent .= "\$CFG->wwwroot   = " . var_export($config['wwwroot'], true) . ";\n";
        $configcontent .= "\$CFG->dataroot  = " . var_export($config['dataroot'], true) . ";\n";
        $configcontent .= "\$CFG->admin     = 'admin';\n\n";

        $configcontent .= "\$CFG->directorypermissions = 0755;\n\n";

        $configcontent .= "require_once(__DIR__ . '/lib/setup.php');\n\n";

        $configcontent .= "// There is no php closing tag in this file,\n";
        $configcontent .= "// it is intentional because it prevents trailing whitespace problems!\n";

        file_put_contents($configpath, $configcontent);
        success("config.php generated");
    } else {
        success("[DRY RUN] Would generate config.php");
    }

    // =========================================================================
    // PHASE 6: Run upgrade
    // =========================================================================
    phase(6, "Running Moodle upgrade");
    update_progress(90, 'upgrading', '[Phase 6] Running Moodle upgrade...');

    if (!$config['skip_upgrade'] && !$config['dry_run']) {
        $upgradescript = $config['dirroot'] . '/admin/cli/upgrade.php';
        if (file_exists($upgradescript)) {
            verbose("Running upgrade.php...");
            $command = 'php ' . escapeshellarg($upgradescript) . ' --non-interactive 2>&1';
            $output_lines = [];
            $returncode = 0;
            exec($command, $output_lines, $returncode);

            if ($returncode === 0) {
                success("Upgrade completed successfully");
            } else {
                warning("Upgrade returned code {$returncode}");
                if ($config['verbose']) {
                    foreach ($output_lines as $line) {
                        echo "      {$line}\n";
                    }
                }
            }
        } else {
            warning("Upgrade script not found, skipping");
        }

        // Purge caches.
        $purgescript = $config['dirroot'] . '/admin/cli/purge_caches.php';
        if (file_exists($purgescript)) {
            verbose("Purging caches...");
            exec('php ' . escapeshellarg($purgescript) . ' 2>&1');
            success("Caches purged");
        }

    } elseif ($config['dry_run']) {
        success("[DRY RUN] Would run Moodle upgrade");
    } else {
        success("Skipping upgrade (--skip-upgrade specified)");
    }

    // =========================================================================
    // CLEANUP
    // =========================================================================
    if (!$config['dry_run'] && is_dir($tempdir)) {
        verbose("Cleaning up temporary files...");
        delete_directory($tempdir);
    }

    // =========================================================================
    // SUMMARY
    // =========================================================================
    $duration = round(microtime(true) - $starttime, 2);
    update_progress(100, 'complete', 'Import completed successfully in ' . $duration . ' seconds!', true);

    if (!$config['quiet']) {
        echo "\n";
        echo fmt("=", CLI_GREEN) . str_repeat(fmt("=", CLI_GREEN), 58) . "\n";
        echo fmt("  IMPORT COMPLETED SUCCESSFULLY!", CLI_GREEN . CLI_BOLD) . "\n";
        echo fmt("=", CLI_GREEN) . str_repeat(fmt("=", CLI_GREEN), 58) . "\n";
        echo "\n";
        echo fmt("  Site URL: ", CLI_BLUE) . $config['wwwroot'] . "\n";
        echo fmt("  Dataroot: ", CLI_BLUE) . $config['dataroot'] . "\n";
        echo fmt("  Duration: ", CLI_BLUE) . $duration . " seconds\n";
        echo "\n";
        echo "  Next steps:\n";
        echo "    1. Visit " . fmt($config['wwwroot'], CLI_CYAN) . " to verify the site\n";
        echo "    2. Login as admin and check configuration\n";
        echo "    3. Review Site administration > Notifications\n";
        echo "    4. Check Site administration > Plugins > Plugin overview\n";
        echo "\n";
    }

} catch (Exception $e) {
    // Cleanup on error.
    if (!$config['dry_run'] && isset($tempdir) && is_dir($tempdir)) {
        delete_directory($tempdir);
    }

    update_progress(0, 'failed', 'ERROR: ' . $e->getMessage(), true);
    output("Import failed: " . $e->getMessage(), true);
    exit(1);
}

exit(0);
