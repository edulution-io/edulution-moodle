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
 * Keycloak API client for user and group synchronization.
 *
 * Provides methods to interact with the Keycloak Admin API using OAuth2
 * client credentials flow. Supports:
 * - User CRUD operations
 * - Group management
 * - User-group membership management
 * - Access token caching
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * Keycloak API client class.
 *
 * Uses OAuth2 client credentials flow for authentication and provides
 * methods for user and group management via the Keycloak Admin API.
 */
class keycloak_client
{

    /** @var string Keycloak server base URL */
    protected string $url;

    /** @var string Keycloak realm name */
    protected string $realm;

    /** @var string OAuth2 client ID */
    protected string $client_id;

    /** @var string OAuth2 client secret */
    protected string $client_secret;

    /** @var string|null Cached access token */
    protected ?string $access_token = null;

    /** @var int Token expiration timestamp */
    protected int $token_expires = 0;

    /** @var int cURL timeout in seconds */
    protected int $timeout = 30;

    /** @var array Session statistics */
    protected array $stats = [
        'api_calls' => 0,
        'errors' => 0,
    ];

    /**
     * Constructor.
     *
     * @param string $url Keycloak server base URL.
     * @param string $realm Keycloak realm name.
     * @param string $client_id OAuth2 client ID.
     * @param string $client_secret OAuth2 client secret.
     */
    public function __construct(string $url, string $realm, string $client_id, string $client_secret)
    {
        $this->url = rtrim($url, '/');
        $this->realm = $realm;
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
    }

    /**
     * Get an access token using OAuth2 client credentials flow.
     *
     * Tokens are cached and reused until they expire.
     *
     * @param bool $force Force token refresh even if not expired.
     * @return string Access token.
     * @throws \moodle_exception If authentication fails.
     */
    public function get_access_token(bool $force = false): string
    {
        // Return cached token if still valid (with 30-second buffer).
        if (!$force && $this->access_token && time() < ($this->token_expires - 30)) {
            return $this->access_token;
        }

        $token_url = "{$this->url}/realms/{$this->realm}/protocol/openid-connect/token";

        $postdata = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
        ], '', '&');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $token_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postdata,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->stats['errors']++;
            throw new \moodle_exception('keycloak_curl_error', 'local_edulution', '', $error);
        }

        if ($httpcode !== 200) {
            $this->stats['errors']++;
            $error_data = json_decode($response, true);
            $error_msg = $error_data['error_description'] ?? $error_data['error'] ?? "HTTP {$httpcode}";
            throw new \moodle_exception('keycloak_auth_failed', 'local_edulution', '', $error_msg);
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            $this->stats['errors']++;
            throw new \moodle_exception('keycloak_no_token', 'local_edulution');
        }

        $this->access_token = $data['access_token'];
        $this->token_expires = time() + ($data['expires_in'] ?? 300);

        return $this->access_token;
    }

    /**
     * Get users from Keycloak.
     *
     * @param string $search Optional search string for username, email, first/last name.
     * @param int $max Maximum number of users to return.
     * @param int $first First result offset (for pagination).
     * @return array Array of user objects with full attributes (LDAP_ENTRY_DN, etc.).
     * @throws \moodle_exception On API errors.
     */
    public function get_users(string $search = '', int $max = 100, int $first = 0): array
    {
        $params = [
            'max' => $max,
            'first' => $first,
            'briefRepresentation' => 'false', // Include full user data with attributes (LDAP_ENTRY_DN).
        ];

        if ($search !== '') {
            $params['search'] = $search;
        }

        return $this->api_request('GET', 'users', $params);
    }

    /**
     * Get all users from Keycloak with automatic pagination.
     *
     * @return array All user objects.
     * @throws \moodle_exception On API errors.
     */
    public function get_all_users(): array
    {
        $all_users = [];
        $batch_size = 100;
        $offset = 0;

        do {
            $users = $this->get_users('', $batch_size, $offset);
            $all_users = array_merge($all_users, $users);
            $offset += $batch_size;
        } while (count($users) === $batch_size);

        return $all_users;
    }

    /**
     * Get a single user by ID.
     *
     * @param string $id Keycloak user ID (UUID).
     * @return array User data.
     * @throws \moodle_exception On API errors.
     */
    public function get_user(string $id): array
    {
        return $this->api_request('GET', "users/{$id}");
    }

    /**
     * Count total users in Keycloak.
     *
     * @return int Total user count.
     */
    public function count_users(): int
    {
        try {
            $token = $this->get_access_token();
            $url = "{$this->url}/admin/realms/{$this->realm}/users/count";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_TIMEOUT => 30,
            ]);

            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpcode === 200 && is_numeric($response)) {
                return (int) $response;
            }

            // Fallback: count by fetching users
            $users = $this->get_all_users();
            return count($users);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get all groups from Keycloak.
     *
     * @param int $max Maximum number of groups to return.
     * @param int $first First result offset (for pagination).
     * @return array Array of group objects.
     * @throws \moodle_exception On API errors.
     */
    public function get_groups(int $max = 100, int $first = 0): array
    {
        $params = [
            'max' => $max,
            'first' => $first,
        ];

        return $this->api_request('GET', 'groups', $params);
    }

    /**
     * Get all groups from Keycloak with automatic pagination.
     *
     * @return array All group objects.
     * @throws \moodle_exception On API errors.
     */
    public function get_all_groups(): array
    {
        $all_groups = [];
        $batch_size = 100;
        $offset = 0;

        do {
            $groups = $this->get_groups($batch_size, $offset);
            $all_groups = array_merge($all_groups, $groups);
            $offset += $batch_size;
        } while (count($groups) === $batch_size);

        return $all_groups;
    }

    /**
     * Get groups that a user belongs to.
     *
     * @param string $userid Keycloak user ID (UUID).
     * @return array Array of group objects.
     * @throws \moodle_exception On API errors.
     */
    public function get_user_groups(string $userid): array
    {
        return $this->api_request('GET', "users/{$userid}/groups");
    }

    /**
     * Create a new user in Keycloak.
     *
     * @param array $data User data (username, email, firstName, lastName, enabled, etc.).
     * @return string The created user's ID (from Location header).
     * @throws \moodle_exception On API errors.
     */
    public function create_user(array $data): string
    {
        $response = $this->api_request('POST', 'users', [], $data, true);

        // The user ID is returned in the Location header.
        if (isset($response['location'])) {
            $parts = explode('/', $response['location']);
            return end($parts);
        }

        throw new \moodle_exception('keycloak_create_user_failed', 'local_edulution');
    }

    /**
     * Update an existing user in Keycloak.
     *
     * @param string $id Keycloak user ID (UUID).
     * @param array $data User data to update.
     * @return bool True on success.
     * @throws \moodle_exception On API errors.
     */
    public function update_user(string $id, array $data): bool
    {
        $this->api_request('PUT', "users/{$id}", [], $data);
        return true;
    }

    /**
     * Add a user to a group.
     *
     * @param string $userid Keycloak user ID (UUID).
     * @param string $groupid Keycloak group ID (UUID).
     * @return bool True on success.
     * @throws \moodle_exception On API errors.
     */
    public function add_user_to_group(string $userid, string $groupid): bool
    {
        $this->api_request('PUT', "users/{$userid}/groups/{$groupid}");
        return true;
    }

    /**
     * Remove a user from a group.
     *
     * @param string $userid Keycloak user ID (UUID).
     * @param string $groupid Keycloak group ID (UUID).
     * @return bool True on success.
     * @throws \moodle_exception On API errors.
     */
    public function remove_user_from_group(string $userid, string $groupid): bool
    {
        $this->api_request('DELETE', "users/{$userid}/groups/{$groupid}");
        return true;
    }

    /**
     * Get all groups flattened (including subgroups).
     *
     * @return array All group objects in a flat array.
     * @throws \moodle_exception On API errors.
     */
    public function get_all_groups_flat(): array
    {
        $groups = $this->get_all_groups();
        return $this->flatten_groups($groups);
    }

    /**
     * Recursively flatten groups and their subgroups.
     *
     * @param array $groups Groups array (may include nested subGroups).
     * @return array Flattened array of groups.
     */
    protected function flatten_groups(array $groups): array
    {
        $flat = [];

        foreach ($groups as $group) {
            // Add the group itself.
            $group_copy = $group;
            unset($group_copy['subGroups']); // Remove nested children from copy.
            $flat[] = $group_copy;

            // Recursively add subgroups.
            if (!empty($group['subGroups'])) {
                $flat = array_merge($flat, $this->flatten_groups($group['subGroups']));
            }
        }

        return $flat;
    }

    /**
     * Get members of a specific group.
     *
     * @param string $groupid Keycloak group ID (UUID).
     * @param int $first Offset for pagination.
     * @param int $max Maximum number of results.
     * @return array Array of user objects.
     * @throws \moodle_exception On API errors.
     */
    public function get_group_members(string $groupid, int $first = 0, int $max = 100): array
    {
        return $this->api_request('GET', "groups/{$groupid}/members", [
            'first' => $first,
            'max' => $max,
            'briefRepresentation' => 'false', // Include full user data with attributes.
        ]);
    }

    /**
     * Get all members of a group with automatic pagination.
     *
     * @param string $groupid Keycloak group ID (UUID).
     * @return array All member user objects.
     * @throws \moodle_exception On API errors.
     */
    public function get_all_group_members(string $groupid): array
    {
        $all_members = [];
        $batch_size = 100;
        $offset = 0;

        do {
            $members = $this->get_group_members($groupid, $offset, $batch_size);
            $all_members = array_merge($all_members, $members);
            $offset += $batch_size;
        } while (count($members) === $batch_size);

        return $all_members;
    }

    /**
     * Test the connection to Keycloak.
     *
     * Validates credentials by attempting to obtain an access token
     * and making a test API call.
     *
     * @return array Test results with 'success', 'message', and optionally 'realm' keys.
     */
    public function test_connection(): array
    {
        try {
            // First, try to get an access token.
            $this->get_access_token(true);

            // Then, try to access the realm info.
            $this->api_request('GET', '');

            return [
                'success' => true,
                'message' => get_string('keycloak_connected', 'local_edulution', $this->realm),
                'realm' => $this->realm,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Make an API request to the Keycloak Admin API.
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE).
     * @param string $endpoint API endpoint (relative to /admin/realms/{realm}/).
     * @param array $params Query parameters for GET requests.
     * @param array|null $data Request body data for POST/PUT requests.
     * @param bool $return_headers Whether to return headers (for Location header).
     * @return array Response data or headers.
     * @throws \moodle_exception On API errors.
     */
    protected function api_request(
        string $method,
        string $endpoint,
        array $params = [],
        ?array $data = null,
        bool $return_headers = false
    ): array {
        $this->stats['api_calls']++;

        $token = $this->get_access_token();

        // Build URL.
        $url = "{$this->url}/admin/realms/{$this->realm}";
        if ($endpoint !== '') {
            $url .= "/{$endpoint}";
        }
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ];

        if ($return_headers) {
            $options[CURLOPT_HEADER] = true;
        }

        switch ($method) {
            case 'POST':
                $options[CURLOPT_POST] = true;
                if ($data !== null) {
                    $options[CURLOPT_POSTFIELDS] = json_encode($data);
                }
                break;

            case 'PUT':
                $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
                if ($data !== null) {
                    $options[CURLOPT_POSTFIELDS] = json_encode($data);
                }
                break;

            case 'DELETE':
                $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                break;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->stats['errors']++;
            throw new \moodle_exception('keycloak_curl_error', 'local_edulution', '', $error);
        }

        // Handle 401 - retry with fresh token.
        if ($httpcode === 401) {
            $this->access_token = null;
            return $this->api_request($method, $endpoint, $params, $data, $return_headers);
        }

        // Parse headers if requested.
        if ($return_headers) {
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $header_str = substr($response, 0, $header_size);
            $body = substr($response, $header_size);

            // Parse Location header.
            $location = null;
            if (preg_match('/Location:\s*(.+)/i', $header_str, $matches)) {
                $location = trim($matches[1]);
            }

            if ($httpcode === 201 && $location) {
                return ['location' => $location];
            }
        }

        // Check for errors.
        if ($httpcode < 200 || $httpcode >= 300) {
            $this->stats['errors']++;
            $error_data = json_decode($response, true);
            $error_msg = $error_data['errorMessage'] ?? $error_data['error'] ?? 'Unknown error';
            $error_info = (object) ['code' => $httpcode, 'message' => $error_msg];
            throw new \moodle_exception('keycloak_api_error', 'local_edulution', '', $error_info);
        }

        // Empty response is valid for some operations.
        if (empty($response) || $response === '""') {
            return [];
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->stats['errors']++;
            throw new \moodle_exception('keycloak_json_error', 'local_edulution', '', json_last_error_msg());
        }

        return $result;
    }

    /**
     * Get session statistics.
     *
     * @return array Statistics array with 'api_calls' and 'errors' keys.
     */
    public function get_stats(): array
    {
        return $this->stats;
    }

    /**
     * Get the configured realm name.
     *
     * @return string Realm name.
     */
    public function get_realm(): string
    {
        return $this->realm;
    }

    /**
     * Get the configured base URL.
     *
     * @return string Base URL.
     */
    public function get_url(): string
    {
        return $this->url;
    }

    /**
     * Set cURL timeout.
     *
     * @param int $timeout Timeout in seconds.
     * @return self
     */
    public function set_timeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }
}
