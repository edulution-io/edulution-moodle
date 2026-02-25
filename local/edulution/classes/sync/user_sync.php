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
 * User synchronization between Keycloak and Moodle.
 *
 * Handles creating, updating, and linking Moodle users to their
 * Keycloak identities. Supports OIDC authentication method.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/user/lib.php');

/**
 * User synchronization class.
 *
 * Synchronizes users from Keycloak to Moodle, creating new users,
 * updating existing ones, and maintaining the mapping between systems.
 */
class user_sync
{

    /** @var keycloak_client Keycloak API client */
    protected keycloak_client $client;

    /** @var sync_report Sync report for tracking results */
    protected sync_report $report;

    /** @var string Authentication method for synced users */
    protected string $auth_method = 'oidc';

    /** @var array User field mappings from Keycloak to Moodle */
    protected array $field_mappings = [
        'username' => 'username',
        'email' => 'email',
        'firstName' => 'firstname',
        'lastName' => 'lastname',
    ];

    /** @var bool Whether to update existing users */
    protected bool $update_existing = true;

    /** @var bool Whether to create new users */
    protected bool $create_new = true;

    /**
     * Constructor.
     *
     * @param keycloak_client $client Keycloak API client instance.
     */
    public function __construct(keycloak_client $client)
    {
        $this->client = $client;
        $this->report = new sync_report();
    }

    /**
     * Synchronize all users from Keycloak to Moodle.
     *
     * @param int $batch_size Number of users to fetch per API call.
     * @return sync_report Sync report with results.
     */
    public function sync_users(int $batch_size = 100): sync_report
    {
        global $DB;

        $this->report = new sync_report();
        $offset = 0;

        try {
            do {
                $keycloak_users = $this->client->get_users('', $batch_size, $offset);

                foreach ($keycloak_users as $kc_user) {
                    try {
                        $this->sync_user($kc_user);
                    } catch (\Exception $e) {
                        $this->report->add_error(
                            $kc_user['username'] ?? 'unknown',
                            $e->getMessage()
                        );
                    }
                }

                $offset += $batch_size;
            } while (count($keycloak_users) === $batch_size);

        } catch (\Exception $e) {
            $this->report->add_error('sync_users', $e->getMessage());
        }

        return $this->report;
    }

    /**
     * Synchronize a single Keycloak user to Moodle.
     *
     * @param array $keycloak_user Keycloak user data.
     * @return int|null Moodle user ID or null on failure.
     */
    public function sync_user(array $keycloak_user): ?int
    {
        // Skip disabled users.
        if (!($keycloak_user['enabled'] ?? true)) {
            $this->report->add_skipped($keycloak_user['username'] ?? 'unknown', 'disabled');
            return null;
        }

        // Skip users without username or email.
        if (empty($keycloak_user['username']) || empty($keycloak_user['email'])) {
            $this->report->add_skipped(
                $keycloak_user['username'] ?? 'unknown',
                'missing required fields'
            );
            return null;
        }

        // Find existing Moodle user.
        $moodle_user = $this->find_moodle_user($keycloak_user);

        if ($moodle_user) {
            // Update existing user.
            if ($this->update_existing) {
                $this->update_moodle_user($moodle_user->id, $keycloak_user);
                $this->report->add_updated($keycloak_user['username']);
            } else {
                $this->report->add_skipped($keycloak_user['username'], 'update disabled');
            }

            // Ensure Keycloak link exists.
            $this->link_to_keycloak($moodle_user->id, $keycloak_user['id']);

            return (int) $moodle_user->id;
        }

        // Create new user.
        if ($this->create_new) {
            $userid = $this->create_moodle_user($keycloak_user);
            if ($userid) {
                $this->link_to_keycloak($userid, $keycloak_user['id']);
                $this->report->add_created($keycloak_user['username']);
                return $userid;
            }
        } else {
            $this->report->add_skipped($keycloak_user['username'], 'creation disabled');
        }

        return null;
    }

    /**
     * Find a Moodle user matching the Keycloak user.
     *
     * Searches by username first, then by email, then by Keycloak mapping.
     *
     * @param array $keycloak_user Keycloak user data.
     * @return \stdClass|null Moodle user record or null.
     */
    public function find_moodle_user(array $keycloak_user): ?\stdClass
    {
        global $DB;

        // First, try to find by username.
        $user = $DB->get_record('user', [
            'username' => \core_text::strtolower($keycloak_user['username']),
            'deleted' => 0,
        ]);

        if ($user) {
            return $user;
        }

        // Then, try to find by email.
        $user = $DB->get_record('user', [
            'email' => \core_text::strtolower($keycloak_user['email']),
            'deleted' => 0,
        ]);

        if ($user) {
            return $user;
        }

        // Finally, check the Keycloak mapping table (if it exists).
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_edulution_user_map') && !empty($keycloak_user['id'])) {
            $mapping = $DB->get_record('local_edulution_user_map', [
                'keycloak_id' => $keycloak_user['id'],
            ]);

            if ($mapping) {
                return $DB->get_record('user', ['id' => $mapping->moodle_userid, 'deleted' => 0]);
            }
        }

        return null;
    }

    /**
     * Create a new Moodle user from Keycloak data.
     *
     * @param array $keycloak_user Keycloak user data.
     * @return int|null Created user ID or null on failure.
     */
    public function create_moodle_user(array $keycloak_user): ?int
    {
        global $CFG;

        $user = new \stdClass();
        $user->auth = $this->auth_method;
        $user->confirmed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->username = \core_text::strtolower($keycloak_user['username']);
        $user->email = \core_text::strtolower($keycloak_user['email']);
        $user->firstname = $keycloak_user['firstName'] ?? '';
        $user->lastname = $keycloak_user['lastName'] ?? '';
        $user->lang = $CFG->lang;
        $user->timecreated = time();
        $user->timemodified = time();

        // Handle optional attributes.
        $attributes = $keycloak_user['attributes'] ?? [];
        if (isset($attributes['phone'][0])) {
            $user->phone1 = $attributes['phone'][0];
        }
        if (isset($attributes['institution'][0])) {
            $user->institution = $attributes['institution'][0];
        }
        if (isset($attributes['department'][0])) {
            $user->department = $attributes['department'][0];
        }

        try {
            $userid = user_create_user($user, false, false);
            return $userid ?: null;
        } catch (\Exception $e) {
            $this->report->add_error($keycloak_user['username'], 'create failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update an existing Moodle user with Keycloak data.
     *
     * @param int $moodleuserid Moodle user ID.
     * @param array $keycloak_user Keycloak user data.
     * @return bool True on success.
     */
    public function update_moodle_user(int $moodleuserid, array $keycloak_user): bool
    {
        $user = new \stdClass();
        $user->id = $moodleuserid;
        $user->email = \core_text::strtolower($keycloak_user['email']);
        $user->firstname = $keycloak_user['firstName'] ?? '';
        $user->lastname = $keycloak_user['lastName'] ?? '';
        $user->timemodified = time();

        // Handle optional attributes.
        $attributes = $keycloak_user['attributes'] ?? [];
        if (isset($attributes['phone'][0])) {
            $user->phone1 = $attributes['phone'][0];
        }
        if (isset($attributes['institution'][0])) {
            $user->institution = $attributes['institution'][0];
        }
        if (isset($attributes['department'][0])) {
            $user->department = $attributes['department'][0];
        }

        try {
            user_update_user($user, false, false);
            return true;
        } catch (\Exception $e) {
            $this->report->add_error(
                $keycloak_user['username'],
                'update failed: ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Store the mapping between a Moodle user and Keycloak ID.
     *
     * @param int $moodleuserid Moodle user ID.
     * @param string $keycloakid Keycloak user ID (UUID).
     * @return bool True on success.
     */
    public function link_to_keycloak(int $moodleuserid, string $keycloakid): bool
    {
        global $DB;

        // Check if mapping already exists.
        $existing = $DB->get_record('local_edulution_user_map', [
            'moodle_userid' => $moodleuserid,
        ]);

        if ($existing) {
            if ($existing->keycloak_id !== $keycloakid) {
                $existing->keycloak_id = $keycloakid;
                $existing->timemodified = time();
                $DB->update_record('local_edulution_user_map', $existing);
            }
            return true;
        }

        // Create new mapping.
        $mapping = new \stdClass();
        $mapping->moodle_userid = $moodleuserid;
        $mapping->keycloak_id = $keycloakid;
        $mapping->timecreated = time();
        $mapping->timemodified = time();

        try {
            $DB->insert_record('local_edulution_user_map', $mapping);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get the Keycloak ID for a Moodle user.
     *
     * @param int $moodleuserid Moodle user ID.
     * @return string|null Keycloak user ID or null if not mapped.
     */
    public function get_user_mapping(int $moodleuserid): ?string
    {
        global $DB;

        $mapping = $DB->get_record('local_edulution_user_map', [
            'moodle_userid' => $moodleuserid,
        ]);

        return $mapping ? $mapping->keycloak_id : null;
    }

    /**
     * Get the Moodle user ID for a Keycloak ID.
     *
     * @param string $keycloakid Keycloak user ID (UUID).
     * @return int|null Moodle user ID or null if not mapped.
     */
    public function get_moodle_userid(string $keycloakid): ?int
    {
        global $DB;

        $mapping = $DB->get_record('local_edulution_user_map', [
            'keycloak_id' => $keycloakid,
        ]);

        return $mapping ? (int) $mapping->moodle_userid : null;
    }

    /**
     * Set whether to update existing users.
     *
     * @param bool $update Whether to update existing users.
     * @return self
     */
    public function set_update_existing(bool $update): self
    {
        $this->update_existing = $update;
        return $this;
    }

    /**
     * Set whether to create new users.
     *
     * @param bool $create Whether to create new users.
     * @return self
     */
    public function set_create_new(bool $create): self
    {
        $this->create_new = $create;
        return $this;
    }

    /**
     * Set the authentication method for new users.
     *
     * @param string $auth Authentication method (e.g., 'oidc', 'manual').
     * @return self
     */
    public function set_auth_method(string $auth): self
    {
        $this->auth_method = $auth;
        return $this;
    }

    /**
     * Get the current sync report.
     *
     * @return sync_report Current report.
     */
    public function get_report(): sync_report
    {
        return $this->report;
    }

    /**
     * Get the Keycloak client.
     *
     * @return keycloak_client Client instance.
     */
    public function get_client(): keycloak_client
    {
        return $this->client;
    }
}
