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
 * Keycloak synchronization CLI script for edulution.
 *
 * Synchronizes users, courses, and enrollments between Keycloak and Moodle.
 *
 * Usage:
 *   php sync.php                     # Full sync (users, courses, enrollments)
 *   php sync.php --dry-run           # Preview changes without applying
 *   php sync.php --users-only        # Sync users only
 *   php sync.php --courses-only      # Sync courses only
 *   php sync.php --enrollments-only  # Sync enrollments only
 *   php sync.php --verbose           # Detailed output
 *   php sync.php --force             # Skip confirmations
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_edulution\sync\keycloak_client;
use local_edulution\sync\group_classifier;
use local_edulution\sync\sync_manager;

// CLI options.
list($options, $unrecognized) = cli_get_params([
    'help' => false,
    'dry-run' => false,
    'preview' => false,  // Alias for dry-run.
    'full' => false,
    'users-only' => false,
    'courses-only' => false,
    'enrollments-only' => false,
    'groups-only' => false,  // Legacy alias for courses.
    'verbose' => false,
    'quiet' => false,
    'force' => false,
    'test-connection' => false,
    'patterns' => false,
    'url' => '',
    'realm' => '',
    'client-id' => '',
    'client-secret' => '',
], [
    'h' => 'help',
    'd' => 'dry-run',
    'p' => 'preview',
    'f' => 'force',
    'u' => 'users-only',
    'c' => 'courses-only',
    'e' => 'enrollments-only',
    'g' => 'groups-only',
    'v' => 'verbose',
    'q' => 'quiet',
    't' => 'test-connection',
]);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

// Print help.
if ($options['help']) {
    $help = <<<EOF
Keycloak synchronization CLI for edulution.

Synchronizes users, courses, and enrollments between Keycloak and Moodle.
Creates courses based on Keycloak groups and syncs user enrollments.

Options:
  -h, --help              Print this help message
  -d, --dry-run           Preview changes without applying them
  -p, --preview           Alias for --dry-run
  -f, --force             Skip confirmation prompts
  -u, --users-only        Sync users only
  -c, --courses-only      Sync courses only
  -e, --enrollments-only  Sync enrollments only
  -v, --verbose           Show detailed progress
  -q, --quiet             Suppress non-error output
  -t, --test-connection   Test Keycloak connection only
  --patterns              Show current group pattern configuration

Connection Options (override config):
  --url=URL               Keycloak server URL
  --realm=REALM           Keycloak realm name
  --client-id=ID          OAuth2 client ID
  --client-secret=SECRET  OAuth2 client secret

Examples:
  # Full sync with progress output
  php sync.php --verbose

  # Preview what would change
  php sync.php --dry-run

  # Sync only users
  php sync.php --users-only --verbose

  # Test connection to Keycloak
  php sync.php --test-connection

  # Show group patterns
  php sync.php --patterns

Configuration:
  Keycloak settings are configured in:
  Site administration > Plugins > Local plugins > edulution

EOF;
    echo $help;
    exit(0);
}

// Handle --preview as alias for --dry-run.
if ($options['preview']) {
    $options['dry-run'] = true;
}

// Handle --groups-only as alias for --courses-only (legacy).
if ($options['groups-only']) {
    $options['courses-only'] = true;
}

// Determine verbosity.
$verbose = $options['verbose'];
$quiet = $options['quiet'];

/**
 * Output a message (respects quiet mode).
 *
 * @param string $message The message to output.
 * @param bool $error Whether this is an error.
 */
function sync_output(string $message, bool $error = false): void
{
    global $quiet;
    if (!$quiet || $error) {
        if ($error) {
            cli_problem($message);
        } else {
            mtrace($message);
        }
    }
}

/**
 * Output verbose message.
 *
 * @param string $message The message to output.
 */
function sync_verbose(string $message): void
{
    global $verbose, $quiet;
    if ($verbose && !$quiet) {
        mtrace("  " . $message);
    }
}

/**
 * Format duration in seconds.
 *
 * @param int $seconds Duration in seconds.
 * @return string Formatted duration.
 */
function format_duration(int $seconds): string
{
    if ($seconds < 60) {
        return "{$seconds}s";
    }
    $minutes = floor($seconds / 60);
    $secs = $seconds % 60;
    return "{$minutes}m {$secs}s";
}

// Get Keycloak configuration (CLI options > env vars > database).
$url = $options['url'] ?: local_edulution_get_config('keycloak_url');
$realm = $options['realm'] ?: local_edulution_get_config('keycloak_realm', 'master');
$client_id = $options['client-id'] ?: local_edulution_get_config('keycloak_client_id');
$client_secret = $options['client-secret'] ?: local_edulution_get_config('keycloak_client_secret');

// Validate configuration.
$missing = [];
if (empty($url)) {
    $missing[] = 'Keycloak URL';
}
if (empty($realm)) {
    $missing[] = 'Keycloak Realm';
}
if (empty($client_id)) {
    $missing[] = 'Client ID';
}
if (empty($client_secret)) {
    $missing[] = 'Client Secret';
}

// Create Keycloak client if configured.
$client = null;
$classifier = null;
if (empty($missing)) {
    try {
        $client = new keycloak_client($url, $realm, $client_id, $client_secret);
        $classifier = new group_classifier();
    } catch (\Exception $e) {
        cli_error("Failed to create Keycloak client: " . $e->getMessage());
    }
}

// Test connection only.
if ($options['test-connection']) {
    sync_output("Testing Keycloak connection...\n");

    sync_output("Configuration:");
    sync_output("  URL: " . ($url ?: '(not set)'));
    sync_output("  Realm: " . ($realm ?: '(not set)'));
    sync_output("  Client ID: " . ($client_id ?: '(not set)'));
    sync_output("  Client Secret: " . (!empty($client_secret) ? '(set)' : '(not set)'));
    sync_output("");

    if (!empty($missing)) {
        cli_error("Keycloak is not configured. Missing: " . implode(', ', $missing) . "\n" .
            "Configure in: Site administration > Plugins > Local plugins > edulution");
    }

    $result = $client->test_connection();

    if ($result['success']) {
        sync_output("[OK] Connection successful!");
        if (!empty($result['realm'])) {
            sync_output("Connected to realm: " . $result['realm']);
        }
        exit(0);
    } else {
        cli_error("Connection failed: " . ($result['message'] ?? 'Unknown error'));
    }
}

// Show patterns mode.
if ($options['patterns']) {
    sync_output("Current Pattern Configuration\n");
    sync_output(str_repeat("=", 50));

    $config = $classifier->get_config_summary();

    sync_output("Source: " . $config['source']);
    if (!empty($config['path'])) {
        sync_output("Path:   " . $config['path']);
    }
    sync_output("");
    sync_output("Categories:");

    foreach ($config['categories'] as $cat) {
        sync_output("  " . $cat['name'] . " (" . $cat['type'] . ")");
        sync_output("    Patterns: " . implode(', ', $cat['patterns']));
        sync_output("    Ignore:   " . ($cat['ignore'] ? 'Yes' : 'No'));
        sync_output("");
    }

    // Test against Keycloak groups if connected.
    if (!empty($missing)) {
        sync_output("Cannot test against Keycloak groups - not configured.");
        exit(0);
    }

    sync_output("Testing against Keycloak groups...");

    try {
        $groups = $client->get_all_groups();
        $results = $classifier->test_patterns(array_column($groups, 'name'));

        sync_output("");
        sync_output("Results:");
        sync_output("  Class groups:   " . $results['counts'][group_classifier::TYPE_CLASS]);
        sync_output("  Teacher groups: " . $results['counts'][group_classifier::TYPE_TEACHER]);
        sync_output("  Project groups: " . $results['counts'][group_classifier::TYPE_PROJECT]);
        sync_output("  Ignored:        " . $results['counts'][group_classifier::TYPE_IGNORE]);
        sync_output("  Unknown:        " . $results['counts'][group_classifier::TYPE_UNKNOWN]);

        if ($verbose && !empty($results[group_classifier::TYPE_UNKNOWN])) {
            sync_output("\nUnknown groups (first 20):");
            foreach (array_slice($results[group_classifier::TYPE_UNKNOWN], 0, 20) as $name) {
                sync_output("  - " . $name);
            }
        }
    } catch (\Exception $e) {
        sync_output("[ERROR] " . $e->getMessage(), true);
    }

    exit(0);
}

// Check configuration.
if (!empty($missing)) {
    cli_error("Keycloak is not configured. Missing: " . implode(', ', $missing) . "\n" .
        "Use --test-connection to check settings.\n" .
        "Configure in: Site administration > Plugins > Local plugins > edulution\n" .
        "Or use CLI options: --url, --realm, --client-id, --client-secret");
}

// Determine sync mode.
if ($options['users-only']) {
    $mode = sync_manager::MODE_USERS;
} elseif ($options['courses-only']) {
    $mode = sync_manager::MODE_COURSES;
} elseif ($options['enrollments-only']) {
    $mode = sync_manager::MODE_ENROLLMENTS;
} else {
    $mode = sync_manager::MODE_FULL;
}

// Start sync banner.
sync_output("Keycloak Sync");
sync_output(str_repeat("=", 50));
sync_output("");

// Show configuration.
sync_output("Configuration:");
sync_output("  Keycloak URL: " . $url);
sync_output("  Realm:        " . $realm);
sync_output("  Mode:         " . $mode);
sync_output("  Dry run:      " . ($options['dry-run'] ? 'Yes' : 'No'));
sync_output("");

// Confirmation prompt (unless forced or dry-run).
if (!$options['force'] && !$options['dry-run'] && !$quiet) {
    sync_output("This will synchronize data from Keycloak to Moodle.");
    sync_output("");

    $confirm = cli_input('Continue? (y/N)', 'n');

    if (strtolower($confirm) !== 'y') {
        sync_output("Aborted.");
        exit(0);
    }

    sync_output("");
}

// Create sync manager and run.
try {
    $manager = new sync_manager($client, $classifier);

    $manager->set_dry_run($options['dry-run']);
    $manager->set_verbose($verbose);
    $manager->set_mode($mode);

    // Run the sync.
    $report = $manager->run();

    // Output summary.
    if (!$quiet) {
        sync_output("");
        sync_output($report->get_text_summary());
    }

    // Save report (not in dry-run mode).
    if (!$options['dry-run']) {
        $report_id = $report->save();
        if ($report_id && !$quiet) {
            sync_output("Report saved with ID: {$report_id}");
        }
    }

    // Exit with appropriate code.
    exit($report->is_success() ? 0 : 1);

} catch (\Exception $e) {
    sync_output("", true);
    sync_output("[ERROR] Sync failed: " . $e->getMessage(), true);

    if ($verbose) {
        sync_output("", true);
        sync_output("Stack trace:", true);
        sync_output($e->getTraceAsString(), true);
    }

    exit(1);
}
