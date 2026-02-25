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
 * Cookie-based authentication backend for SSO.
 *
 * This class validates JWT tokens from cookies and automatically logs in users.
 * Designed for iframe embedding scenarios where the portal sets a cookie with
 * a JWT token from Keycloak.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\auth;

defined('MOODLE_INTERNAL') || die();

/**
 * Cookie authentication backend for JWT-based SSO.
 */
class cookie_auth_backend
{

    /** @var string Session key for marking authenticated sessions */
    const SESSION_KEY = 'local_edulution_cookie_auth';

    /** @var string Session key for storing token hash */
    const SESSION_TOKEN_HASH = 'local_edulution_token_hash';

    /** @var string Session key for storing token expiration */
    const SESSION_TOKEN_EXP = 'local_edulution_token_exp';

    /** @var int Cache TTL for public key (1 hour) */
    const KEY_CACHE_TTL = 3600;

    /** @var array Supported algorithms */
    const SUPPORTED_ALGORITHMS = ['RS256', 'RS384', 'RS512'];

    /** @var array Algorithm to OpenSSL constant mapping */
    const ALGORITHM_MAP = [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
    ];

    /**
     * Try to auto-login the user based on JWT cookie.
     *
     * IMPORTANT: This method NEVER calls require_logout() because that can
     * trigger a Keycloak/OIDC cascade logout which would log the user out
     * of the edulution portal as well.
     *
     * @return bool True if user is logged in (or was already), false otherwise.
     */
    public function try_auto_login(): bool
    {
        global $SESSION, $USER;

        // Check if cookie auth is enabled.
        if (!$this->is_enabled()) {
            return false;
        }

        // Get the JWT from cookie.
        $token = $this->get_token_from_cookie();

        // No token cookie present - don't interfere with anything.
        if (empty($token)) {
            return false;
        }

        // If user is already logged in, just keep them logged in.
        // NEVER log out an already-authenticated user - this prevents
        // cascade logouts that would also log them out of edulution.
        if (isloggedin() && !isguestuser()) {
            $token_hash = hash('sha256', $token);

            // Update session markers if token changed (e.g. token was refreshed).
            if (!isset($SESSION->{self::SESSION_TOKEN_HASH}) ||
                $SESSION->{self::SESSION_TOKEN_HASH} !== $token_hash) {
                $payload = $this->decode_token_payload($token);
                if ($payload) {
                    $SESSION->{self::SESSION_TOKEN_HASH} = $token_hash;
                    $SESSION->{self::SESSION_TOKEN_EXP} = $payload['exp'] ?? (time() + 3600);
                }
            }

            return true;
        }

        // User is NOT logged in. Try auto-login with the token.

        // Check session cache to avoid redundant validation on every request.
        $token_hash = hash('sha256', $token);
        if (
            isset($SESSION->{self::SESSION_TOKEN_HASH}) &&
            $SESSION->{self::SESSION_TOKEN_HASH} === $token_hash &&
            isset($SESSION->{self::SESSION_TOKEN_EXP}) &&
            $SESSION->{self::SESSION_TOKEN_EXP} > time()
        ) {
            // Token already validated and not expired, but user is not logged in.
            // This can happen if the session was lost. Clear markers and retry.
            $this->clear_session_markers();
        }

        // Validate the token (checks signature, expiration, issuer).
        $payload = $this->validate_token($token);
        if ($payload === null) {
            $this->clear_session_markers();
            return false;
        }

        // Extract username from token.
        $username = $this->extract_username($payload);
        if (empty($username)) {
            $this->log_debug('No username found in token');
            return false;
        }

        // Find the user in Moodle.
        $user = $this->find_user($username, $payload);
        if (!$user) {
            $this->log_debug("User not found: {$username}");
            return false;
        }

        // Check if user is enabled.
        if ($user->suspended || $user->deleted) {
            $this->log_debug("User is suspended or deleted: {$username}");
            return false;
        }

        // Log in the user.
        if ($this->login_user($user)) {
            // Set session markers.
            $SESSION->{self::SESSION_KEY} = true;
            $SESSION->{self::SESSION_TOKEN_HASH} = $token_hash;
            $SESSION->{self::SESSION_TOKEN_EXP} = $payload['exp'] ?? (time() + 3600);

            $this->log_debug("User logged in via cookie auth: {$username}");
            return true;
        }

        return false;
    }

    /**
     * Check if cookie auth is enabled (environment variables take precedence).
     *
     * @return bool True if enabled.
     */
    public function is_enabled(): bool
    {
        return (bool) \local_edulution_get_config('cookie_auth_enabled');
    }

    /**
     * Get the JWT token from the configured cookie.
     *
     * @return string|null The token or null.
     */
    protected function get_token_from_cookie(): ?string
    {
        $cookie_name = \local_edulution_get_config('cookie_auth_cookie_name', 'authToken');

        return $_COOKIE[$cookie_name] ?? null;
    }

    /**
     * Validate a JWT token.
     *
     * @param string $token The JWT token.
     * @return array|null The payload if valid, null otherwise.
     */
    public function validate_token(string $token): ?array
    {
        // Split the token.
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            $this->log_debug('Invalid token format: not 3 parts');
            return null;
        }

        [$header_b64, $payload_b64, $signature_b64] = $parts;

        // Decode header.
        $header = $this->base64url_decode($header_b64);
        if ($header === null) {
            $this->log_debug('Failed to decode token header');
            return null;
        }
        $header = json_decode($header, true);
        if (!is_array($header)) {
            $this->log_debug('Invalid token header JSON');
            return null;
        }

        // Check algorithm.
        $configured_algorithm = get_config('local_edulution', 'cookie_auth_algorithm') ?: 'RS256';
        $token_algorithm = $header['alg'] ?? '';
        if ($token_algorithm !== $configured_algorithm) {
            $this->log_debug("Algorithm mismatch: expected {$configured_algorithm}, got {$token_algorithm}");
            return null;
        }

        // Decode payload.
        $payload = $this->base64url_decode($payload_b64);
        if ($payload === null) {
            $this->log_debug('Failed to decode token payload');
            return null;
        }
        $payload = json_decode($payload, true);
        if (!is_array($payload)) {
            $this->log_debug('Invalid token payload JSON');
            return null;
        }

        // Check expiration.
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            $this->log_debug('Token has expired');
            return null;
        }

        // Check not-before.
        if (isset($payload['nbf']) && $payload['nbf'] > time()) {
            $this->log_debug('Token not yet valid (nbf)');
            return null;
        }

        // Check issuer.
        $expected_issuer = get_config('local_edulution', 'cookie_auth_issuer');
        if (empty($expected_issuer)) {
            $expected_issuer = get_config('local_edulution', 'cookie_auth_realm_url');
        }
        if (!empty($expected_issuer) && isset($payload['iss'])) {
            if ($payload['iss'] !== $expected_issuer) {
                $this->log_debug("Issuer mismatch: expected {$expected_issuer}, got {$payload['iss']}");
                return null;
            }
        }

        // Verify signature.
        if (!$this->verify_signature($header_b64, $payload_b64, $signature_b64, $configured_algorithm)) {
            $this->log_debug('Signature verification failed');
            return null;
        }

        return $payload;
    }

    /**
     * Decode token payload without verification (for checking username).
     *
     * @param string $token The JWT token.
     * @return array|null The payload or null.
     */
    protected function decode_token_payload(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = $this->base64url_decode($parts[1]);
        if ($payload === null) {
            return null;
        }

        return json_decode($payload, true) ?: null;
    }

    /**
     * Verify the JWT signature.
     *
     * @param string $header_b64 Base64url encoded header.
     * @param string $payload_b64 Base64url encoded payload.
     * @param string $signature_b64 Base64url encoded signature.
     * @param string $algorithm The algorithm to use.
     * @return bool True if signature is valid.
     */
    protected function verify_signature(string $header_b64, string $payload_b64, string $signature_b64, string $algorithm): bool
    {
        $data = $header_b64 . '.' . $payload_b64;
        $signature = $this->base64url_decode($signature_b64);

        if ($signature === null) {
            return false;
        }

        $public_key = $this->get_public_key();
        if (empty($public_key)) {
            $this->log_debug('No public key available');
            return false;
        }

        $algo_constant = self::ALGORITHM_MAP[$algorithm] ?? OPENSSL_ALGO_SHA256;

        $key = openssl_pkey_get_public($public_key);
        if ($key === false) {
            $this->log_debug('Failed to parse public key');
            return false;
        }

        $result = openssl_verify($data, $signature, $key, $algo_constant);

        return $result === 1;
    }

    /**
     * Get the public key for JWT verification.
     *
     * @return string|null The PEM-formatted public key or null.
     */
    protected function get_public_key(): ?string
    {
        // Check for direct public key configuration.
        $public_key = get_config('local_edulution', 'cookie_auth_public_key');
        if (!empty($public_key)) {
            // If it's a file path, read it.
            if (strpos($public_key, '-----BEGIN') === false && file_exists($public_key)) {
                $public_key = file_get_contents($public_key);
            }
            return $public_key ?: null;
        }

        // Try to fetch from Keycloak realm (environment variables take precedence).
        $realm_url = get_config('local_edulution', 'cookie_auth_realm_url');
        if (empty($realm_url)) {
            // Fall back to main Keycloak settings.
            $keycloak_url = \local_edulution_get_config('keycloak_url');
            $realm = \local_edulution_get_config('keycloak_realm', 'master');
            if (!empty($keycloak_url) && !empty($realm)) {
                $realm_url = rtrim($keycloak_url, '/') . '/realms/' . $realm;
            }
        }

        if (empty($realm_url)) {
            return null;
        }

        return $this->fetch_public_key_from_realm($realm_url);
    }

    /**
     * Fetch and cache the public key from Keycloak realm.
     *
     * @param string $realm_url The realm URL.
     * @return string|null The public key or null.
     */
    protected function fetch_public_key_from_realm(string $realm_url): ?string
    {
        // Check cache first.
        $cached_key = get_config('local_edulution', 'cookie_auth_cached_public_key');
        $cached_time = get_config('local_edulution', 'cookie_auth_cached_public_key_time');

        if (!empty($cached_key) && !empty($cached_time) && (time() - $cached_time) < self::KEY_CACHE_TTL) {
            return $cached_key;
        }

        // Fetch from realm using native curl (Moodle's \curl class may not be loaded yet).
        $verify_ssl = \local_edulution_get_config('verify_ssl', true);

        $ch = curl_init($realm_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => (bool) $verify_ssl,
        ]);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno || empty($response)) {
            $this->log_debug("Failed to fetch realm info from: {$realm_url}");
            return $cached_key ?: null; // Return cached key as fallback.
        }

        $data = json_decode($response, true);
        if (!isset($data['public_key'])) {
            $this->log_debug('No public_key in realm response');
            return $cached_key ?: null;
        }

        // Convert to PEM format.
        $pem = "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split($data['public_key'], 64, "\n") .
            "-----END PUBLIC KEY-----";

        // Cache the key.
        set_config('cookie_auth_cached_public_key', $pem, 'local_edulution');
        set_config('cookie_auth_cached_public_key_time', time(), 'local_edulution');

        return $pem;
    }

    /**
     * Extract username from JWT payload.
     *
     * @param array $payload The JWT payload.
     * @return string|null The username or null.
     */
    protected function extract_username(array $payload): ?string
    {
        $claim = get_config('local_edulution', 'cookie_auth_user_claim');
        if (empty($claim)) {
            $claim = 'preferred_username'; // Default.
        }

        // Support dot notation for nested claims.
        $parts = explode('.', $claim);
        $value = $payload;

        foreach ($parts as $part) {
            if (!is_array($value) || !isset($value[$part])) {
                return null;
            }
            $value = $value[$part];
        }

        return is_string($value) ? $value : null;
    }

    /**
     * Find a Moodle user by username.
     *
     * @param string $username The username.
     * @param array $payload The JWT payload (for email fallback).
     * @return \stdClass|null The user record or null.
     */
    protected function find_user(string $username, array $payload): ?\stdClass
    {
        global $DB;

        // Try by username first.
        $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
        if ($user) {
            return $user;
        }

        // Try lowercase username.
        $user = $DB->get_record('user', ['username' => strtolower($username), 'deleted' => 0]);
        if ($user) {
            return $user;
        }

        // Try email fallback if enabled.
        $fallback_email = get_config('local_edulution', 'cookie_auth_fallback_email');
        if ($fallback_email && isset($payload['email'])) {
            $user = $DB->get_record('user', ['email' => $payload['email'], 'deleted' => 0]);
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Log in a user.
     *
     * @param \stdClass $user The user to log in.
     * @return bool True if successful.
     */
    protected function login_user(\stdClass $user): bool
    {
        global $USER, $SESSION;

        try {
            complete_user_login($user);
            \core\session\manager::apply_concurrent_login_limit($user->id);

            return true;
        } catch (\Exception $e) {
            $this->log_debug('Login failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear session markers.
     */
    protected function clear_session_markers(): void
    {
        global $SESSION;

        unset($SESSION->{self::SESSION_KEY});
        unset($SESSION->{self::SESSION_TOKEN_HASH});
        unset($SESSION->{self::SESSION_TOKEN_EXP});
    }

    /**
     * Base64url decode.
     *
     * @param string $data The data to decode.
     * @return string|null The decoded data or null.
     */
    protected function base64url_decode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded !== false ? $decoded : null;
    }

    /**
     * Log a debug message.
     *
     * @param string $message The message.
     */
    protected function log_debug(string $message): void
    {
        if (get_config('local_edulution', 'cookie_auth_debug')) {
            debugging("[edulution Cookie Auth] {$message}", DEBUG_DEVELOPER);
        }
    }

    /**
     * Get debug information for troubleshooting.
     *
     * @return array Debug information.
     */
    public function get_debug_info(): array
    {
        global $USER, $SESSION;

        $cookie_name = get_config('local_edulution', 'cookie_auth_cookie_name') ?: 'authToken';
        $token = $_COOKIE[$cookie_name] ?? null;

        $info = [
            'enabled' => $this->is_enabled(),
            'cookie_name' => $cookie_name,
            'cookie_present' => !empty($token),
            'user_logged_in' => isloggedin() && !isguestuser(),
            'current_user' => isloggedin() ? $USER->username : null,
            'session_marked' => !empty($SESSION->{self::SESSION_KEY}),
            'realm_url' => get_config('local_edulution', 'cookie_auth_realm_url'),
            'user_claim' => get_config('local_edulution', 'cookie_auth_user_claim') ?: 'preferred_username',
            'algorithm' => get_config('local_edulution', 'cookie_auth_algorithm') ?: 'RS256',
        ];

        // Decode token payload for debugging (without verification).
        if ($token) {
            $payload = $this->decode_token_payload($token);
            if ($payload) {
                $info['token_claims'] = [
                    'iss' => $payload['iss'] ?? null,
                    'exp' => $payload['exp'] ?? null,
                    'exp_human' => isset($payload['exp']) ? date('Y-m-d H:i:s', $payload['exp']) : null,
                    'username_claim' => $this->extract_username($payload),
                ];
            } else {
                $info['token_claims'] = 'Failed to decode';
            }
        }

        return $info;
    }

    /**
     * Test the configuration by validating a token.
     *
     * @return array Test results.
     */
    public function test_configuration(): array
    {
        $results = [
            'success' => false,
            'messages' => [],
        ];

        // Check if enabled.
        if (!$this->is_enabled()) {
            $results['messages'][] = ['type' => 'warning', 'text' => 'Cookie Auth ist nicht aktiviert.'];
            return $results;
        }

        // Check realm URL or public key.
        $realm_url = get_config('local_edulution', 'cookie_auth_realm_url');
        $public_key = get_config('local_edulution', 'cookie_auth_public_key');

        if (empty($realm_url) && empty($public_key)) {
            // Try main Keycloak settings (environment variables take precedence).
            $keycloak_url = \local_edulution_get_config('keycloak_url');
            $realm = \local_edulution_get_config('keycloak_realm', 'master');

            if (!empty($keycloak_url) && !empty($realm)) {
                $realm_url = rtrim($keycloak_url, '/') . '/realms/' . $realm;
                $results['messages'][] = ['type' => 'info', 'text' => "Verwende Keycloak-Einstellungen: {$realm_url}"];
            } else {
                $results['messages'][] = ['type' => 'error', 'text' => 'Keine Realm-URL oder Public Key konfiguriert.'];
                return $results;
            }
        }

        // Test fetching public key.
        $key = $this->get_public_key();
        if (empty($key)) {
            $results['messages'][] = ['type' => 'error', 'text' => 'Public Key konnte nicht abgerufen werden.'];
            return $results;
        }

        $results['messages'][] = ['type' => 'success', 'text' => 'Public Key erfolgreich abgerufen.'];

        // Check for token.
        $cookie_name = \local_edulution_get_config('cookie_auth_cookie_name', 'authToken');
        $token = $_COOKIE[$cookie_name] ?? null;

        if (empty($token)) {
            $results['messages'][] = ['type' => 'warning', 'text' => "Kein Cookie '{$cookie_name}' vorhanden."];
        } else {
            $payload = $this->validate_token($token);
            if ($payload) {
                $results['messages'][] = ['type' => 'success', 'text' => 'Token erfolgreich validiert.'];
                $username = $this->extract_username($payload);
                if ($username) {
                    $results['messages'][] = ['type' => 'info', 'text' => "Benutzername aus Token: {$username}"];

                    $user = $this->find_user($username, $payload);
                    if ($user) {
                        $results['messages'][] = ['type' => 'success', 'text' => "Moodle-Benutzer gefunden: {$user->username}"];
                        $results['success'] = true;
                    } else {
                        $results['messages'][] = ['type' => 'error', 'text' => 'Kein passender Moodle-Benutzer gefunden.'];
                    }
                }
            } else {
                $results['messages'][] = ['type' => 'error', 'text' => 'Token-Validierung fehlgeschlagen.'];
            }
        }

        return $results;
    }
}
