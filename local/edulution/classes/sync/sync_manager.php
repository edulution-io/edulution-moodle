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
 * Sync manager - main orchestrator for Keycloak synchronization.
 *
 * Coordinates user and group synchronization between Keycloak and Moodle,
 * reading configuration from plugin settings.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * Sync manager class.
 *
 * Main orchestrator for the synchronization process. Reads configuration
 * from plugin settings and coordinates user and group synchronization.
 */
class sync_manager {

    /** Sync mode: full sync (users, courses, enrollments) */
    const MODE_FULL = 'full';

    /** Sync mode: users only */
    const MODE_USERS = 'users';

    /** Sync mode: courses only */
    const MODE_COURSES = 'courses';

    /** Sync mode: enrollments only */
    const MODE_ENROLLMENTS = 'enrollments';

    /** Config key for last sync time */
    const CONFIG_LAST_SYNC = 'last_sync_time';

    /** Config key for last sync stats */
    const CONFIG_LAST_STATS = 'last_sync_stats';

    /** @var keycloak_client Keycloak API client */
    protected keycloak_client $client;

    /** @var user_sync User synchronization handler */
    protected user_sync $user_sync;

    /** @var group_sync Group synchronization handler */
    protected group_sync $group_sync;

    /** @var group_classifier Group classifier */
    protected ?group_classifier $classifier = null;

    /** @var sync_report Combined report */
    protected sync_report $report;

    /** @var bool Whether sync is enabled */
    protected bool $enabled = false;

    /** @var array Plugin configuration */
    protected array $config = [];

    /** @var string Current sync mode */
    protected string $mode = self::MODE_FULL;

    /** @var bool Dry run mode */
    protected bool $dry_run = false;

    /** @var bool Verbose output */
    protected bool $verbose = false;

    /**
     * Constructor.
     *
     * Can be called with no arguments (reads from plugin settings)
     * or with a pre-configured client and classifier.
     *
     * @param keycloak_client|null $client Pre-configured Keycloak client.
     * @param group_classifier|null $classifier Pre-configured group classifier.
     */
    public function __construct(?keycloak_client $client = null, ?group_classifier $classifier = null) {
        $this->load_config();
        $this->report = new sync_report();

        if ($client !== null) {
            // Use provided client.
            $this->client = $client;
            $this->classifier = $classifier;
            $this->user_sync = new user_sync($this->client);
            // Note: group_sync may need the classifier, handled in sync methods.
        } elseif ($this->is_configured()) {
            $this->init_client();
        }
    }

    /**
     * Load configuration from plugin settings (environment variables take precedence).
     */
    protected function load_config(): void {
        $this->config = [
            'enabled' => (bool) \local_edulution_get_config('keycloak_sync_enabled'),
            'url' => \local_edulution_get_config('keycloak_url') ?: '',
            'realm' => \local_edulution_get_config('keycloak_realm', 'master') ?: '',
            'client_id' => \local_edulution_get_config('keycloak_client_id') ?: '',
            'client_secret' => \local_edulution_get_config('keycloak_client_secret') ?: '',
        ];

        $this->enabled = $this->config['enabled'];
    }

    /**
     * Initialize the Keycloak client and sync handlers.
     */
    protected function init_client(): void {
        $this->client = new keycloak_client(
            $this->config['url'],
            $this->config['realm'],
            $this->config['client_id'],
            $this->config['client_secret']
        );

        $this->user_sync = new user_sync($this->client);
        $this->group_sync = new group_sync($this->client, $this->user_sync);
    }

    /**
     * Check if Keycloak sync is properly configured.
     *
     * @return bool True if all required settings are present.
     */
    public function is_configured(): bool {
        return !empty($this->config['url']) &&
               !empty($this->config['realm']) &&
               !empty($this->config['client_id']) &&
               !empty($this->config['client_secret']);
    }

    /**
     * Check if sync is enabled.
     *
     * @return bool True if enabled.
     */
    public function is_enabled(): bool {
        return $this->enabled && $this->is_configured();
    }

    /**
     * Run a full synchronization (users and groups).
     *
     * @return sync_report Combined sync report.
     */
    public function run_full_sync(): sync_report {
        $this->report = new sync_report();

        if (!$this->is_enabled()) {
            $this->report->add_error('sync', 'Keycloak sync is not enabled or configured');
            return $this->report;
        }

        $start_time = time();

        // Sync users first.
        $user_report = $this->sync_users_only();
        $this->report->merge($user_report);

        // Then sync groups and memberships.
        $group_report = $this->sync_groups_only();
        $this->report->merge($group_report);

        // Store last sync time and stats.
        $this->store_sync_stats($start_time);

        return $this->report;
    }

    /**
     * Run a preview of what would be synchronized.
     *
     * Returns a report of what changes would be made without
     * actually performing any modifications.
     *
     * @return array Preview data with users and groups to sync.
     */
    public function run_preview(): array {
        $preview = [
            'configured' => $this->is_configured(),
            'enabled' => $this->is_enabled(),
            'users' => [
                'to_create' => [],
                'to_update' => [],
                'total_in_keycloak' => 0,
            ],
            'groups' => [
                'to_create' => [],
                'to_update' => [],
                'total_in_keycloak' => 0,
            ],
            'errors' => [],
        ];

        if (!$this->is_configured()) {
            $preview['errors'][] = 'Keycloak is not configured';
            return $preview;
        }

        try {
            // Preview users.
            $preview['users'] = $this->preview_users();

            // Preview groups.
            $preview['groups'] = $this->preview_groups();
        } catch (\Exception $e) {
            $preview['errors'][] = $e->getMessage();
        }

        return $preview;
    }

    /**
     * Preview user synchronization.
     *
     * @return array User preview data.
     */
    protected function preview_users(): array {
        $preview = [
            'to_create' => [],
            'to_update' => [],
            'total_in_keycloak' => 0,
        ];

        $offset = 0;
        $batch_size = 100;

        do {
            $keycloak_users = $this->client->get_users('', $batch_size, $offset);
            $preview['total_in_keycloak'] += count($keycloak_users);

            foreach ($keycloak_users as $kc_user) {
                if (empty($kc_user['username']) || empty($kc_user['email'])) {
                    continue;
                }

                if (!($kc_user['enabled'] ?? true)) {
                    continue;
                }

                $moodle_user = $this->user_sync->find_moodle_user($kc_user);

                if ($moodle_user) {
                    $preview['to_update'][] = [
                        'username' => $kc_user['username'],
                        'email' => $kc_user['email'],
                        'moodle_id' => $moodle_user->id,
                    ];
                } else {
                    $preview['to_create'][] = [
                        'username' => $kc_user['username'],
                        'email' => $kc_user['email'],
                        'firstname' => $kc_user['firstName'] ?? '',
                        'lastname' => $kc_user['lastName'] ?? '',
                    ];
                }
            }

            $offset += $batch_size;
        } while (count($keycloak_users) === $batch_size);

        return $preview;
    }

    /**
     * Preview group synchronization.
     *
     * @return array Group preview data.
     */
    protected function preview_groups(): array {
        $preview = [
            'to_create' => [],
            'to_update' => [],
            'total_in_keycloak' => 0,
        ];

        $keycloak_groups = $this->client->get_all_groups();
        $preview['total_in_keycloak'] = count($keycloak_groups);

        foreach ($keycloak_groups as $kc_group) {
            if (empty($kc_group['name']) || empty($kc_group['id'])) {
                continue;
            }

            $cohort = $this->group_sync->find_moodle_cohort($kc_group);

            if ($cohort) {
                if ($cohort->name !== $kc_group['name']) {
                    $preview['to_update'][] = [
                        'name' => $kc_group['name'],
                        'cohort_id' => $cohort->id,
                        'old_name' => $cohort->name,
                    ];
                }
            } else {
                $preview['to_create'][] = [
                    'name' => $kc_group['name'],
                    'path' => $kc_group['path'] ?? '',
                ];
            }
        }

        return $preview;
    }

    /**
     * Synchronize users only.
     *
     * @return sync_report Sync report for users.
     */
    public function sync_users_only(): sync_report {
        if (!$this->is_enabled()) {
            $report = new sync_report();
            $report->add_error('sync', 'Keycloak sync is not enabled or configured');
            return $report;
        }

        return $this->user_sync->sync_users();
    }

    /**
     * Synchronize groups only.
     *
     * @return sync_report Sync report for groups.
     */
    public function sync_groups_only(): sync_report {
        if (!$this->is_enabled()) {
            $report = new sync_report();
            $report->add_error('sync', 'Keycloak sync is not enabled or configured');
            return $report;
        }

        // Sync group structures.
        $report = $this->group_sync->sync_groups();

        // Sync memberships.
        $membership_report = $this->group_sync->sync_group_memberships();
        $report->merge($membership_report);

        return $report;
    }

    /**
     * Get the timestamp of the last sync.
     *
     * @return int|null Unix timestamp or null if never synced.
     */
    public function get_last_sync_time(): ?int {
        $time = get_config('local_edulution', self::CONFIG_LAST_SYNC);
        return $time ? (int) $time : null;
    }

    /**
     * Get statistics from the last sync.
     *
     * @return array|null Statistics array or null.
     */
    public function get_sync_statistics(): ?array {
        $stats = get_config('local_edulution', self::CONFIG_LAST_STATS);

        if (!$stats) {
            return null;
        }

        $decoded = json_decode($stats, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Store sync statistics after a run.
     *
     * @param int $start_time Sync start timestamp.
     */
    protected function store_sync_stats(int $start_time): void {
        $end_time = time();

        $stats = [
            'start_time' => $start_time,
            'end_time' => $end_time,
            'duration' => $end_time - $start_time,
            'summary' => $this->report->get_summary(),
        ];

        set_config(self::CONFIG_LAST_SYNC, $end_time, 'local_edulution');
        set_config(self::CONFIG_LAST_STATS, json_encode($stats), 'local_edulution');
    }

    /**
     * Test the Keycloak connection.
     *
     * @return array Test results with 'success' and 'message'.
     */
    public function test_connection(): array {
        if (!$this->is_configured()) {
            return [
                'success' => false,
                'message' => get_string('sync_not_configured', 'local_edulution'),
            ];
        }

        return $this->client->test_connection();
    }

    /**
     * Get configuration status.
     *
     * @return array Configuration details.
     */
    public function get_config_status(): array {
        return [
            'configured' => $this->is_configured(),
            'enabled' => $this->is_enabled(),
            'url' => $this->config['url'] ?: '(not set)',
            'realm' => $this->config['realm'] ?: '(not set)',
            'client_id' => $this->config['client_id'] ?: '(not set)',
            'client_secret' => !empty($this->config['client_secret']) ? '(set)' : '(not set)',
        ];
    }

    /**
     * Get the current sync report.
     *
     * @return sync_report Report instance.
     */
    public function get_report(): sync_report {
        return $this->report;
    }

    /**
     * Get the Keycloak client.
     *
     * @return keycloak_client|null Client instance or null if not configured.
     */
    public function get_client(): ?keycloak_client {
        return $this->client ?? null;
    }

    /**
     * Get the user sync handler.
     *
     * @return user_sync|null Handler instance or null if not configured.
     */
    public function get_user_sync(): ?user_sync {
        return $this->user_sync ?? null;
    }

    /**
     * Get the group sync handler.
     *
     * @return group_sync|null Handler instance or null if not configured.
     */
    public function get_group_sync(): ?group_sync {
        return $this->group_sync ?? null;
    }

    /**
     * Set the sync mode.
     *
     * @param string $mode One of MODE_FULL, MODE_USERS, MODE_COURSES, MODE_ENROLLMENTS.
     * @return self
     */
    public function set_mode(string $mode): self {
        $valid_modes = [self::MODE_FULL, self::MODE_USERS, self::MODE_COURSES, self::MODE_ENROLLMENTS];
        if (in_array($mode, $valid_modes)) {
            $this->mode = $mode;
        }
        return $this;
    }

    /**
     * Set dry run mode.
     *
     * @param bool $dry_run Whether to run in dry run mode.
     * @return self
     */
    public function set_dry_run(bool $dry_run): self {
        $this->dry_run = $dry_run;
        return $this;
    }

    /**
     * Set verbose output.
     *
     * @param bool $verbose Whether to output verbose messages.
     * @return self
     */
    public function set_verbose(bool $verbose): self {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * Run synchronization based on current mode.
     *
     * @return sync_report Combined sync report.
     */
    public function run(): sync_report {
        $this->report = new sync_report();

        if (!$this->is_configured() && !isset($this->client)) {
            $this->report->add_error('sync', get_string('keycloak_not_configured', 'local_edulution'));
            return $this->report;
        }

        $start_time = time();

        if ($this->verbose) {
            mtrace("Starting sync in mode: {$this->mode}" . ($this->dry_run ? ' (DRY RUN)' : ''));
        }

        try {
            $synced_courses = [];

            switch ($this->mode) {
                case self::MODE_USERS:
                    $this->run_user_sync();
                    break;

                case self::MODE_COURSES:
                    $this->run_course_sync();
                    break;

                case self::MODE_ENROLLMENTS:
                    $this->run_enrollment_sync();
                    break;

                case self::MODE_FULL:
                default:
                    $this->run_user_sync();
                    $synced_courses = $this->run_course_sync();
                    $this->run_enrollment_sync($synced_courses);
                    break;
            }
        } catch (\Exception $e) {
            $this->report->add_error('sync', $e->getMessage());
        }

        // Store stats (not in dry run mode).
        if (!$this->dry_run) {
            $this->store_sync_stats($start_time);
        }

        return $this->report;
    }

    /**
     * Run user synchronization.
     */
    protected function run_user_sync(): void {
        if ($this->verbose) {
            mtrace('  Syncing users...');
        }

        if ($this->dry_run) {
            // Preview mode - just count what would be synced.
            $preview = $this->preview_users();
            foreach ($preview['to_create'] as $user) {
                $this->report->add_skipped($user['username'], 'Would create (dry run)');
            }
            foreach ($preview['to_update'] as $user) {
                $this->report->add_skipped($user['username'], 'Would update (dry run)');
            }
            if ($this->verbose) {
                mtrace("    Would create: " . count($preview['to_create']));
                mtrace("    Would update: " . count($preview['to_update']));
            }
        } else {
            // Actual sync.
            $user_report = $this->user_sync->sync_users();
            $this->report->merge($user_report);
            if ($this->verbose) {
                $summary = $user_report->get_summary();
                mtrace("    Created: {$summary['created']}");
                mtrace("    Updated: {$summary['updated']}");
                mtrace("    Skipped: {$summary['skipped']}");
            }
        }
    }

    /**
     * Run course synchronization.
     *
     * @return array Course sync results for use by enrollment sync.
     */
    protected function run_course_sync(): array {
        if ($this->verbose) {
            mtrace('  Syncing courses from groups...');
        }

        $classifier = $this->classifier ?? new group_classifier();

        // Get groups from Keycloak.
        $groups = $this->client->get_all_groups_flat();

        if ($this->verbose) {
            mtrace("    Found " . count($groups) . " groups in Keycloak");
        }

        if ($this->dry_run) {
            // Preview mode.
            $classified = $classifier->classify_groups($groups);
            $class_count = count($classified[group_classifier::TYPE_CLASS] ?? []);
            $project_count = count($classified[group_classifier::TYPE_PROJECT] ?? []);

            $this->report->add_skipped('courses', "Would sync {$class_count} class courses (dry run)");
            $this->report->add_skipped('projects', "Would sync {$project_count} project courses (dry run)");

            if ($this->verbose) {
                mtrace("    Would create class courses: {$class_count}");
                mtrace("    Would create project courses: {$project_count}");
            }
            return [];
        }

        // Create course_sync handler and run.
        $course_sync = new course_sync($this->client, $classifier);
        $results = $course_sync->sync($groups);

        // Convert results to report format.
        $stats = $results['stats'] ?? [];
        for ($i = 0; $i < ($stats['courses_created'] ?? 0); $i++) {
            $this->report->add_created('course', ['type' => 'course']);
        }
        for ($i = 0; $i < ($stats['courses_updated'] ?? 0); $i++) {
            $this->report->add_updated('course', ['type' => 'course']);
        }
        for ($i = 0; $i < ($stats['courses_skipped'] ?? 0); $i++) {
            $this->report->add_skipped('course', 'Already exists');
        }
        for ($i = 0; $i < ($stats['errors'] ?? 0); $i++) {
            $this->report->add_error('course', 'Course sync error');
        }

        if ($this->verbose) {
            mtrace("    Created: " . ($stats['courses_created'] ?? 0));
            mtrace("    Updated: " . ($stats['courses_updated'] ?? 0));
            mtrace("    Skipped: " . ($stats['courses_skipped'] ?? 0));
        }

        return $results['courses'] ?? [];
    }

    /**
     * Run enrollment synchronization.
     *
     * @param array $synced_courses Optional course sync results.
     */
    protected function run_enrollment_sync(array $synced_courses = []): void {
        if ($this->verbose) {
            mtrace('  Syncing enrollments...');
        }

        $classifier = $this->classifier ?? new group_classifier();

        // Get groups from Keycloak.
        $groups = $this->client->get_all_groups_flat();

        if ($this->dry_run) {
            // Preview mode - count group memberships.
            $total_members = 0;
            foreach ($groups as $group) {
                $members = $this->client->get_group_members($group['id']);
                $total_members += count($members);
            }
            $this->report->add_skipped('enrollments', "Would process {$total_members} group memberships (dry run)");
            if ($this->verbose) {
                mtrace("    Would process: {$total_members} group memberships");
            }
            return;
        }

        // Get synced users info (username => user info mapping).
        $synced_users = $this->get_synced_users_map();

        // Create enrollment_sync handler and run.
        $enrollment_sync = new enrollment_sync($this->client, $classifier);
        $results = $enrollment_sync->sync($synced_users, $synced_courses, $groups);

        // Convert results to report format.
        $stats = $results['stats'] ?? [];
        for ($i = 0; $i < ($stats['enrollments_created'] ?? 0); $i++) {
            $this->report->add_created('enrollment', ['type' => 'enrollment']);
        }
        for ($i = 0; $i < ($stats['unenrollments'] ?? 0); $i++) {
            $this->report->add_updated('enrollment', ['type' => 'unenrollment']);
        }
        for ($i = 0; $i < ($stats['enrollments_skipped'] ?? 0); $i++) {
            $this->report->add_skipped('enrollment', 'Already enrolled');
        }
        for ($i = 0; $i < ($stats['errors'] ?? 0); $i++) {
            $this->report->add_error('enrollment', 'Enrollment sync error');
        }

        if ($this->verbose) {
            mtrace("    Created: " . ($stats['enrollments_created'] ?? 0));
            mtrace("    Removed: " . ($stats['unenrollments'] ?? 0));
            mtrace("    Skipped: " . ($stats['enrollments_skipped'] ?? 0));
        }
    }

    /**
     * Get a map of synced users (username => user info).
     *
     * @return array User mapping.
     */
    protected function get_synced_users_map(): array {
        global $DB;

        $users = [];
        $records = $DB->get_records('user', ['deleted' => 0, 'suspended' => 0], '', 'id, username, email, firstname, lastname');

        foreach ($records as $user) {
            $users[$user->username] = [
                'user_id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
            ];
        }

        return $users;
    }

    /**
     * Check if this manager has a valid client configured.
     *
     * @return bool True if client is available.
     */
    public function has_client(): bool {
        return isset($this->client);
    }
}
