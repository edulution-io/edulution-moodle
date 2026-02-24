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
 * AJAX handler to test Keycloak connection.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

// Require login and capability.
require_login();
require_capability('local/edulution:manage', context_system::instance());
require_sesskey();

// Set JSON header.
header('Content-Type: application/json');

try {
    // Get connection parameters.
    $keycloakUrl = required_param('keycloak_url', PARAM_URL);
    $keycloakRealm = required_param('keycloak_realm', PARAM_ALPHANUMEXT);
    $keycloakClientId = required_param('keycloak_client_id', PARAM_ALPHANUMEXT);
    $keycloakClientSecret = optional_param('keycloak_client_secret', '', PARAM_RAW);

    // If no secret provided, try to get from config.
    if (empty($keycloakClientSecret)) {
        $keycloakClientSecret = get_config('local_edulution', 'keycloak_client_secret');
    }

    // Validate URL format.
    if (!filter_var($keycloakUrl, FILTER_VALIDATE_URL)) {
        throw new Exception('Invalid Keycloak URL format');
    }

    // Remove trailing slash.
    $keycloakUrl = rtrim($keycloakUrl, '/');

    // Build token endpoint URL.
    $tokenUrl = $keycloakUrl . '/realms/' . $keycloakRealm . '/protocol/openid-connect/token';

    // Try to get an access token using client credentials.
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $tokenUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $keycloakClientId,
            'client_secret' => $keycloakClientSecret,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    // Check for cURL errors.
    if ($curlError) {
        throw new Exception('Connection error: ' . $curlError);
    }

    // Check HTTP response code.
    if ($httpCode === 0) {
        throw new Exception('Could not connect to Keycloak server. Please check the URL.');
    }

    if ($httpCode === 404) {
        throw new Exception('Realm not found. Please check the realm name.');
    }

    if ($httpCode === 401 || $httpCode === 400) {
        $responseData = json_decode($response, true);
        $errorMessage = $responseData['error_description'] ?? $responseData['error'] ?? 'Authentication failed';
        throw new Exception('Authentication error: ' . $errorMessage);
    }

    if ($httpCode !== 200) {
        throw new Exception('Unexpected response from Keycloak (HTTP ' . $httpCode . ')');
    }

    // Parse response.
    $responseData = json_decode($response, true);

    if (empty($responseData['access_token'])) {
        throw new Exception('No access token received from Keycloak');
    }

    // Success!
    echo json_encode([
        'success' => true,
        'message' => get_string('connection_successful', 'local_edulution'),
        'details' => [
            'token_type' => $responseData['token_type'] ?? 'unknown',
            'expires_in' => $responseData['expires_in'] ?? 0,
        ],
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => get_string('connection_failed', 'local_edulution', $e->getMessage()),
        'error' => $e->getMessage(),
    ]);
}
