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
 * Cache definitions for local_edulution.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Cache for Keycloak API responses (access tokens, user data, groups).
    'keycloak_api' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 300, // 5 minutes default TTL.
        'staticacceleration' => true,
        'staticaccelerationsize' => 100,
    ],

    // Cache for group classification results.
    'group_classification' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 3600, // 1 hour TTL.
        'staticacceleration' => true,
        'staticaccelerationsize' => 500,
    ],

    // Cache for user sync lookups (Keycloak ID to Moodle user mapping).
    'user_mapping' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 600, // 10 minutes TTL.
        'staticacceleration' => true,
        'staticaccelerationsize' => 1000,
    ],

    // Cache for course/group mapping (Keycloak group ID to Moodle course).
    'course_mapping' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 600, // 10 minutes TTL.
        'staticacceleration' => true,
        'staticaccelerationsize' => 500,
    ],

    // Cache for sync job status and progress.
    'sync_jobs' => [
        'mode' => cache_store::MODE_SESSION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 3600, // 1 hour TTL.
    ],
];
