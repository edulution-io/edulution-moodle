<?php
/**
 * Debug script to examine Keycloak user data.
 *
 * Usage: php debug_keycloak.php
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_edulution\sync\keycloak_client;

echo "\n";
echo "========================================\n";
echo "  KEYCLOAK DEBUG - USER ATTRIBUTES\n";
echo "========================================\n\n";

// Get Keycloak config (environment variables take precedence).
require_once(__DIR__ . '/../lib.php');
$url = local_edulution_get_config('keycloak_url');
$realm = local_edulution_get_config('keycloak_realm', 'master');
$client_id = local_edulution_get_config('keycloak_client_id');
$client_secret = local_edulution_get_config('keycloak_client_secret');

echo "Config:\n";
echo "  URL: $url\n";
echo "  Realm: $realm\n";
echo "  Client ID: $client_id\n\n";

if (empty($url) || empty($client_id) || empty($client_secret)) {
    die("ERROR: Keycloak not configured!\n");
}

// Create client.
$client = new keycloak_client($url, $realm, $client_id, $client_secret);

echo "Fetching first 20 users...\n\n";

try {
    $users = $client->get_users('', 20, 0);

    echo "Got " . count($users) . " users.\n\n";

    $teachers = 0;
    $students = 0;
    $no_ldap_dn = 0;

    foreach ($users as $i => $user) {
        echo "========================================\n";
        echo "USER " . ($i + 1) . ": " . ($user['username'] ?? 'NO USERNAME') . "\n";
        echo "========================================\n";

        echo "  id: " . ($user['id'] ?? 'null') . "\n";
        echo "  username: " . ($user['username'] ?? 'null') . "\n";
        echo "  email: " . ($user['email'] ?? 'null') . "\n";
        echo "  firstName: " . ($user['firstName'] ?? 'null') . "\n";
        echo "  lastName: " . ($user['lastName'] ?? 'null') . "\n";
        echo "  enabled: " . ($user['enabled'] ? 'true' : 'false') . "\n";

        echo "\n  ATTRIBUTES:\n";
        $attributes = $user['attributes'] ?? [];

        if (empty($attributes)) {
            echo "    (NO ATTRIBUTES - briefRepresentation might be true!)\n";
        } else {
            foreach ($attributes as $key => $value) {
                $val = is_array($value) ? json_encode($value) : $value;
                echo "    $key: $val\n";
            }
        }

        // Check LDAP_ENTRY_DN
        if (isset($attributes['LDAP_ENTRY_DN'])) {
            $dn = is_array($attributes['LDAP_ENTRY_DN'])
                ? ($attributes['LDAP_ENTRY_DN'][0] ?? '')
                : $attributes['LDAP_ENTRY_DN'];

            echo "\n  LDAP_ENTRY_DN: $dn\n";

            if (stripos($dn, 'OU=Teachers') !== false) {
                echo "  >>> IS TEACHER (OU=Teachers found)\n";
                $teachers++;
            } else if (stripos($dn, 'OU=Students') !== false) {
                echo "  >>> IS STUDENT (OU=Students found)\n";
                $students++;
            } else {
                echo "  >>> UNKNOWN OU\n";
            }
        } else {
            echo "\n  NO LDAP_ENTRY_DN attribute!\n";
            $no_ldap_dn++;
        }

        echo "\n";
    }

    echo "========================================\n";
    echo "SUMMARY (first 20 users):\n";
    echo "  Total users: " . count($users) . "\n";
    echo "  Teachers (OU=Teachers): $teachers\n";
    echo "  Students (OU=Students): $students\n";
    echo "  No LDAP_ENTRY_DN: $no_ldap_dn\n";
    echo "========================================\n\n";

    // Now scan ALL users to find teachers
    echo "Scanning ALL users to count teachers...\n";
    $all_users = [];
    $offset = 0;
    $batch_size = 100;
    do {
        $batch = $client->get_users('', $batch_size, $offset);
        $all_users = array_merge($all_users, $batch);
        $offset += $batch_size;
        echo "  Fetched $offset users...\r";
    } while (count($batch) === $batch_size);

    echo "\nTotal users fetched: " . count($all_users) . "\n\n";

    $all_teachers = 0;
    $all_students = 0;
    $teacher_examples = [];

    foreach ($all_users as $user) {
        $attrs = $user['attributes'] ?? [];
        if (isset($attrs['LDAP_ENTRY_DN'])) {
            $dn = is_array($attrs['LDAP_ENTRY_DN'])
                ? ($attrs['LDAP_ENTRY_DN'][0] ?? '')
                : $attrs['LDAP_ENTRY_DN'];

            if (stripos($dn, 'OU=Teachers') !== false) {
                $all_teachers++;
                if (count($teacher_examples) < 5) {
                    $teacher_examples[] = [
                        'username' => $user['username'],
                        'name' => ($user['firstName'] ?? '') . ' ' . ($user['lastName'] ?? ''),
                        'dn' => $dn,
                    ];
                }
            } else if (stripos($dn, 'OU=Students') !== false) {
                $all_students++;
            }
        }
    }

    echo "========================================\n";
    echo "FULL SCAN RESULTS:\n";
    echo "  Total users: " . count($all_users) . "\n";
    echo "  Teachers (OU=Teachers): $all_teachers\n";
    echo "  Students (OU=Students): $all_students\n";
    echo "========================================\n\n";

    if (!empty($teacher_examples)) {
        echo "Example teachers:\n";
        foreach ($teacher_examples as $t) {
            echo "  - {$t['username']}: {$t['name']}\n";
            echo "    DN: {$t['dn']}\n";
        }
        echo "\n";
    }

    // Now fetch a specific group to check members
    echo "Fetching groups...\n";
    $groups = $client->get_all_groups_flat();
    echo "Got " . count($groups) . " groups.\n\n";

    // Find the "p_alle-lehrer" group (all teachers)
    $teacher_group = null;
    foreach ($groups as $group) {
        if ($group['name'] === 'p_alle-lehrer') {
            $teacher_group = $group;
            break;
        }
    }
    // Fallback to any lehrer group
    if (!$teacher_group) {
        foreach ($groups as $group) {
            if (strpos($group['name'], 'lehrer') !== false || strpos($group['name'], 'teacher') !== false) {
                $teacher_group = $group;
                break;
            }
        }
    }

    if ($teacher_group) {
        echo "Found teacher group: " . $teacher_group['name'] . " (id: " . $teacher_group['id'] . ")\n";
        echo "Fetching members...\n\n";

        $members = $client->get_group_members($teacher_group['id'], 0, 10);
        echo "Got " . count($members) . " members.\n\n";

        foreach ($members as $i => $member) {
            echo "MEMBER " . ($i + 1) . ": " . ($member['username'] ?? 'NO USERNAME') . "\n";

            $attrs = $member['attributes'] ?? [];
            if (empty($attrs)) {
                echo "  (NO ATTRIBUTES!)\n";
            } else {
                if (isset($attrs['LDAP_ENTRY_DN'])) {
                    $dn = is_array($attrs['LDAP_ENTRY_DN'])
                        ? ($attrs['LDAP_ENTRY_DN'][0] ?? '')
                        : $attrs['LDAP_ENTRY_DN'];
                    echo "  LDAP_ENTRY_DN: $dn\n";

                    if (stripos($dn, 'OU=Teachers') !== false) {
                        echo "  >>> IS TEACHER\n";
                    }
                } else {
                    echo "  NO LDAP_ENTRY_DN!\n";
                }
            }
            echo "\n";
        }
    } else {
        echo "No teacher group found.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
