<?php
/**
 * Debug script to examine Moodle enrollments and roles.
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

echo "\n";
echo "========================================\n";
echo "  MOODLE ENROLLMENT DEBUG\n";
echo "========================================\n\n";

// Get courses with idnumber starting with kc_
echo "Fetching sync-managed courses (idnumber starts with 'kc_')...\n\n";

$courses = $DB->get_records_sql(
    "SELECT id, shortname, fullname, idnumber
     FROM {course}
     WHERE idnumber LIKE 'kc_%'
     ORDER BY shortname
     LIMIT 10"
);

echo "Found " . count($courses) . " sync-managed courses.\n\n";

foreach ($courses as $course) {
    echo "========================================\n";
    echo "COURSE: {$course->shortname} (id: {$course->id})\n";
    echo "  idnumber: {$course->idnumber}\n";
    echo "========================================\n";

    // Get enrollments for this course
    $sql = "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, r.shortname as role
            FROM {user_enrolments} ue
            JOIN {enrol} e ON e.id = ue.enrolid
            JOIN {user} u ON u.id = ue.userid
            JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
            LEFT JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id
            LEFT JOIN {role} r ON r.id = ra.roleid
            WHERE e.courseid = ? AND e.enrol = 'manual'
            ORDER BY u.username
            LIMIT 20";

    $enrollments = $DB->get_records_sql($sql, [$course->id]);

    echo "Enrollments (" . count($enrollments) . "):\n";

    foreach ($enrollments as $enroll) {
        $role = $enroll->role ?? 'NO ROLE';
        echo "  - {$enroll->username} ({$enroll->firstname} {$enroll->lastname}): {$role}\n";
    }

    echo "\n";
}

// Now check if any teachers are enrolled as students
echo "========================================\n";
echo "CHECKING FOR TEACHERS ENROLLED AS STUDENT\n";
echo "========================================\n\n";

// Get all teachers from Keycloak (by checking LDAP_ENTRY_DN in our user mapping or by username pattern)
// Actually, let's just check users who have 'student' role in courses

$sql = "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, c.shortname as course, r.shortname as role
        FROM {user_enrolments} ue
        JOIN {enrol} e ON e.id = ue.enrolid
        JOIN {user} u ON u.id = ue.userid
        JOIN {course} c ON c.id = e.courseid
        JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
        JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id
        JOIN {role} r ON r.id = ra.roleid
        WHERE c.idnumber LIKE 'kc_project_%'
        AND r.shortname = 'student'
        ORDER BY u.username
        LIMIT 30";

$students_in_projects = $DB->get_records_sql($sql);

echo "Users with 'student' role in project courses:\n";
foreach ($students_in_projects as $s) {
    echo "  - {$s->username} in {$s->course}: {$s->role}\n";
}

echo "\n";

// Check users with 'editingteacher' role
$sql = "SELECT DISTINCT u.id, u.username, u.firstname, u.lastname, c.shortname as course
        FROM {user_enrolments} ue
        JOIN {enrol} e ON e.id = ue.enrolid
        JOIN {user} u ON u.id = ue.userid
        JOIN {course} c ON c.id = e.courseid
        JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
        JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id
        JOIN {role} r ON r.id = ra.roleid
        WHERE c.idnumber LIKE 'kc_%'
        AND r.shortname = 'editingteacher'
        ORDER BY u.username
        LIMIT 30";

$teachers_enrolled = $DB->get_records_sql($sql);

echo "Users with 'editingteacher' role in sync courses:\n";
foreach ($teachers_enrolled as $t) {
    echo "  - {$t->username} in {$t->course}\n";
}

echo "\n";

// Get total count of each role in sync-managed courses
$sql = "SELECT r.shortname, COUNT(DISTINCT CONCAT(ue.userid, '_', e.courseid)) as count
        FROM {user_enrolments} ue
        JOIN {enrol} e ON e.id = ue.enrolid
        JOIN {course} c ON c.id = e.courseid
        JOIN {context} ctx ON ctx.instanceid = e.courseid AND ctx.contextlevel = 50
        JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id
        JOIN {role} r ON r.id = ra.roleid
        WHERE c.idnumber LIKE 'kc_%'
        GROUP BY r.shortname";

$role_counts = $DB->get_records_sql($sql);

echo "========================================\n";
echo "ROLE SUMMARY (in sync-managed courses):\n";
echo "========================================\n";
foreach ($role_counts as $rc) {
    echo "  {$rc->shortname}: {$rc->count}\n";
}

echo "\nDone.\n";
