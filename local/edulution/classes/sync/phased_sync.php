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
 * Phased synchronization manager.
 *
 * Splits the Keycloak sync into clear phases with delta calculation
 * and progress tracking for each phase.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * Phased synchronization manager.
 *
 * Phases:
 * 1. FETCH_USERS - Fetch users from Keycloak
 * 2. DELTA_USERS - Calculate user delta (create/update/skip)
 * 3. SYNC_USERS - Apply user changes
 * 4. FETCH_GROUPS - Fetch groups from Keycloak
 * 5. DELTA_GROUPS - Calculate group/course delta
 * 6. SYNC_GROUPS - Create/update courses
 * 7. FETCH_MEMBERS - Fetch group memberships
 * 8. DELTA_ENROLL - Calculate enrollment delta
 * 9. SYNC_ENROLL - Apply enrollment changes
 * 10. COMPLETE - Finalize and report
 */
class phased_sync
{

    /** Phase constants */
    const PHASE_INIT = 'init';
    const PHASE_FETCH_USERS = 'fetch_users';
    const PHASE_DELTA_USERS = 'delta_users';
    const PHASE_SYNC_USERS = 'sync_users';
    const PHASE_FETCH_GROUPS = 'fetch_groups';
    const PHASE_DELTA_GROUPS = 'delta_groups';
    const PHASE_SYNC_GROUPS = 'sync_groups';
    const PHASE_FETCH_MEMBERS = 'fetch_members';
    const PHASE_DELTA_ENROLL = 'delta_enroll';
    const PHASE_SYNC_ENROLL = 'sync_enroll';
    const PHASE_COMPLETE = 'complete';

    /** @var keycloak_client Keycloak client */
    protected keycloak_client $client;

    /** @var group_classifier Group classifier */
    protected group_classifier $classifier;

    /** @var naming_schema_processor Schema processor for course naming */
    protected ?naming_schema_processor $schema_processor = null;

    /** @var category_path_resolver Category resolver */
    protected ?category_path_resolver $category_resolver = null;

    /** @var callable|null Progress callback */
    protected $progress_callback = null;

    /** @var bool Verbose logging (log each item vs only summaries) */
    protected bool $verbose = false;

    /** @var string Current phase */
    protected string $current_phase = self::PHASE_INIT;

    /** @var array Keycloak users cache */
    protected array $keycloak_users = [];

    /** @var array User info cache: username => ['moodle_id' => int, 'is_teacher' => bool] */
    protected array $user_cache = [];

    /** @var array Expected enrollments: 'courseid_userid' => true */
    protected array $expected_enrollments = [];

    /** @var array Keycloak groups cache */
    protected array $keycloak_groups = [];

    /** @var array User delta */
    protected array $user_delta = [
        'to_create' => [],
        'to_update' => [],
        'to_skip' => [],
    ];

    /** @var array Group delta */
    protected array $group_delta = [
        'to_create' => [],
        'to_update' => [],
        'to_skip' => [],
    ];

    /** @var array Enrollment delta */
    protected array $enroll_delta = [
        'to_enroll' => [],
        'to_unenroll' => [],
        'to_skip' => [],
    ];

    /** @var array Statistics */
    protected array $stats = [
        'users_fetched' => 0,
        'users_created' => 0,
        'users_updated' => 0,
        'users_skipped' => 0,
        'users_errors' => 0,
        'teachers_detected' => 0,
        'coursecreators_assigned' => 0,
        'users_suspended' => 0,
        'groups_fetched' => 0,
        'courses_created' => 0,
        'courses_updated' => 0,
        'courses_skipped' => 0,
        'courses_errors' => 0,
        'enrollments_created' => 0,
        'enrollments_updated' => 0,
        'enrollments_removed' => 0,
        'enrollments_skipped' => 0,
        'enrollments_errors' => 0,
    ];

    /** @var array Errors */
    protected array $errors = [];

    /** @var array Log entries */
    protected array $log = [];

    /**
     * Constructor.
     *
     * @param keycloak_client $client Keycloak client.
     * @param group_classifier|null $classifier Group classifier.
     */
    public function __construct(keycloak_client $client, ?group_classifier $classifier = null)
    {
        $this->client = $client;
        $this->classifier = $classifier ?? new group_classifier();

        // Initialize schema processor with configuration.
        $this->init_schema_processor();

        // Initialize category resolver with parent category from settings.
        $parent_category_id = (int) get_config('local_edulution', 'parent_category_id');
        $this->category_resolver = new category_path_resolver($parent_category_id);
    }

    /**
     * Initialize the schema processor from configuration.
     */
    protected function init_schema_processor(): void
    {
        // Load schema configuration from settings or use defaults.
        $schema_json = get_config('local_edulution', 'naming_schemas');

        if (!empty($schema_json)) {
            $config = json_decode($schema_json, true);
            if (is_array($config)) {
                $this->schema_processor = new naming_schema_processor($config);
                return;
            }
        }

        // Use German school defaults if no configuration.
        $defaults = naming_schema_processor::get_german_school_defaults();
        $this->schema_processor = new naming_schema_processor($defaults);
    }

    /**
     * Set progress callback.
     *
     * @param callable $callback Callback function(phase, progress, message, stats).
     * @return self
     */
    public function set_progress_callback(callable $callback): self
    {
        $this->progress_callback = $callback;
        return $this;
    }

    /**
     * Set verbose logging mode.
     *
     * @param bool $verbose True for detailed logging, false for summaries only.
     * @return self
     */
    public function set_verbose(bool $verbose): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * Run the full phased sync.
     *
     * @return array Final statistics and results.
     */
    public function run(): array
    {
        $start_time = time();

        try {
            // Phase 1: Fetch users from Keycloak.
            $this->run_phase_fetch_users();

            // Phase 2: Calculate user delta.
            $this->run_phase_delta_users();

            // Phase 3: Sync users.
            $this->run_phase_sync_users();

            // Phase 4: Fetch groups from Keycloak.
            $this->run_phase_fetch_groups();

            // Phase 5: Calculate group delta.
            $this->run_phase_delta_groups();

            // Phase 6: Sync groups/courses.
            $this->run_phase_sync_groups();

            // Phase 7: Fetch group memberships.
            $this->run_phase_fetch_members();

            // Phase 8: Calculate enrollment delta.
            $this->run_phase_delta_enroll();

            // Phase 9: Sync enrollments.
            $this->run_phase_sync_enroll();

            // Phase 10: Complete.
            $this->run_phase_complete();

        } catch (\Exception $e) {
            $this->add_error('sync', $e->getMessage());
            $this->log('error', 'Sync failed: ' . $e->getMessage());
        }

        $this->stats['duration'] = time() - $start_time;
        $this->stats['success'] = empty($this->errors);

        return [
            'stats' => $this->stats,
            'errors' => $this->errors,
            'log' => $this->log,
        ];
    }

    /**
     * Phase 1: Fetch users from Keycloak.
     */
    protected function run_phase_fetch_users(): void
    {
        $this->set_phase(self::PHASE_FETCH_USERS, 5, 'Fetching users from Keycloak...');

        $this->keycloak_users = [];
        $offset = 0;
        $batch_size = 100;

        do {
            $users = $this->client->get_users('', $batch_size, $offset);
            $this->keycloak_users = array_merge($this->keycloak_users, $users);
            $offset += $batch_size;

            $this->update_progress(
                5 + min(10, (count($this->keycloak_users) / 100)),
                'Fetched ' . count($this->keycloak_users) . ' users...'
            );
        } while (count($users) === $batch_size);

        $this->stats['users_fetched'] = count($this->keycloak_users);
        if ($this->verbose) {
            $this->log('info', "Fetched {$this->stats['users_fetched']} users from Keycloak");
        }
    }

    /**
     * Phase 2: Calculate user delta.
     */
    protected function run_phase_delta_users(): void
    {
        global $DB;

        $this->set_phase(self::PHASE_DELTA_USERS, 15, 'Calculating user changes...');

        $this->user_delta = [
            'to_create' => [],
            'to_update' => [],
            'to_skip' => [],
        ];

        // Build map of existing Moodle users by email and username.
        $moodle_users_by_email = [];
        $moodle_users_by_username = [];
        $records = $DB->get_records('user', ['deleted' => 0], '', 'id, username, email, auth, firstname, lastname');
        foreach ($records as $user) {
            $moodle_users_by_email[strtolower($user->email)] = $user;
            $moodle_users_by_username[strtolower($user->username)] = $user;
        }

        $total = count($this->keycloak_users);
        $processed = 0;

        foreach ($this->keycloak_users as $kc_user) {
            $processed++;

            if ($processed % 50 === 0) {
                $this->update_progress(
                    15 + (10 * $processed / $total),
                    "Analyzing user $processed of $total..."
                );
            }

            // Skip users without required fields.
            if (empty($kc_user['username']) || empty($kc_user['email'])) {
                $this->user_delta['to_skip'][] = [
                    'user' => $kc_user,
                    'reason' => 'Missing username or email',
                ];
                continue;
            }

            // Skip disabled users.
            if (!($kc_user['enabled'] ?? true)) {
                $this->user_delta['to_skip'][] = [
                    'user' => $kc_user,
                    'reason' => 'User disabled in Keycloak',
                ];
                continue;
            }

            $email_lower = strtolower($kc_user['email']);
            $username_lower = strtolower($kc_user['username']);

            // Check if user exists by email or username.
            $moodle_user = $moodle_users_by_email[$email_lower]
                ?? $moodle_users_by_username[$username_lower]
                ?? null;

            if ($moodle_user) {
                // Check if update is needed.
                $needs_update = false;
                $changes = [];

                $kc_firstname = $kc_user['firstName'] ?? '';
                $kc_lastname = $kc_user['lastName'] ?? '';

                if ($moodle_user->firstname !== $kc_firstname) {
                    $needs_update = true;
                    $changes[] = 'firstname';
                }
                if ($moodle_user->lastname !== $kc_lastname) {
                    $needs_update = true;
                    $changes[] = 'lastname';
                }

                if ($needs_update) {
                    $this->user_delta['to_update'][] = [
                        'keycloak' => $kc_user,
                        'moodle' => $moodle_user,
                        'changes' => $changes,
                    ];
                } else {
                    $this->user_delta['to_skip'][] = [
                        'user' => $kc_user,
                        'reason' => 'No changes needed',
                        'moodle_id' => $moodle_user->id,
                    ];
                }
            } else {
                // New user to create.
                $this->user_delta['to_create'][] = $kc_user;
            }
        }

        // Check for users to suspend (in Moodle but not in Keycloak).
        $this->user_delta['to_suspend'] = [];

        $suspend_enabled = get_config('local_edulution', 'sync_suspend_users');
        if ($suspend_enabled) {
            // Build set of Keycloak usernames for quick lookup.
            $kc_usernames = [];
            foreach ($this->keycloak_users as $kc_user) {
                if (!empty($kc_user['username'])) {
                    $kc_usernames[strtolower($kc_user['username'])] = true;
                }
            }

            // Find Moodle users that were synced (auth = oauth2) but no longer in Keycloak.
            foreach ($moodle_users_by_username as $username => $moodle_user) {
                // Only check oauth2 users (synced from Keycloak).
                if ($moodle_user->auth !== 'oauth2') {
                    continue;
                }
                // Skip admin user.
                if ($moodle_user->username === 'admin' || $moodle_user->username === 'guest') {
                    continue;
                }
                // Skip already suspended users.
                if ($moodle_user->suspended) {
                    continue;
                }
                // If not in Keycloak, mark for suspension.
                if (!isset($kc_usernames[$username])) {
                    $this->user_delta['to_suspend'][] = $moodle_user;
                }
            }

            if ($this->verbose && count($this->user_delta['to_suspend']) > 0) {
                $this->log('warning', sprintf(
                    'Found %d users to suspend (no longer in Keycloak)',
                    count($this->user_delta['to_suspend'])
                ));
            }
        }

        if ($this->verbose) {
            $this->log('info', sprintf(
                'User delta: %d to create, %d to update, %d to skip, %d to suspend',
                count($this->user_delta['to_create']),
                count($this->user_delta['to_update']),
                count($this->user_delta['to_skip']),
                count($this->user_delta['to_suspend'])
            ));
        }
    }

    /**
     * Phase 3: Sync users.
     *
     * Also builds the user_cache with is_teacher status for enrollment phase.
     */
    protected function run_phase_sync_users(): void
    {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $this->set_phase(self::PHASE_SYNC_USERS, 25, 'Creating and updating users...');

        // Reset user cache.
        $this->user_cache = [];
        $teachers_detected = 0;
        $coursecreators_assigned = 0;

        $total_actions = count($this->user_delta['to_create']) + count($this->user_delta['to_update']);
        $processed = 0;

        // Create new users.
        foreach ($this->user_delta['to_create'] as $kc_user) {
            $processed++;
            $this->update_progress(
                25 + (15 * $processed / max(1, $total_actions)),
                "Creating user $processed of $total_actions..."
            );

            try {
                $user = new \stdClass();
                $user->username = strtolower($kc_user['username']);
                $user->email = strtolower($kc_user['email']);
                $user->firstname = $kc_user['firstName'] ?? '';
                $user->lastname = $kc_user['lastName'] ?? '';
                $user->auth = 'oauth2';
                $user->confirmed = 1;
                $user->mnethostid = $CFG->mnet_localhost_id;
                $user->password = '';

                $user->id = user_create_user($user, false, false);
                $this->stats['users_created']++;
                if ($this->verbose) {
                    $this->log('success', "Created user: {$user->username}");
                }

                // Store Keycloak mapping if table exists.
                $this->store_user_mapping($kc_user['id'] ?? '', $user->id, $user->username);

                // Cache user info with is_teacher status (determined from LDAP_ENTRY_DN).
                $is_teacher = $this->is_teacher_user($kc_user);
                $this->user_cache[strtolower($kc_user['username'])] = [
                    'moodle_id' => $user->id,
                    'is_teacher' => $is_teacher,
                ];
                if ($is_teacher) {
                    $teachers_detected++;
                    // Assign coursecreator role to teachers.
                    if ($this->assign_coursecreator_role($user->id)) {
                        $coursecreators_assigned++;
                    }
                }

            } catch (\Exception $e) {
                $this->stats['users_errors']++;
                $this->add_error('user_create', "Failed to create {$kc_user['username']}: " . $e->getMessage());
            }
        }

        // Update existing users.
        foreach ($this->user_delta['to_update'] as $update) {
            $processed++;
            $this->update_progress(
                25 + (15 * $processed / max(1, $total_actions)),
                "Updating user $processed of $total_actions..."
            );

            try {
                $user = $update['moodle'];
                $kc_user = $update['keycloak'];

                $user->firstname = $kc_user['firstName'] ?? $user->firstname;
                $user->lastname = $kc_user['lastName'] ?? $user->lastname;

                user_update_user($user, false, false);
                $this->stats['users_updated']++;
                if ($this->verbose) {
                    $this->log('info', "Updated user: {$user->username}");
                }

                // Cache user info with is_teacher status.
                $is_teacher = $this->is_teacher_user($kc_user);
                $this->user_cache[strtolower($kc_user['username'])] = [
                    'moodle_id' => $user->id,
                    'is_teacher' => $is_teacher,
                ];
                if ($is_teacher) {
                    $teachers_detected++;
                    // Assign coursecreator role to teachers.
                    if ($this->assign_coursecreator_role($user->id)) {
                        $coursecreators_assigned++;
                    }
                }

            } catch (\Exception $e) {
                $this->stats['users_errors']++;
                $this->add_error('user_update', "Failed to update {$user->username}: " . $e->getMessage());
            }
        }

        // Also cache skipped users (they exist and need enrollment too).
        foreach ($this->user_delta['to_skip'] as $skip) {
            $kc_user = $skip['user'] ?? null;
            $moodle_id = $skip['moodle_id'] ?? null;

            if ($kc_user && $moodle_id && !empty($kc_user['username'])) {
                $is_teacher = $this->is_teacher_user($kc_user);
                $this->user_cache[strtolower($kc_user['username'])] = [
                    'moodle_id' => $moodle_id,
                    'is_teacher' => $is_teacher,
                ];
                if ($is_teacher) {
                    $teachers_detected++;
                    // Assign coursecreator role to teachers (might already have it).
                    if ($this->assign_coursecreator_role($moodle_id)) {
                        $coursecreators_assigned++;
                    }
                }
            }
        }

        // Suspend users no longer in Keycloak.
        $users_suspended = 0;
        foreach ($this->user_delta['to_suspend'] ?? [] as $moodle_user) {
            try {
                $moodle_user->suspended = 1;
                $moodle_user->timemodified = time();
                $DB->update_record('user', $moodle_user);
                $users_suspended++;
                if ($this->verbose) {
                    $this->log('warning', "Suspended user: {$moodle_user->username} (no longer in Keycloak)");
                }
            } catch (\Exception $e) {
                $this->add_error('user_suspend', "Failed to suspend {$moodle_user->username}: " . $e->getMessage());
            }
        }

        $this->stats['users_skipped'] = count($this->user_delta['to_skip']);
        $this->stats['users_suspended'] = $users_suspended;
        $this->stats['teachers_detected'] = $teachers_detected;
        $this->stats['coursecreators_assigned'] = $coursecreators_assigned;
        if ($this->verbose) {
            $this->log('info', "Skipped {$this->stats['users_skipped']} users (no changes needed)");
            if ($users_suspended > 0) {
                $this->log('warning', "Suspended {$users_suspended} users (no longer in Keycloak)");
            }
            $this->log('info', "User cache built: " . count($this->user_cache) . " users, {$teachers_detected} teachers detected");
            $this->log('info', "Coursecreator role assigned to {$coursecreators_assigned} teachers");
        }
    }

    /**
     * Phase 4: Fetch groups from Keycloak.
     */
    protected function run_phase_fetch_groups(): void
    {
        $this->set_phase(self::PHASE_FETCH_GROUPS, 40, 'Fetching groups from Keycloak...');

        $this->keycloak_groups = $this->client->get_all_groups_flat();
        $this->stats['groups_fetched'] = count($this->keycloak_groups);

        if ($this->verbose) {
            $this->log('info', "Fetched {$this->stats['groups_fetched']} groups from Keycloak");
        }
    }

    /**
     * Phase 5: Calculate group/course delta.
     *
     * Uses schema processor to match groups and determine course properties.
     */
    protected function run_phase_delta_groups(): void
    {
        global $DB;

        $this->set_phase(self::PHASE_DELTA_GROUPS, 45, 'Calculating course changes...');

        $this->group_delta = [
            'to_create' => [],
            'to_update' => [],
            'to_skip' => [],
            'unmatched' => [],
        ];

        // Get existing courses by idnumber.
        $existing_courses = [];
        $records = $DB->get_records('course', [], '', 'id, idnumber, shortname, fullname, category');
        foreach ($records as $course) {
            if (!empty($course->idnumber)) {
                $existing_courses[$course->idnumber] = $course;
            }
        }

        // Process each group with schema processor.
        $total = count($this->keycloak_groups);
        $processed = 0;

        foreach ($this->keycloak_groups as $group) {
            $processed++;

            if ($processed % 50 === 0) {
                $this->update_progress(
                    45 + (5 * $processed / max(1, $total)),
                    "Analyzing group $processed of $total..."
                );
            }

            // Process group through schema processor.
            $result = $this->schema_processor->process($group['name'], $group['id'] ?? '');

            if ($result === null) {
                // No schema matched - skip this group.
                $this->group_delta['unmatched'][] = [
                    'group' => $group,
                    'reason' => 'No matching naming schema',
                ];
                continue;
            }

            // Check if course already exists.
            $idnumber = $result['course_idnumber'];

            if (isset($existing_courses[$idnumber])) {
                // Course exists - check if update needed.
                $existing = $existing_courses[$idnumber];
                $needs_update = false;
                $changes = [];

                // Check if name changed.
                if ($existing->fullname !== $result['course_fullname']) {
                    $needs_update = true;
                    $changes[] = 'fullname';
                }

                if ($needs_update) {
                    $this->group_delta['to_update'][] = [
                        'group' => $group,
                        'schema_result' => $result,
                        'existing_course' => $existing,
                        'changes' => $changes,
                    ];
                } else {
                    $this->group_delta['to_skip'][] = [
                        'group' => $group,
                        'schema_result' => $result,
                        'course' => $existing,
                        'reason' => 'Course already exists and up to date',
                    ];
                }
            } else {
                // New course to create.
                $this->group_delta['to_create'][] = [
                    'group' => $group,
                    'schema_result' => $result,
                ];
            }
        }

        if ($this->verbose) {
            $this->log('info', sprintf(
                'Course delta: %d to create, %d to update, %d to skip, %d unmatched',
                count($this->group_delta['to_create']),
                count($this->group_delta['to_update']),
                count($this->group_delta['to_skip']),
                count($this->group_delta['unmatched'])
            ));
        }

        // Log unmatched groups for debugging (verbose only).
        if ($this->verbose && count($this->group_delta['unmatched']) > 0) {
            $unmatched_names = array_map(
                fn($item) => $item['group']['name'],
                array_slice($this->group_delta['unmatched'], 0, 5)
            );
            $this->log('info', 'Unmatched groups (first 5): ' . implode(', ', $unmatched_names));
        }
    }

    /**
     * Phase 6: Sync groups/courses.
     *
     * Uses schema results and category resolver for course creation.
     */
    protected function run_phase_sync_groups(): void
    {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $this->set_phase(self::PHASE_SYNC_GROUPS, 50, 'Creating courses...');

        $total = count($this->group_delta['to_create']) + count($this->group_delta['to_update']);
        $processed = 0;

        // Create new courses.
        foreach ($this->group_delta['to_create'] as &$item) {
            $processed++;
            $this->update_progress(
                50 + (10 * $processed / max(1, $total)),
                "Creating course $processed of $total..."
            );

            try {
                $group = $item['group'];
                $schema_result = $item['schema_result'];

                // Resolve category path to category ID.
                $category_id = $this->category_resolver->resolve($schema_result['category_path']);

                $course = new \stdClass();
                $course->fullname = $schema_result['course_fullname'];
                $course->shortname = $schema_result['course_shortname'];
                $course->idnumber = $schema_result['course_idnumber'];
                $course->category = $category_id;
                $course->format = 'topics';
                $course->visible = 1;
                $course->numsections = 10;

                $created_course = create_course($course);
                $this->stats['courses_created']++;
                if ($this->verbose) {
                    $this->log('success', "Created course: {$course->fullname} (Schema: {$schema_result['schema_id']})");
                }

                // Store course ID for enrollment phase.
                $item['course_id'] = $created_course->id;

            } catch (\Exception $e) {
                $this->stats['courses_errors']++;
                $this->add_error('course_create', "Failed to create course {$group['name']}: " . $e->getMessage());
            }
        }

        // Update existing courses.
        foreach ($this->group_delta['to_update'] as $item) {
            $processed++;
            $this->update_progress(
                50 + (10 * $processed / max(1, $total)),
                "Updating course $processed of $total..."
            );

            try {
                $existing = $item['existing_course'];
                $schema_result = $item['schema_result'];
                $changes = $item['changes'];

                // Update course name if changed.
                if (in_array('fullname', $changes)) {
                    global $DB;
                    $DB->set_field('course', 'fullname', $schema_result['course_fullname'], ['id' => $existing->id]);
                    $this->stats['courses_updated']++;
                    if ($this->verbose) {
                        $this->log('info', "Updated course: {$schema_result['course_fullname']}");
                    }
                }

            } catch (\Exception $e) {
                $this->stats['courses_errors']++;
                $this->add_error('course_update', "Failed to update course: " . $e->getMessage());
            }
        }

        $this->stats['courses_skipped'] = count($this->group_delta['to_skip']);

        // Log category creation stats.
        $cat_stats = $this->category_resolver->get_stats();
        if ($this->verbose && $cat_stats['categories_created'] > 0) {
            $this->log('success', "Created {$cat_stats['categories_created']} new categories");
        }
    }


    /**
     * Phase 7: Fetch group memberships.
     *
     * Only fetches members for groups that matched a schema (have courses).
     */
    protected function run_phase_fetch_members(): void
    {
        $this->set_phase(self::PHASE_FETCH_MEMBERS, 60, 'Fetching group memberships...');

        // Build set of group IDs that have/will have courses (from delta phase).
        $groups_needing_members = [];

        foreach ($this->group_delta['to_create'] ?? [] as $item) {
            $group_id = $item['group']['id'] ?? null;
            if ($group_id) {
                $groups_needing_members[$group_id] = true;
            }
        }
        foreach ($this->group_delta['to_update'] ?? [] as $item) {
            $group_id = $item['group']['id'] ?? null;
            if ($group_id) {
                $groups_needing_members[$group_id] = true;
            }
        }
        foreach ($this->group_delta['to_skip'] ?? [] as $item) {
            $group_id = $item['group']['id'] ?? null;
            if ($group_id) {
                $groups_needing_members[$group_id] = true;
            }
        }

        $total = count($groups_needing_members);
        $unmatched = count($this->group_delta['unmatched'] ?? []);
        $processed = 0;

        if ($this->verbose) {
            $this->log('info', "Fetching members for $total groups with courses (skipping $unmatched unmatched groups)");
        }

        foreach ($this->keycloak_groups as &$group) {
            // Skip groups that don't need members.
            if (!isset($groups_needing_members[$group['id']])) {
                $group['members'] = [];
                continue;
            }

            $processed++;

            if ($processed % 20 === 0 || $processed === $total) {
                $this->update_progress(
                    60 + (10 * $processed / max(1, $total)),
                    "Fetching members for group $processed of $total..."
                );
            }

            try {
                $group['members'] = $this->client->get_group_members($group['id']);
            } catch (\Exception $e) {
                $group['members'] = [];
                $this->add_error('fetch_members', "Failed to fetch members for {$group['name']}: " . $e->getMessage());
            }
        }

        if ($this->verbose) {
            $this->log('info', "Fetched memberships for $processed groups");
        }
    }

    /**
     * Determine if a Keycloak user is a teacher or admin based on attributes.
     *
     * Checks multiple sources:
     * 1. Username patterns for admin users (global-admin, admin, administrator)
     * 2. LDAP_ENTRY_DN containing "OU=Teachers" (linuxmuster.net style)
     * 3. Configured role attribute (default: sophomorixRole)
     * 4. Generic role/userType attributes
     *
     * @param array $kc_user Keycloak user data with attributes.
     * @return bool True if user is a teacher or admin.
     */
    protected function is_teacher_user(array $kc_user): bool
    {
        $username = strtolower($kc_user['username'] ?? '');
        $attributes = $kc_user['attributes'] ?? [];

        // Check for admin usernames - treat them as teachers (editingteacher role).
        $admin_usernames = ['global-admin', 'admin', 'administrator', 'moodle-admin', 'keycloak-admin'];
        if (in_array($username, $admin_usernames)) {
            return true;
        }

        // Check for admin prefix/suffix in username.
        if (strpos($username, 'admin') !== false) {
            return true;
        }

        // PRIMARY: Check LDAP_ENTRY_DN for OU=Teachers (linuxmuster.net / LDAP federated users).
        // Example DN: "CN=obe,OU=Teachers,OU=default-school,OU=SCHOOLS,DC=lmn,DC=kepler-freiburg,DC=de"
        if (isset($attributes['LDAP_ENTRY_DN'])) {
            $dn = is_array($attributes['LDAP_ENTRY_DN'])
                ? ($attributes['LDAP_ENTRY_DN'][0] ?? '')
                : $attributes['LDAP_ENTRY_DN'];
            if (stripos($dn, 'OU=Teachers') !== false) {
                return true;
            }
        }

        // Get configured attribute name and value from settings.
        $role_attribute = get_config('local_edulution', 'teacher_role_attribute') ?: 'sophomorixRole';
        $teacher_value = get_config('local_edulution', 'teacher_role_value') ?: 'teacher';

        // Check primary configured attribute.
        if (isset($attributes[$role_attribute])) {
            $role = is_array($attributes[$role_attribute])
                ? ($attributes[$role_attribute][0] ?? '')
                : $attributes[$role_attribute];
            if (strcasecmp($role, $teacher_value) === 0) {
                return true;
            }
        }

        // Fallback: Check sophomorixRole if different from configured.
        if ($role_attribute !== 'sophomorixRole' && isset($attributes['sophomorixRole'])) {
            $role = is_array($attributes['sophomorixRole'])
                ? ($attributes['sophomorixRole'][0] ?? '')
                : $attributes['sophomorixRole'];
            if (strcasecmp($role, $teacher_value) === 0) {
                return true;
            }
        }

        // Fallback: Check generic role attribute if different from configured.
        if ($role_attribute !== 'role' && isset($attributes['role'])) {
            $role = is_array($attributes['role'])
                ? ($attributes['role'][0] ?? '')
                : $attributes['role'];
            if (strcasecmp($role, $teacher_value) === 0) {
                return true;
            }
        }

        // Fallback: Check userType attribute.
        if (isset($attributes['userType'])) {
            $type = is_array($attributes['userType'])
                ? ($attributes['userType'][0] ?? '')
                : $attributes['userType'];
            if (strcasecmp($type, 'teacher') === 0 || strcasecmp($type, 'Teacher') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Phase 8: Calculate enrollment delta.
     *
     * Uses schema results from group delta phase to determine enrollments.
     */
    protected function run_phase_delta_enroll(): void
    {
        global $DB;

        $this->set_phase(self::PHASE_DELTA_ENROLL, 70, 'Calculating enrollment changes...');

        $this->enroll_delta = [
            'to_enroll' => [],
            'to_update_role' => [],
            'to_unenroll' => [],
            'to_skip' => [],
        ];

        // Track expected enrollments for unenrollment calculation.
        $this->expected_enrollments = [];

        // Build user lookup by username and email.
        $users_by_username = [];
        $users_by_email = [];
        $records = $DB->get_records('user', ['deleted' => 0], '', 'id, username, email');
        foreach ($records as $user) {
            $users_by_username[strtolower($user->username)] = $user->id;
            $users_by_email[strtolower($user->email)] = $user->id;
        }

        // Build course lookup by idnumber.
        $courses_by_idnumber = [];
        $records = $DB->get_records('course', [], '', 'id, idnumber');
        foreach ($records as $course) {
            if (!empty($course->idnumber)) {
                $courses_by_idnumber[$course->idnumber] = $course->id;
            }
        }

        // Get existing enrollments with their roles.
        $existing_enrollments = [];
        $sql = "SELECT DISTINCT ue.id, ue.userid, e.courseid, r.shortname as role
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
                LEFT JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id
                LEFT JOIN {role} r ON r.id = ra.roleid
                WHERE e.enrol = 'manual'";
        $records = $DB->get_records_sql($sql);
        foreach ($records as $record) {
            $key = $record->courseid . '_' . $record->userid;
            $existing_enrollments[$key] = $record->role ?? 'student';
        }

        // Build lookup from keycloak_groups by ID (these have the fetched members).
        $keycloak_groups_by_id = [];
        foreach ($this->keycloak_groups as $kg) {
            if (!empty($kg['id'])) {
                $keycloak_groups_by_id[$kg['id']] = $kg;
            }
        }

        // Combine all groups that have courses (created, updated, skipped).
        $groups_with_courses = array_merge(
            $this->group_delta['to_create'] ?? [],
            $this->group_delta['to_update'] ?? [],
            $this->group_delta['to_skip'] ?? []
        );

        // Process each group using schema-based role mapping.
        foreach ($groups_with_courses as $item) {
            $group = $item['group'];
            $schema_result = $item['schema_result'] ?? null;

            if (!$schema_result) {
                continue;
            }

            // Get course ID by idnumber.
            $course_id = $courses_by_idnumber[$schema_result['course_idnumber']] ?? null;
            if (!$course_id) {
                continue;
            }

            // Get members from the actual keycloak_groups (not the stale copy in group_delta).
            $group_id = $group['id'] ?? '';
            if (!empty($group_id) && isset($keycloak_groups_by_id[$group_id])) {
                $group = $keycloak_groups_by_id[$group_id];
            }

            // Get role mapping from schema.
            $role_map = $schema_result['role_map'] ?? ['default' => 'student'];

            // Process each member.
            foreach ($group['members'] ?? [] as $member) {
                $username_lower = strtolower($member['username'] ?? '');
                $email_lower = strtolower($member['email'] ?? '');

                $user_id = $users_by_username[$username_lower]
                    ?? $users_by_email[$email_lower]
                    ?? null;

                if (!$user_id) {
                    $this->enroll_delta['to_skip'][] = [
                        'member' => $member,
                        'group' => $group['name'],
                        'reason' => 'User not found in Moodle',
                    ];
                    continue;
                }

                $key = $course_id . '_' . $user_id;
                $this->expected_enrollments[$key] = true;

                // Determine role: check user cache for teacher status, then apply role map.
                $cached_user = $this->user_cache[$username_lower] ?? null;
                $is_teacher = $cached_user['is_teacher'] ?? false;

                // Apply schema role map.
                if ($is_teacher && isset($role_map['teacher'])) {
                    $role = $role_map['teacher'];
                } else {
                    $role = $role_map['default'] ?? 'student';
                }

                if (isset($existing_enrollments[$key])) {
                    $current_role = $existing_enrollments[$key];
                    if ($current_role !== $role) {
                        $this->enroll_delta['to_update_role'][] = [
                            'user_id' => $user_id,
                            'course_id' => $course_id,
                            'old_role' => $current_role,
                            'new_role' => $role,
                            'group' => $group['name'],
                        ];
                    } else {
                        $this->enroll_delta['to_skip'][] = [
                            'user_id' => $user_id,
                            'course_id' => $course_id,
                            'reason' => 'Already enrolled with correct role',
                        ];
                    }
                } else {
                    $this->enroll_delta['to_enroll'][] = [
                        'user_id' => $user_id,
                        'course_id' => $course_id,
                        'role' => $role,
                        'group' => $group['name'],
                    ];
                }
            }
        }

        // Check for unenrollments.
        $unenroll_enabled = get_config('local_edulution', 'sync_unenroll_users');
        if ($unenroll_enabled) {
            $this->calculate_unenrollments($courses_by_idnumber, $existing_enrollments);
        }

        if ($this->verbose) {
            $this->log('info', sprintf(
                'Enrollment delta: %d to enroll, %d to update role, %d to unenroll, %d to skip',
                count($this->enroll_delta['to_enroll']),
                count($this->enroll_delta['to_update_role']),
                count($this->enroll_delta['to_unenroll']),
                count($this->enroll_delta['to_skip'])
            ));
        }
    }

    /**
     * Calculate which users should be unenrolled.
     *
     * Finds users who are enrolled in sync-managed courses but are no longer
     * members of the corresponding Keycloak groups.
     *
     * @param array $courses_by_idnumber Course lookup by idnumber.
     * @param array $existing_enrollments Existing enrollments.
     */
    protected function calculate_unenrollments(array $courses_by_idnumber, array $existing_enrollments): void
    {
        global $DB;

        // Get sync-managed course IDs (courses with kc_ or kc_project_ idnumber).
        $sync_course_ids = [];
        foreach ($courses_by_idnumber as $idnumber => $course_id) {
            if (strpos($idnumber, 'kc_') === 0) {
                $sync_course_ids[$course_id] = true;
            }
        }

        // Find enrollments that exist but are not expected.
        foreach ($existing_enrollments as $key => $role) {
            // Parse the key to get course_id and user_id.
            list($course_id, $user_id) = explode('_', $key);

            // Only consider sync-managed courses.
            if (!isset($sync_course_ids[(int) $course_id])) {
                continue;
            }

            // If this enrollment is not expected, mark for unenrollment.
            if (!isset($this->expected_enrollments[$key])) {
                // Get user info for logging.
                $user = $DB->get_record('user', ['id' => $user_id], 'id, username');
                $course = $DB->get_record('course', ['id' => $course_id], 'id, shortname');

                $this->enroll_delta['to_unenroll'][] = [
                    'user_id' => (int) $user_id,
                    'course_id' => (int) $course_id,
                    'username' => $user->username ?? 'unknown',
                    'course_shortname' => $course->shortname ?? 'unknown',
                    'current_role' => $role,
                ];
            }
        }

        if ($this->verbose && count($this->enroll_delta['to_unenroll']) > 0) {
            $this->log('warning', sprintf(
                'Found %d enrollments to remove (users no longer in Keycloak groups)',
                count($this->enroll_delta['to_unenroll'])
            ));
        }
    }

    /**
     * Phase 9: Sync enrollments.
     */
    protected function run_phase_sync_enroll(): void
    {
        global $DB;

        $this->set_phase(self::PHASE_SYNC_ENROLL, 80, 'Processing enrollments...');

        $total = count($this->enroll_delta['to_enroll']);
        $processed = 0;

        // Get role IDs.
        $roles = $DB->get_records('role', [], '', 'shortname, id');
        $student_role = $roles['student']->id ?? 5;
        $teacher_role = $roles['editingteacher']->id ?? 3;

        // Get or create manual enrolment instances.
        $enrol_instances = [];

        foreach ($this->enroll_delta['to_enroll'] as $enroll) {
            $processed++;

            if ($processed % 50 === 0) {
                $this->update_progress(
                    80 + (15 * $processed / max(1, $total)),
                    "Enrolling user $processed of $total..."
                );
            }

            try {
                $course_id = $enroll['course_id'];
                $user_id = $enroll['user_id'];
                $role = $enroll['role'];

                // Get or create enrol instance.
                if (!isset($enrol_instances[$course_id])) {
                    $instance = $DB->get_record('enrol', [
                        'courseid' => $course_id,
                        'enrol' => 'manual',
                    ]);

                    if (!$instance) {
                        // Create manual enrolment instance.
                        $enrol_plugin = enrol_get_plugin('manual');
                        $instance_id = $enrol_plugin->add_instance(
                            get_course($course_id),
                            ['status' => ENROL_INSTANCE_ENABLED]
                        );
                        $instance = $DB->get_record('enrol', ['id' => $instance_id]);
                    }

                    $enrol_instances[$course_id] = $instance;
                }

                $instance = $enrol_instances[$course_id];
                $role_id = ($role === 'editingteacher') ? $teacher_role : $student_role;

                // Enrol user.
                $enrol_plugin = enrol_get_plugin('manual');
                $enrol_plugin->enrol_user($instance, $user_id, $role_id);

                $this->stats['enrollments_created']++;

            } catch (\Exception $e) {
                $this->stats['enrollments_errors']++;
                $this->add_error('enroll', "Failed to enroll user {$user_id} in course {$course_id}: " . $e->getMessage());
            }
        }

        // Process role updates for already-enrolled users.
        $total_updates = count($this->enroll_delta['to_update_role']);
        $processed_updates = 0;

        foreach ($this->enroll_delta['to_update_role'] as $update) {
            $processed_updates++;

            if ($processed_updates % 50 === 0 || $processed_updates === $total_updates) {
                $this->update_progress(
                    90 + (5 * $processed_updates / max(1, $total_updates)),
                    "Updating role $processed_updates of $total_updates..."
                );
            }

            try {
                $course_id = $update['course_id'];
                $user_id = $update['user_id'];
                $new_role = $update['new_role'];
                $old_role = $update['old_role'];

                $context = \context_course::instance($course_id);
                $new_role_id = ($new_role === 'editingteacher') ? $teacher_role : $student_role;
                $old_role_id = ($old_role === 'editingteacher') ? $teacher_role : $student_role;

                // Remove old role assignment.
                role_unassign($old_role_id, $user_id, $context->id);

                // Assign new role.
                role_assign($new_role_id, $user_id, $context->id);

                $this->stats['enrollments_updated']++;
                if ($this->verbose) {
                    $this->log('info', "Updated role for user {$user_id} in course {$course_id}: {$old_role} -> {$new_role}");
                }

            } catch (\Exception $e) {
                $this->stats['enrollments_errors']++;
                $this->add_error('role_update', "Failed to update role for user {$user_id}: " . $e->getMessage());
            }
        }

        // Process unenrollments (users no longer in Keycloak groups).
        $unenrollments_removed = 0;
        foreach ($this->enroll_delta['to_unenroll'] ?? [] as $unenroll) {
            try {
                $course_id = $unenroll['course_id'];
                $user_id = $unenroll['user_id'];
                $username = $unenroll['username'] ?? 'unknown';
                $course_shortname = $unenroll['course_shortname'] ?? 'unknown';

                // Get the manual enrol instance.
                $instance = $DB->get_record('enrol', [
                    'courseid' => $course_id,
                    'enrol' => 'manual',
                ]);

                if ($instance) {
                    $enrol_plugin = enrol_get_plugin('manual');
                    $enrol_plugin->unenrol_user($instance, $user_id);
                    $unenrollments_removed++;
                    if ($this->verbose) {
                        $this->log('warning', "Unenrolled user {$username} from course {$course_shortname} (no longer in Keycloak group)");
                    }
                }

            } catch (\Exception $e) {
                $this->stats['enrollments_errors']++;
                $this->add_error('unenroll', "Failed to unenroll user {$user_id} from course {$course_id}: " . $e->getMessage());
            }
        }

        $this->stats['enrollments_removed'] = $unenrollments_removed;
        $this->stats['enrollments_skipped'] = count($this->enroll_delta['to_skip']);
    }

    /**
     * Phase 10: Complete.
     */
    protected function run_phase_complete(): void
    {
        $this->set_phase(self::PHASE_COMPLETE, 100, 'Synchronization complete!');

        $this->log('success', sprintf(
            'Sync completed: %d users created, %d updated, %d suspended, %d teachers, %d courses created, %d enrolled, %d roles updated, %d unenrolled',
            $this->stats['users_created'],
            $this->stats['users_updated'],
            $this->stats['users_suspended'],
            $this->stats['teachers_detected'],
            $this->stats['courses_created'],
            $this->stats['enrollments_created'],
            $this->stats['enrollments_updated'],
            $this->stats['enrollments_removed']
        ));
    }

    /**
     * Set the current phase and update progress.
     *
     * @param string $phase Phase name.
     * @param int $progress Progress percentage.
     * @param string $message Status message.
     */
    protected function set_phase(string $phase, int $progress, string $message): void
    {
        $this->current_phase = $phase;
        $this->update_progress($progress, $message);
        if ($this->verbose) {
            $this->log('info', "Phase: $phase - $message");
        }
    }

    /**
     * Update progress.
     *
     * @param float $progress Progress percentage.
     * @param string $message Status message.
     */
    protected function update_progress(float $progress, string $message): void
    {
        if ($this->progress_callback) {
            call_user_func(
                $this->progress_callback,
                $this->current_phase,
                (int) $progress,
                $message,
                $this->stats
            );
        }
    }

    /**
     * Add a log entry.
     *
     * @param string $type Log type (info, success, warning, error).
     * @param string $message Log message.
     */
    protected function log(string $type, string $message): void
    {
        $this->log[] = [
            'type' => $type,
            'message' => $message,
            'time' => time(),
            'phase' => $this->current_phase,
        ];

        // Also output to console if running via CLI.
        if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
            mtrace("[$type] $message");
        }
    }

    /**
     * Add an error.
     *
     * @param string $type Error type.
     * @param string $message Error message.
     */
    protected function add_error(string $type, string $message): void
    {
        $this->errors[] = [
            'type' => $type,
            'message' => $message,
            'phase' => $this->current_phase,
        ];
        $this->log('error', $message);
    }

    /**
     * Store Keycloak user mapping.
     *
     * @param string $keycloak_id Keycloak user ID.
     * @param int $moodle_id Moodle user ID.
     * @param string $username Username.
     */
    protected function store_user_mapping(string $keycloak_id, int $moodle_id, string $username): void
    {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('local_edulution_user_map') || empty($keycloak_id)) {
            return;
        }

        try {
            $existing = $DB->get_record('local_edulution_user_map', ['keycloak_id' => $keycloak_id]);

            if ($existing) {
                $existing->moodle_userid = $moodle_id;
                $existing->keycloak_username = $username;
                $existing->timemodified = time();
                $DB->update_record('local_edulution_user_map', $existing);
            } else {
                $mapping = new \stdClass();
                $mapping->keycloak_id = $keycloak_id;
                $mapping->moodle_userid = $moodle_id;
                $mapping->keycloak_username = $username;
                $mapping->timecreated = time();
                $mapping->timemodified = time();
                $DB->insert_record('local_edulution_user_map', $mapping);
            }
        } catch (\Exception $e) {
            // Non-critical, only log in verbose mode.
            if ($this->verbose) {
                $this->log('warning', "Could not store user mapping: " . $e->getMessage());
            }
        }
    }

    /**
     * Assign coursecreator role to a user at system level.
     *
     * Teachers in linuxmuster.net should be able to create their own courses.
     *
     * @param int $user_id Moodle user ID.
     * @return bool True if role was newly assigned, false if already assigned or failed.
     */
    protected function assign_coursecreator_role(int $user_id): bool
    {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => 'coursecreator']);
        if (!$role) {
            return false;
        }

        $context = \context_system::instance();

        // Check if already assigned.
        $existing = $DB->get_record('role_assignments', [
            'roleid' => $role->id,
            'contextid' => $context->id,
            'userid' => $user_id,
        ]);

        if (!$existing) {
            role_assign($role->id, $user_id, $context->id);
            return true;
        }

        return false;
    }

    /**
     * Get current statistics.
     *
     * @return array Statistics.
     */
    public function get_stats(): array
    {
        return $this->stats;
    }

    /**
     * Get errors.
     *
     * @return array Errors.
     */
    public function get_errors(): array
    {
        return $this->errors;
    }

    /**
     * Get log entries.
     *
     * @return array Log entries.
     */
    public function get_log(): array
    {
        return $this->log;
    }
}
