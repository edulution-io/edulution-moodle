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
 * Sync report for tracking synchronization results.
 *
 * Stores and reports on sync operations including created, updated,
 * skipped items, and any errors that occurred.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * Sync report class.
 *
 * Collects and reports on synchronization results.
 */
class sync_report {

    /** @var array Created items */
    protected array $created = [];

    /** @var array Updated items */
    protected array $updated = [];

    /** @var array Skipped items with reasons */
    protected array $skipped = [];

    /** @var array Errors with messages */
    protected array $errors = [];

    /** @var int Start timestamp */
    protected int $start_time = 0;

    /** @var int End timestamp */
    protected int $end_time = 0;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->start_time = time();
    }

    /**
     * Add a created item.
     *
     * @param string $identifier Item identifier (e.g., username, group name).
     * @param array $details Optional additional details.
     * @return self
     */
    public function add_created(string $identifier, array $details = []): self {
        $this->created[] = [
            'identifier' => $identifier,
            'time' => time(),
            'details' => $details,
        ];
        return $this;
    }

    /**
     * Add an updated item.
     *
     * @param string $identifier Item identifier.
     * @param array $details Optional additional details.
     * @return self
     */
    public function add_updated(string $identifier, array $details = []): self {
        $this->updated[] = [
            'identifier' => $identifier,
            'time' => time(),
            'details' => $details,
        ];
        return $this;
    }

    /**
     * Add a skipped item.
     *
     * @param string $identifier Item identifier.
     * @param string $reason Reason for skipping.
     * @return self
     */
    public function add_skipped(string $identifier, string $reason): self {
        $this->skipped[] = [
            'identifier' => $identifier,
            'reason' => $reason,
            'time' => time(),
        ];
        return $this;
    }

    /**
     * Add an error.
     *
     * @param string $identifier Item identifier or operation name.
     * @param string $message Error message.
     * @return self
     */
    public function add_error(string $identifier, string $message): self {
        $this->errors[] = [
            'identifier' => $identifier,
            'message' => $message,
            'time' => time(),
        ];
        return $this;
    }

    /**
     * Get summary of sync results.
     *
     * @return array Summary with counts.
     */
    public function get_summary(): array {
        if ($this->end_time === 0) {
            $this->end_time = time();
        }

        return [
            'created' => count($this->created),
            'updated' => count($this->updated),
            'skipped' => count($this->skipped),
            'errors' => count($this->errors),
            'total_processed' => count($this->created) + count($this->updated) + count($this->skipped),
            'success' => count($this->errors) === 0,
            'duration' => $this->end_time - $this->start_time,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
        ];
    }

    /**
     * Get detailed results.
     *
     * @return array Full details of all items.
     */
    public function get_details(): array {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }

    /**
     * Get created items.
     *
     * @return array Created items.
     */
    public function get_created(): array {
        return $this->created;
    }

    /**
     * Get updated items.
     *
     * @return array Updated items.
     */
    public function get_updated(): array {
        return $this->updated;
    }

    /**
     * Get skipped items.
     *
     * @return array Skipped items with reasons.
     */
    public function get_skipped(): array {
        return $this->skipped;
    }

    /**
     * Get errors.
     *
     * @return array Errors.
     */
    public function get_errors(): array {
        return $this->errors;
    }

    /**
     * Check if sync was successful (no errors).
     *
     * @return bool True if no errors.
     */
    public function is_success(): bool {
        return count($this->errors) === 0;
    }

    /**
     * Convert report to JSON string.
     *
     * @param bool $pretty Use pretty printing.
     * @return string JSON representation.
     */
    public function to_json(bool $pretty = false): string {
        $data = [
            'summary' => $this->get_summary(),
            'details' => $this->get_details(),
        ];

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($data, $flags);
    }

    /**
     * Merge another report into this one.
     *
     * @param sync_report $other Report to merge.
     * @return self
     */
    public function merge(sync_report $other): self {
        $this->created = array_merge($this->created, $other->get_created());
        $this->updated = array_merge($this->updated, $other->get_updated());
        $this->skipped = array_merge($this->skipped, $other->get_skipped());
        $this->errors = array_merge($this->errors, $other->get_errors());
        return $this;
    }

    /**
     * Reset the report.
     *
     * @return self
     */
    public function reset(): self {
        $this->created = [];
        $this->updated = [];
        $this->skipped = [];
        $this->errors = [];
        $this->start_time = time();
        $this->end_time = 0;
        return $this;
    }

    /**
     * Get a human-readable summary string.
     *
     * @return string Summary text.
     */
    public function get_summary_text(): string {
        $summary = $this->get_summary();

        $lines = [];
        $lines[] = sprintf('Created: %d', $summary['created']);
        $lines[] = sprintf('Updated: %d', $summary['updated']);
        $lines[] = sprintf('Skipped: %d', $summary['skipped']);
        $lines[] = sprintf('Errors: %d', $summary['errors']);
        $lines[] = sprintf('Duration: %d seconds', $summary['duration']);
        $lines[] = sprintf('Status: %s', $summary['success'] ? 'Success' : 'Failed');

        return implode("\n", $lines);
    }

    /**
     * Get the duration in seconds.
     *
     * @return int Duration in seconds.
     */
    public function get_duration(): int {
        if ($this->end_time === 0) {
            return time() - $this->start_time;
        }
        return $this->end_time - $this->start_time;
    }

    /**
     * Set start time.
     *
     * @param int $time Unix timestamp.
     * @return self
     */
    public function set_start_time(int $time): self {
        $this->start_time = $time;
        return $this;
    }

    /**
     * Set end time.
     *
     * @param int $time Unix timestamp.
     * @return self
     */
    public function set_end_time(int $time): self {
        $this->end_time = $time;
        return $this;
    }

    /**
     * Get a formatted text summary suitable for CLI output.
     *
     * @return string Formatted text summary.
     */
    public function get_text_summary(): string {
        $summary = $this->get_summary();

        $lines = [];
        $lines[] = str_repeat('=', 50);
        $lines[] = '  Sync Results';
        $lines[] = str_repeat('=', 50);
        $lines[] = '';
        $lines[] = sprintf('  Created:   %d', $summary['created']);
        $lines[] = sprintf('  Updated:   %d', $summary['updated']);
        $lines[] = sprintf('  Skipped:   %d', $summary['skipped']);
        $lines[] = sprintf('  Errors:    %d', $summary['errors']);
        $lines[] = '';
        $lines[] = sprintf('  Duration:  %s', $this->format_duration($summary['duration']));
        $lines[] = sprintf('  Status:    %s', $summary['success'] ? 'SUCCESS' : 'FAILED');
        $lines[] = '';

        // Show errors if any.
        if (!empty($this->errors)) {
            $lines[] = 'Errors:';
            foreach (array_slice($this->errors, 0, 10) as $error) {
                $lines[] = sprintf('  - [%s] %s', $error['identifier'], $error['message']);
            }
            if (count($this->errors) > 10) {
                $lines[] = sprintf('  ... and %d more errors', count($this->errors) - 10);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Format duration in human-readable format.
     *
     * @param int $seconds Duration in seconds.
     * @return string Formatted duration.
     */
    protected function format_duration(int $seconds): string {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        if ($minutes < 60) {
            return "{$minutes}m {$secs}s";
        }
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return "{$hours}h {$mins}m {$secs}s";
    }

    /**
     * Save the report to the database.
     *
     * @return int|null The report ID or null on failure.
     */
    public function save(): ?int {
        global $DB, $USER;

        if ($this->end_time === 0) {
            $this->end_time = time();
        }

        $record = new \stdClass();
        $record->userid = $USER->id ?? 0;
        $record->timecreated = $this->start_time;
        $record->timefinished = $this->end_time;
        $record->duration = $this->end_time - $this->start_time;
        $record->created_count = count($this->created);
        $record->updated_count = count($this->updated);
        $record->skipped_count = count($this->skipped);
        $record->error_count = count($this->errors);
        $record->success = count($this->errors) === 0 ? 1 : 0;
        $record->report_data = $this->to_json();

        try {
            // Check if table exists, if not just store in config.
            $tables = $DB->get_tables();
            if (in_array('local_edulution_sync_reports', $tables)) {
                return $DB->insert_record('local_edulution_sync_reports', $record);
            }

            // Fallback: store in config.
            $stats = [
                'time' => $this->end_time,
                'duration' => $record->duration,
                'created' => $record->created_count,
                'updated' => $record->updated_count,
                'skipped' => $record->skipped_count,
                'errors' => $record->error_count,
                'success' => $record->success,
            ];
            set_config('last_sync_stats', json_encode($stats), 'local_edulution');
            set_config('last_sync_time', $this->end_time, 'local_edulution');

            return null;
        } catch (\Exception $e) {
            // Silently fail - saving report is not critical.
            return null;
        }
    }

    /**
     * Send the report via email to specified recipients or admins.
     *
     * @param array|null $recipients Array of user objects or null for all admins.
     * @return int Number of emails sent.
     */
    public function send_email(?array $recipients = null): int {
        global $CFG;

        if ($recipients === null) {
            $recipients = get_admins();
        }

        if (empty($recipients)) {
            return 0;
        }

        $summary = $this->get_summary();
        $sitename = $CFG->shortname ?? 'Moodle';

        $subject = get_string('sync_report_subject', 'local_edulution', $sitename);

        $data = new \stdClass();
        $data->site = $CFG->wwwroot ?? 'unknown';
        $data->time = date('Y-m-d H:i:s', $this->end_time ?: time());
        $data->duration = $summary['duration'];
        $data->created = $summary['created'];
        $data->updated = $summary['updated'];
        $data->skipped = $summary['skipped'];
        $data->errors = $summary['errors'];

        $messagehtml = get_string('sync_report_body_html', 'local_edulution', $data);
        $messagetext = get_string('sync_report_body_text', 'local_edulution', $data);

        // Append error details if any.
        if (!empty($this->errors)) {
            $errortext = "\n\nErrors:\n";
            $errorhtml = "<h3>Errors:</h3><ul>";
            foreach (array_slice($this->errors, 0, 20) as $error) {
                $errortext .= "- [{$error['identifier']}] {$error['message']}\n";
                $errorhtml .= "<li><strong>{$error['identifier']}:</strong> " . s($error['message']) . "</li>";
            }
            if (count($this->errors) > 20) {
                $errortext .= "... and " . (count($this->errors) - 20) . " more errors\n";
                $errorhtml .= "<li>... and " . (count($this->errors) - 20) . " more errors</li>";
            }
            $errorhtml .= "</ul>";
            $messagetext .= $errortext;
            $messagehtml .= $errorhtml;
        }

        $sent = 0;
        $noreply = \core_user::get_noreply_user();

        foreach ($recipients as $user) {
            if (!empty($user->email)) {
                $result = email_to_user($user, $noreply, $subject, $messagetext, $messagehtml);
                if ($result) {
                    $sent++;
                }
            }
        }

        return $sent;
    }
}
