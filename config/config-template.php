<?php
/**
 * Moodle configuration file - Edulution Edition
 *
 * This config is optimized for:
 * - Running behind a reverse proxy (Traefik)
 * - iframe embedding in edulution.io
 * - SSL termination at proxy level
 */

unset($CFG);
global $CFG;
$CFG = new stdClass();

//=========================================================================
// DATABASE SETTINGS
//=========================================================================
$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MOODLE_DATABASE_HOST') ?: 'moodle-db';
$CFG->dbname    = getenv('MOODLE_DATABASE_NAME') ?: 'moodle';
$CFG->dbuser    = getenv('MOODLE_DATABASE_USER') ?: 'moodle';
$CFG->dbpass    = getenv('MOODLE_DATABASE_PASSWORD') ?: 'moodle';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
    'dbpersist' => 0,
    'dbport' => getenv('MOODLE_DATABASE_PORT') ?: 3306,
    'dbsocket' => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
);

//=========================================================================
// URL SETTINGS - CRITICAL FOR REVERSE PROXY
//=========================================================================
// Build wwwroot from environment variables
$hostname = getenv('MOODLE_HOSTNAME') ?: 'localhost';
$path = getenv('MOODLE_PATH') ?: '/moodle';

// Ensure path starts with /
if (substr($path, 0, 1) !== '/') {
    $path = '/' . $path;
}

// Remove trailing slash from path
$path = rtrim($path, '/');

// Build full URL (always https because we're behind SSL proxy)
$CFG->wwwroot = 'https://' . $hostname . $path;

//=========================================================================
// DATA DIRECTORY
//=========================================================================
$CFG->dataroot = getenv('MOODLE_DATA') ?: '/var/moodledata';
$CFG->directorypermissions = 02775;

//=========================================================================
// REVERSE PROXY SETTINGS - CRITICAL!
//=========================================================================
// Tell Moodle it's behind a reverse proxy
$CFG->reverseproxy = filter_var(getenv('MOODLE_REVERSEPROXY') ?: 'true', FILTER_VALIDATE_BOOLEAN);

// Tell Moodle SSL is terminated at the proxy
$CFG->sslproxy = filter_var(getenv('MOODLE_SSLPROXY') ?: 'true', FILTER_VALIDATE_BOOLEAN);

// Trust X-Forwarded-* headers from proxy (0 = use X-Forwarded-For)
$CFG->getremoteaddrconf = 0;

//=========================================================================
// IFRAME EMBEDDING - ALLOW EMBEDDING IN EDULUTION
//=========================================================================
// This is the key setting to allow Moodle to be embedded in an iframe!
$CFG->allowframembedding = filter_var(getenv('MOODLE_ALLOWFRAMEMBEDDING') ?: 'true', FILTER_VALIDATE_BOOLEAN);

//=========================================================================
// ADMIN SETTINGS
//=========================================================================
$CFG->admin = 'admin';

//=========================================================================
// SESSION SETTINGS
//=========================================================================
// Use database sessions for better reliability behind load balancer
$CFG->session_handler_class = '\core\session\database';
$CFG->session_database_acquire_lock_timeout = 120;

//=========================================================================
// CACHE SETTINGS (OPTIONAL - Redis)
//=========================================================================
$redis_host = getenv('REDIS_HOST');
if ($redis_host) {
    $CFG->session_handler_class = '\core\session\redis';
    $CFG->session_redis_host = $redis_host;
    $CFG->session_redis_port = getenv('REDIS_PORT') ?: 6379;
    $CFG->session_redis_database = 0;
    $CFG->session_redis_prefix = 'moodle_session_';
    $CFG->session_redis_acquire_lock_timeout = 120;
    $CFG->session_redis_lock_expire = 7200;
}

//=========================================================================
// SECURITY SETTINGS
//=========================================================================
$CFG->passwordpolicy = 1;
$CFG->minpasswordlength = 12;
$CFG->minpassworddigits = 1;
$CFG->minpasswordlower = 1;
$CFG->minpasswordupper = 1;
$CFG->minpasswordnonalphanum = 1;
$CFG->maxeditingtime = 7200;
$CFG->forceloginforprofiles = 1;
$CFG->opentogoogle = 0;

// Block access to internal networks (security)
$CFG->curlsecurityblockedhosts = "127.0.0.0/8\n192.168.0.0/16\n10.0.0.0/8\n0.0.0.0\nlocalhost\n169.254.169.254\n0000::1";

//=========================================================================
// PERFORMANCE SETTINGS
//=========================================================================
$CFG->cachejs = true;
$CFG->langstringcache = true;
$CFG->localcachedir = '/var/moodledata/localcache';

//=========================================================================
// EMAIL SETTINGS (optional - configure via environment or admin UI)
//=========================================================================
$smtp_host = getenv('SMTP_HOST');
if ($smtp_host) {
    $CFG->smtphosts = $smtp_host . ':' . (getenv('SMTP_PORT') ?: '587');
    $CFG->smtpsecure = getenv('SMTP_SECURITY') ?: 'tls';
    $CFG->smtpuser = getenv('SMTP_USER') ?: '';
    $CFG->smtppass = getenv('SMTP_PASSWORD') ?: '';
}

//=========================================================================
// DEBUG SETTINGS (disable in production!)
//=========================================================================
$debug = getenv('MOODLE_DEBUG') ?: 'false';
if (filter_var($debug, FILTER_VALIDATE_BOOLEAN)) {
    $CFG->debug = E_ALL;
    $CFG->debugdisplay = 1;
} else {
    $CFG->debug = 0;
    $CFG->debugdisplay = 0;
}

//=========================================================================
// BOOTSTRAP
//=========================================================================
require_once(__DIR__ . '/lib/setup.php');
