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
 * Ad-hoc task for background Keycloak synchronization.
 *
 * This task is queued when a user starts a sync from the web UI.
 * It runs in the background via cron, allowing the UI to remain responsive.
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
 * Ad-hoc task for background Keycloak synchronization.
 */
class adhoc_sync_task extends \core\task\adhoc_task
{

    /** @var string Current sync ID */
    protected string $sync_id = '';

    /**
     * Get task name.
     *
     * @return string Task name.
     */
    public function get_name(): string
    {
        return get_string('task_adhoc_sync', 'local_edulution');
    }

    /**
     * Execute the task.
     */
    public function execute(): void
    {
        global $DB;

        $data = $this->get_custom_data();
        $this->sync_id = $data->sync_id ?? '';
        $direction = $data->direction ?? 'from_keycloak';
        $user_id = $data->user_id ?? 0;

        if (empty($this->sync_id)) {
            mtrace('No sync_id provided, aborting.');
            return;
        }

        mtrace('========================================');
        mtrace('  edulution Keycloak Sync');
        mtrace('========================================');
        mtrace("Sync ID: {$this->sync_id}");
        mtrace("Direction: {$direction}");
        mtrace("User ID: {$user_id}");
        mtrace('');

        // Update job status to processing.
        $this->update_job_status([
            'status' => 'processing',
            'progress' => 1,
            'phase' => 'init',
            'log' => [['type' => 'info', 'message' => 'Background task started', 'phase' => 'init']],
        ]);

        // Check if Keycloak is configured (environment variables take precedence).
        $url = \local_edulution_get_config('keycloak_url');
        $realm = \local_edulution_get_config('keycloak_realm', 'master');
        $client_id = \local_edulution_get_config('keycloak_client_id');
        $client_secret = \local_edulution_get_config('keycloak_client_secret');

        if (empty($url) || empty($realm) || empty($client_id) || empty($client_secret)) {
            $this->update_job_status([
                'status' => 'failed',
                'progress' => 0,
                'error_details' => ['Keycloak is not configured (check env vars or admin settings)'],
                'log' => [['type' => 'error', 'message' => 'Keycloak is not configured', 'phase' => 'init']],
                'finished' => time(),
            ]);
            mtrace('[ERROR] Keycloak is not configured. Aborting.');
            return;
        }

        try {
            // Create Keycloak client.
            $client = new keycloak_client($url, $realm, $client_id, $client_secret);
            $classifier = new group_classifier();

            // Test connection.
            $connection = $client->test_connection();
            if (!$connection['success']) {
                throw new \Exception('Keycloak connection failed: ' . ($connection['message'] ?? 'Unknown error'));
            }

            $this->update_job_status([
                'progress' => 5,
                'log' => [['type' => 'success', 'message' => 'Connected to Keycloak realm: ' . $realm, 'phase' => 'init']],
            ]);

            mtrace("Connected to Keycloak realm: {$realm}");
            mtrace('');

            // Create phased sync with progress callback.
            $sync = new phased_sync($client, $classifier);
            $sync->set_progress_callback([$this, 'on_progress']);

            // Run the phased sync.
            $result = $sync->run();

            mtrace('');
            mtrace('========================================');
            mtrace('  Sync Complete');
            mtrace('========================================');
            mtrace('');

            // Build summary.
            $stats = $result['stats'];
            mtrace("Users:       {$stats['users_created']} created, {$stats['users_updated']} updated, {$stats['users_skipped']} skipped");
            mtrace("Courses:     {$stats['courses_created']} created, {$stats['courses_skipped']} skipped");
            mtrace("Enrollments: {$stats['enrollments_created']} created, {$stats['enrollments_skipped']} skipped");
            mtrace("Duration:    {$stats['duration']} seconds");

            if (!empty($result['errors'])) {
                mtrace("Errors:      " . count($result['errors']));
            }

            // Update job with final results.
            $total_created = $stats['users_created'] + $stats['courses_created'] + $stats['enrollments_created'];
            $total_updated = $stats['users_updated'];
            $total_errors = $stats['users_errors'] + $stats['courses_errors'] + $stats['enrollments_errors'];

            $this->update_job_status([
                'status' => 'completed',
                'progress' => 100,
                'phase' => 'complete',
                'created' => $total_created,
                'updated' => $total_updated,
                'deleted' => 0,
                'errors' => $total_errors,
                'processed' => $stats['users_fetched'],
                'total' => $stats['users_fetched'],
                'error_details' => array_map(function ($e) {
                    return $e['message'] ?? json_encode($e);
                }, $result['errors']),
                'log' => $this->format_log_entries($result['log']),
                'finished' => time(),
            ]);

            mtrace('');
            mtrace("Sync job {$this->sync_id} completed successfully.");

        } catch (\Exception $e) {
            mtrace('');
            mtrace('[ERROR] Sync failed: ' . $e->getMessage());

            $this->update_job_status([
                'status' => 'failed',
                'progress' => 0,
                'error_details' => [$e->getMessage()],
                'log' => [['type' => 'error', 'message' => $e->getMessage(), 'phase' => 'error']],
                'finished' => time(),
            ]);
        }
    }

    /**
     * Progress callback from phased_sync.
     *
     * @param string $phase Current phase.
     * @param int $progress Progress percentage.
     * @param string $message Status message.
     * @param array $stats Current statistics.
     */
    public function on_progress(string $phase, int $progress, string $message, array $stats): void
    {
        // Update job status with current progress.
        $this->update_job_status([
            'progress' => $progress,
            'phase' => $phase,
            'processed' => $stats['users_fetched'] ?? 0,
            'log' => [['type' => 'info', 'message' => $message, 'phase' => $phase]],
        ]);
    }

    /**
     * Format log entries for storage.
     *
     * @param array $log_entries Log entries from phased_sync.
     * @return array Formatted log entries.
     */
    protected function format_log_entries(array $log_entries): array
    {
        $formatted = [];
        foreach ($log_entries as $entry) {
            $formatted[] = [
                'type' => $entry['type'] ?? 'info',
                'message' => $entry['message'] ?? '',
                'phase' => $entry['phase'] ?? '',
            ];
        }
        return $formatted;
    }

    /**
     * Update the sync job status in the database.
     *
     * @param array $updates Fields to update.
     */
    protected function update_job_status(array $updates): void
    {
        global $DB;

        if (empty($this->sync_id)) {
            return;
        }

        // Get existing job.
        $job = $DB->get_record('local_edulution_sync_jobs', ['sync_id' => $this->sync_id]);

        if (!$job) {
            // Create new job record if it doesn't exist.
            $job = new \stdClass();
            $job->sync_id = $this->sync_id;
            $job->status = 'pending';
            $job->progress = 0;
            $job->processed = 0;
            $job->total = 0;
            $job->created_count = 0;
            $job->updated_count = 0;
            $job->deleted_count = 0;
            $job->error_count = 0;
            $job->error_details = '[]';
            $job->log_entries = '[]';
            $job->timecreated = time();
            $job->timemodified = time();
            $job->id = $DB->insert_record('local_edulution_sync_jobs', $job);
        }

        // Apply updates.
        if (isset($updates['status'])) {
            $job->status = $updates['status'];
        }
        if (isset($updates['progress'])) {
            $job->progress = $updates['progress'];
        }
        if (isset($updates['phase'])) {
            $job->phase = $updates['phase'];
        }
        if (isset($updates['processed'])) {
            $job->processed = $updates['processed'];
        }
        if (isset($updates['total'])) {
            $job->total = $updates['total'];
        }
        if (isset($updates['created'])) {
            $job->created_count = $updates['created'];
        }
        if (isset($updates['updated'])) {
            $job->updated_count = $updates['updated'];
        }
        if (isset($updates['deleted'])) {
            $job->deleted_count = $updates['deleted'];
        }
        if (isset($updates['errors'])) {
            $job->error_count = $updates['errors'];
        }
        if (isset($updates['error_details'])) {
            // Replace errors (final result).
            $job->error_details = json_encode((array) $updates['error_details']);
        }
        if (isset($updates['log'])) {
            // Append to existing log.
            $existing = json_decode($job->log_entries, true) ?: [];
            $job->log_entries = json_encode(array_merge($existing, $updates['log']));
        }
        if (isset($updates['finished'])) {
            $job->timefinished = $updates['finished'];
        }
        if (isset($updates['report_id'])) {
            $job->report_id = $updates['report_id'];
        }

        $job->timemodified = time();
        $DB->update_record('local_edulution_sync_jobs', $job);
    }
}
