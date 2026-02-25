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
 * Scheduled task for Keycloak user and course synchronization.
 *
 * Runs daily (configurable) and:
 * - Synchronizes users from Keycloak
 * - Synchronizes courses from Keycloak groups
 * - Synchronizes enrollments
 * - Emails report to administrators
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\task;

defined('MOODLE_INTERNAL') || die();

use local_edulution\sync\keycloak_client;
use local_edulution\sync\group_classifier;
use local_edulution\sync\phased_sync;

/**
 * Scheduled task for Keycloak synchronization.
 */
class sync_users_task extends \core\task\scheduled_task
{

    /**
     * Get task name.
     *
     * @return string Task name.
     */
    public function get_name(): string
    {
        return get_string('task_sync_users', 'local_edulution');
    }

    /**
     * Execute the task.
     */
    public function execute(): void
    {
        global $CFG;

        mtrace('========================================');
        mtrace('  Keycloak Sync Task Starting');
        mtrace('========================================');
        mtrace('');

        // Check if sync is enabled (environment variables take precedence).
        $enabled = \local_edulution_get_config('keycloak_sync_enabled');
        if (!$enabled) {
            mtrace('Keycloak sync is disabled. Skipping.');
            return;
        }

        // Check if Keycloak is configured (environment variables take precedence).
        $url = \local_edulution_get_config('keycloak_url');
        $realm = \local_edulution_get_config('keycloak_realm', 'master');
        $client_id = \local_edulution_get_config('keycloak_client_id');
        $client_secret = \local_edulution_get_config('keycloak_client_secret');

        if (empty($url) || empty($realm) || empty($client_id) || empty($client_secret)) {
            mtrace('Keycloak is not configured. Please configure it in Site Administration or via environment variables.');
            mtrace('Missing: ' . implode(', ', array_filter([
                empty($url) ? 'URL (EDULUTION_KEYCLOAK_URL)' : null,
                empty($realm) ? 'Realm (EDULUTION_KEYCLOAK_REALM)' : null,
                empty($client_id) ? 'Client ID (EDULUTION_KEYCLOAK_CLIENT_ID)' : null,
                empty($client_secret) ? 'Client Secret (EDULUTION_KEYCLOAK_CLIENT_SECRET)' : null,
            ])));
            return;
        }

        // Create sync using phased_sync.
        try {
            $client = new keycloak_client($url, $realm, $client_id, $client_secret);
            $classifier = new group_classifier();

            // Test connection first.
            $connection = $client->test_connection();
            if (!$connection['success']) {
                throw new \Exception('Keycloak connection failed: ' . ($connection['message'] ?? 'Unknown error'));
            }
            mtrace('Connected to Keycloak realm: ' . $realm);

            // Create phased sync.
            $sync = new phased_sync($client, $classifier);

            // Enable verbose logging for CLI/cron.
            if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
                $sync->set_verbose(true);
            }

            // Run the sync.
            mtrace('Starting synchronization...');
            mtrace('');

            $result = $sync->run();

            // Output summary.
            mtrace('');
            mtrace('========================================');
            mtrace('  Sync Complete');
            mtrace('========================================');
            mtrace('');

            $stats = $result['stats'];

            // Save last sync time and stats for dashboard.
            set_config('last_sync_time', time(), 'local_edulution');
            set_config('last_sync_stats', json_encode($stats), 'local_edulution');

            mtrace("Users:       {$stats['users_created']} created, {$stats['users_updated']} updated, {$stats['users_skipped']} skipped");
            mtrace("Courses:     {$stats['courses_created']} created, {$stats['courses_skipped']} skipped");
            mtrace("Enrollments: {$stats['enrollments_created']} created, {$stats['enrollments_skipped']} skipped");
            mtrace("Duration:    {$stats['duration']} seconds");

            if (!empty($result['errors'])) {
                mtrace("Errors:      " . count($result['errors']));
                foreach (array_slice($result['errors'], 0, 10) as $error) {
                    mtrace("  - " . ($error['message'] ?? json_encode($error)));
                }
            }

            // Email report to admins if configured.
            $email_enabled = get_config('local_edulution', 'sync_email_report');
            if ($email_enabled) {
                $this->send_summary_email($stats, $result['errors']);
            }

            // Log completion.
            mtrace('');
            mtrace('Sync task finished at ' . date('Y-m-d H:i:s'));

        } catch (\Exception $e) {
            mtrace('');
            mtrace('[ERROR] Sync failed: ' . $e->getMessage());
            mtrace('');
            mtrace('Stack trace:');
            mtrace($e->getTraceAsString());

            // Try to send error notification.
            $this->send_error_notification($e);
        }
    }

    /**
     * Send sync summary via email to administrators.
     *
     * @param array $stats Sync statistics.
     * @param array $errors Sync errors.
     */
    protected function send_summary_email(array $stats, array $errors): void
    {
        global $CFG;

        mtrace('');
        mtrace('Sending report email to administrators...');

        $admins = get_admins();
        $site_name = $CFG->shortname ?? 'Moodle';

        $subject = "edulution Sync Report - {$site_name}";

        $message = "Keycloak Synchronization Report\n";
        $message .= "================================\n\n";
        $message .= "Site: {$CFG->wwwroot}\n";
        $message .= "Time: " . date('Y-m-d H:i:s') . "\n\n";
        $message .= "Results:\n";
        $message .= "- Users created: {$stats['users_created']}\n";
        $message .= "- Users updated: {$stats['users_updated']}\n";
        $message .= "- Users skipped: {$stats['users_skipped']}\n";
        $message .= "- Courses created: {$stats['courses_created']}\n";
        $message .= "- Enrollments created: {$stats['enrollments_created']}\n";
        $message .= "- Duration: {$stats['duration']} seconds\n";

        if (!empty($errors)) {
            $message .= "\nErrors (" . count($errors) . "):\n";
            foreach (array_slice($errors, 0, 20) as $error) {
                $message .= "- " . ($error['message'] ?? json_encode($error)) . "\n";
            }
        }

        $sent = 0;
        foreach ($admins as $admin) {
            email_to_user(
                $admin,
                \core_user::get_noreply_user(),
                $subject,
                $message
            );
            $sent++;
        }

        mtrace("Sent report email to {$sent} administrator(s)");
    }

    /**
     * Send error notification to administrators.
     *
     * @param \Exception $exception The exception that occurred.
     */
    protected function send_error_notification(\Exception $exception): void
    {
        global $CFG;

        $admins = get_admins();

        $subject = get_string('sync_error_subject', 'local_edulution', $CFG->shortname ?? 'Moodle');

        $message = get_string('sync_error_body', 'local_edulution', [
            'error' => $exception->getMessage(),
            'time' => date('Y-m-d H:i:s'),
            'site' => $CFG->wwwroot ?? 'unknown',
        ]);

        foreach ($admins as $admin) {
            email_to_user(
                $admin,
                \core_user::get_noreply_user(),
                $subject,
                $message
            );
        }
    }

    /**
     * Get the default time for this task.
     *
     * @return int Hour of day (0-23).
     */
    public function get_default_scheduled_task_hour(): int
    {
        return 4; // 4 AM.
    }

    /**
     * Get the default minute for this task.
     *
     * @return int Minute (0-59).
     */
    public function get_default_scheduled_task_minute(): int
    {
        return 0;
    }

    /**
     * Can this task be run manually?
     *
     * @return bool True.
     */
    public function can_run_from_web(): bool
    {
        return true;
    }
}
