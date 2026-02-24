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
 * Group synchronization between Keycloak and Moodle cohorts.
 *
 * Handles syncing Keycloak groups to Moodle cohorts and maintaining
 * group memberships across both systems.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/cohort/lib.php');

/**
 * Group synchronization class.
 *
 * Synchronizes Keycloak groups to Moodle cohorts and manages
 * memberships based on Keycloak group assignments.
 */
class group_sync {

    /** @var keycloak_client Keycloak API client */
    protected keycloak_client $client;

    /** @var user_sync User sync helper for ID mappings */
    protected user_sync $user_sync;

    /** @var sync_report Sync report for tracking results */
    protected sync_report $report;

    /** @var string Cohort ID prefix for Keycloak-synced cohorts */
    protected string $cohort_prefix = 'kc_';

    /** @var int Context ID for cohorts (system context by default) */
    protected int $context_id;

    /**
     * Constructor.
     *
     * @param keycloak_client $client Keycloak API client instance.
     * @param user_sync|null $user_sync User sync instance for ID mappings.
     */
    public function __construct(keycloak_client $client, ?user_sync $user_sync = null) {
        $this->client = $client;
        $this->user_sync = $user_sync ?? new user_sync($client);
        $this->report = new sync_report();
        $this->context_id = \context_system::instance()->id;
    }

    /**
     * Synchronize all Keycloak groups to Moodle cohorts.
     *
     * @return sync_report Sync report with results.
     */
    public function sync_groups(): sync_report {
        $this->report = new sync_report();

        try {
            $keycloak_groups = $this->client->get_all_groups();

            foreach ($keycloak_groups as $kc_group) {
                $this->sync_single_group($kc_group);
            }
        } catch (\Exception $e) {
            $this->report->add_error('sync_groups', $e->getMessage());
        }

        return $this->report;
    }

    /**
     * Synchronize a single Keycloak group to a Moodle cohort.
     *
     * @param array $keycloak_group Keycloak group data.
     * @return int|null Cohort ID or null on failure.
     */
    protected function sync_single_group(array $keycloak_group): ?int {
        $group_name = $keycloak_group['name'] ?? '';
        $group_id = $keycloak_group['id'] ?? '';

        if (empty($group_name) || empty($group_id)) {
            return null;
        }

        // Find or create the cohort.
        $cohort = $this->find_moodle_cohort($keycloak_group);

        if ($cohort) {
            // Update if needed.
            if ($this->needs_update($cohort, $keycloak_group)) {
                $this->update_cohort($cohort, $keycloak_group);
                $this->report->add_updated($group_name);
            }
        } else {
            // Create new cohort.
            $cohort = $this->create_moodle_cohort($keycloak_group);
            if ($cohort) {
                $this->report->add_created($group_name);
            }
        }

        return $cohort ? (int) $cohort->id : null;
    }

    /**
     * Synchronize group memberships from Keycloak to Moodle cohorts.
     *
     * @return sync_report Sync report with results.
     */
    public function sync_group_memberships(): sync_report {
        global $DB;

        $this->report = new sync_report();

        try {
            $keycloak_groups = $this->client->get_all_groups();

            foreach ($keycloak_groups as $kc_group) {
                $group_id = $kc_group['id'] ?? '';
                $group_name = $kc_group['name'] ?? '';

                if (empty($group_id)) {
                    continue;
                }

                // Find the cohort.
                $cohort = $this->find_moodle_cohort($kc_group);
                if (!$cohort) {
                    continue;
                }

                // Get current Keycloak members.
                $kc_members = [];
                try {
                    $kc_member_data = $this->client->get_user_groups($group_id);
                    // Actually need to get group members, not user's groups.
                    // This requires a different API call that gets members of a group.
                    // For now, we'll iterate through users and check their groups.
                } catch (\Exception $e) {
                    $this->report->add_error($group_name, 'Failed to get members: ' . $e->getMessage());
                    continue;
                }

                // Sync memberships using user data from Keycloak.
                $this->sync_cohort_members($cohort, $group_id);
            }
        } catch (\Exception $e) {
            $this->report->add_error('sync_memberships', $e->getMessage());
        }

        return $this->report;
    }

    /**
     * Sync members for a specific cohort based on Keycloak group membership.
     *
     * @param \stdClass $cohort Moodle cohort.
     * @param string $keycloak_group_id Keycloak group ID.
     */
    protected function sync_cohort_members(\stdClass $cohort, string $keycloak_group_id): void {
        global $DB;

        // Get all users and check their group memberships.
        $keycloak_users = [];
        $offset = 0;
        $batch_size = 100;

        do {
            $users = $this->client->get_users('', $batch_size, $offset);

            foreach ($users as $kc_user) {
                if (empty($kc_user['id'])) {
                    continue;
                }

                try {
                    $user_groups = $this->client->get_user_groups($kc_user['id']);
                    foreach ($user_groups as $group) {
                        if (($group['id'] ?? '') === $keycloak_group_id) {
                            $keycloak_users[$kc_user['id']] = $kc_user;
                            break;
                        }
                    }
                } catch (\Exception $e) {
                    // Skip users with errors.
                    continue;
                }
            }

            $offset += $batch_size;
        } while (count($users) === $batch_size);

        // Get current cohort members.
        $current_members = $DB->get_records('cohort_members', ['cohortid' => $cohort->id], '', 'userid');
        $current_member_ids = array_keys($current_members);

        // Add new members.
        foreach ($keycloak_users as $kc_id => $kc_user) {
            $moodle_userid = $this->user_sync->get_moodle_userid($kc_id);

            if (!$moodle_userid) {
                continue;
            }

            if (!in_array($moodle_userid, $current_member_ids)) {
                cohort_add_member($cohort->id, $moodle_userid);
            }
        }

        // Remove members no longer in Keycloak group.
        $keycloak_moodle_ids = [];
        foreach ($keycloak_users as $kc_id => $kc_user) {
            $mid = $this->user_sync->get_moodle_userid($kc_id);
            if ($mid) {
                $keycloak_moodle_ids[] = $mid;
            }
        }

        foreach ($current_member_ids as $member_id) {
            if (!in_array($member_id, $keycloak_moodle_ids)) {
                cohort_remove_member($cohort->id, $member_id);
            }
        }
    }

    /**
     * Find a Moodle cohort matching a Keycloak group.
     *
     * @param array $keycloak_group Keycloak group data.
     * @return \stdClass|null Moodle cohort or null.
     */
    public function find_moodle_cohort(array $keycloak_group): ?\stdClass {
        global $DB;

        $idnumber = $this->cohort_prefix . ($keycloak_group['id'] ?? '');

        return $DB->get_record('cohort', [
            'idnumber' => $idnumber,
            'contextid' => $this->context_id,
        ]) ?: null;
    }

    /**
     * Create a Moodle cohort from a Keycloak group.
     *
     * @param array $keycloak_group Keycloak group data.
     * @return \stdClass|null Created cohort or null on failure.
     */
    public function create_moodle_cohort(array $keycloak_group): ?\stdClass {
        global $DB;

        $group_name = $keycloak_group['name'] ?? '';
        $group_id = $keycloak_group['id'] ?? '';
        $group_path = $keycloak_group['path'] ?? '';

        if (empty($group_name) || empty($group_id)) {
            return null;
        }

        $cohort = new \stdClass();
        $cohort->contextid = $this->context_id;
        $cohort->name = $group_name;
        $cohort->idnumber = $this->cohort_prefix . $group_id;
        $cohort->description = "Synced from Keycloak group: {$group_path}";
        $cohort->descriptionformat = FORMAT_HTML;
        $cohort->visible = 1;
        $cohort->component = 'local_edulution';
        $cohort->timecreated = time();
        $cohort->timemodified = time();

        try {
            $cohort->id = cohort_add_cohort($cohort);
            return $cohort;
        } catch (\Exception $e) {
            $this->report->add_error($group_name, 'create cohort failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if cohort needs to be updated based on Keycloak group data.
     *
     * @param \stdClass $cohort Moodle cohort.
     * @param array $keycloak_group Keycloak group data.
     * @return bool True if update is needed.
     */
    protected function needs_update(\stdClass $cohort, array $keycloak_group): bool {
        $group_name = $keycloak_group['name'] ?? '';
        return $cohort->name !== $group_name;
    }

    /**
     * Update a cohort with Keycloak group data.
     *
     * @param \stdClass $cohort Moodle cohort.
     * @param array $keycloak_group Keycloak group data.
     */
    protected function update_cohort(\stdClass $cohort, array $keycloak_group): void {
        $cohort->name = $keycloak_group['name'] ?? $cohort->name;
        $cohort->timemodified = time();

        cohort_update_cohort($cohort);
    }

    /**
     * Set the cohort ID prefix.
     *
     * @param string $prefix Prefix string.
     * @return self
     */
    public function set_cohort_prefix(string $prefix): self {
        $this->cohort_prefix = $prefix;
        return $this;
    }

    /**
     * Set the context ID for cohorts.
     *
     * @param int $context_id Context ID.
     * @return self
     */
    public function set_context_id(int $context_id): self {
        $this->context_id = $context_id;
        return $this;
    }

    /**
     * Get the current sync report.
     *
     * @return sync_report Current report.
     */
    public function get_report(): sync_report {
        return $this->report;
    }

    /**
     * Get the Keycloak client.
     *
     * @return keycloak_client Client instance.
     */
    public function get_client(): keycloak_client {
        return $this->client;
    }
}
