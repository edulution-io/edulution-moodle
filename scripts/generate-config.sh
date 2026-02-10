#!/bin/bash
# Generate Moodle config.php with actual values (not getenv)

MOODLE_DIR="/var/www/html/moodle"
CONFIG_FILE="${MOODLE_DIR}/config.php"

# Get values from environment
DB_HOST="${MOODLE_DATABASE_HOST:-moodle-db}"
DB_NAME="${MOODLE_DATABASE_NAME:-moodle}"
DB_USER="${MOODLE_DATABASE_USER:-moodle}"
DB_PASS="${MOODLE_DATABASE_PASSWORD:-}"
DB_PORT="${MOODLE_DATABASE_PORT:-3306}"
DATA_ROOT="${MOODLE_DATA:-/var/moodledata}"
HOSTNAME="${MOODLE_HOSTNAME:-localhost}"
MOODLE_PATH="${MOODLE_PATH:-/moodle-app}"

# Ensure path starts with /
if [[ "${MOODLE_PATH}" != /* ]]; then
    MOODLE_PATH="/${MOODLE_PATH}"
fi
# Remove trailing slash
MOODLE_PATH="${MOODLE_PATH%/}"

WWWROOT="https://${HOSTNAME}${MOODLE_PATH}"

# Boolean settings
REVERSE_PROXY="true"
SSL_PROXY="true"
ALLOW_FRAME="true"

if [ "${MOODLE_REVERSEPROXY}" = "false" ] || [ "${MOODLE_REVERSEPROXY}" = "0" ]; then
    REVERSE_PROXY="false"
fi
if [ "${MOODLE_SSLPROXY}" = "false" ] || [ "${MOODLE_SSLPROXY}" = "0" ]; then
    SSL_PROXY="false"
fi
if [ "${MOODLE_ALLOWFRAMEMBEDDING}" = "false" ] || [ "${MOODLE_ALLOWFRAMEMBEDDING}" = "0" ]; then
    ALLOW_FRAME="false"
fi

# Redis settings
REDIS_CONFIG=""
if [ -n "${REDIS_HOST}" ]; then
    REDIS_PORT="${REDIS_PORT:-6379}"
    REDIS_CONFIG="
// Redis session handling
\$CFG->session_handler_class = '\\\\core\\\\session\\\\redis';
\$CFG->session_redis_host = '${REDIS_HOST}';
\$CFG->session_redis_port = ${REDIS_PORT};
\$CFG->session_redis_database = 0;
\$CFG->session_redis_prefix = 'moodle_session_';
\$CFG->session_redis_acquire_lock_timeout = 120;
\$CFG->session_redis_lock_expire = 7200;"
fi

# SMTP settings
SMTP_CONFIG=""
if [ -n "${SMTP_HOST}" ]; then
    SMTP_PORT="${SMTP_PORT:-587}"
    SMTP_SECURITY="${SMTP_SECURITY:-tls}"
    SMTP_CONFIG="
// SMTP Configuration
\$CFG->smtphosts = '${SMTP_HOST}:${SMTP_PORT}';
\$CFG->smtpsecure = '${SMTP_SECURITY}';
\$CFG->smtpuser = '${SMTP_USER:-}';
\$CFG->smtppass = '${SMTP_PASSWORD:-}';"
fi

# Debug settings
DEBUG_CONFIG="\$CFG->debug = 0;
\$CFG->debugdisplay = 0;"
if [ "${MOODLE_DEBUG}" = "true" ] || [ "${MOODLE_DEBUG}" = "1" ]; then
    DEBUG_CONFIG="\$CFG->debug = E_ALL;
\$CFG->debugdisplay = 1;"
fi

# Escape single quotes in password
DB_PASS_ESCAPED="${DB_PASS//\'/\\\'}"

# Generate config.php
cat > "${CONFIG_FILE}" << CONFIGEOF
<?php
/**
 * Moodle configuration file - Edulution Edition
 * Generated at: $(date)
 */

unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();

//=========================================================================
// DATABASE SETTINGS
//=========================================================================
\$CFG->dbtype    = 'mariadb';
\$CFG->dblibrary = 'native';
\$CFG->dbhost    = '${DB_HOST}';
\$CFG->dbname    = '${DB_NAME}';
\$CFG->dbuser    = '${DB_USER}';
\$CFG->dbpass    = '${DB_PASS_ESCAPED}';
\$CFG->prefix    = 'mdl_';
\$CFG->dboptions = array(
    'dbpersist' => 0,
    'dbport' => ${DB_PORT},
    'dbsocket' => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
);

//=========================================================================
// URL SETTINGS
//=========================================================================
\$CFG->wwwroot = '${WWWROOT}';

//=========================================================================
// DATA DIRECTORY
//=========================================================================
\$CFG->dataroot = '${DATA_ROOT}';
\$CFG->directorypermissions = 02775;

//=========================================================================
// REVERSE PROXY SETTINGS
//=========================================================================
\$CFG->reverseproxy = ${REVERSE_PROXY};
\$CFG->sslproxy = ${SSL_PROXY};
\$CFG->getremoteaddrconf = 0;

//=========================================================================
// IFRAME EMBEDDING
//=========================================================================
\$CFG->allowframembedding = ${ALLOW_FRAME};

//=========================================================================
// ADMIN SETTINGS
//=========================================================================
\$CFG->admin = 'admin';

//=========================================================================
// SESSION SETTINGS
//=========================================================================
\$CFG->session_handler_class = '\\core\\session\\database';
\$CFG->session_database_acquire_lock_timeout = 120;
${REDIS_CONFIG}

//=========================================================================
// SECURITY SETTINGS
//=========================================================================
\$CFG->passwordpolicy = 1;
\$CFG->minpasswordlength = 12;
\$CFG->minpassworddigits = 1;
\$CFG->minpasswordlower = 1;
\$CFG->minpasswordupper = 1;
\$CFG->minpasswordnonalphanum = 1;
\$CFG->maxeditingtime = 7200;
\$CFG->forceloginforprofiles = 1;
\$CFG->opentogoogle = 0;
\$CFG->curlsecurityblockedhosts = "127.0.0.0/8\n192.168.0.0/16\n10.0.0.0/8\n0.0.0.0\nlocalhost\n169.254.169.254\n0000::1";

//=========================================================================
// PERFORMANCE SETTINGS
//=========================================================================
\$CFG->cachejs = true;
\$CFG->langstringcache = true;
\$CFG->localcachedir = '${DATA_ROOT}/localcache';
${SMTP_CONFIG}

//=========================================================================
// DEBUG SETTINGS
//=========================================================================
${DEBUG_CONFIG}

//=========================================================================
// BOOTSTRAP
//=========================================================================
require_once(__DIR__ . '/lib/setup.php');
CONFIGEOF

chown www-data:www-data "${CONFIG_FILE}"
chmod 644 "${CONFIG_FILE}"

echo "[CONFIG] Generated config.php with:"
echo "  - wwwroot: ${WWWROOT}"
echo "  - dbhost: ${DB_HOST}"
echo "  - reverseproxy: ${REVERSE_PROXY}"
echo "  - sslproxy: ${SSL_PROXY}"
echo "  - allowframembedding: ${ALLOW_FRAME}"
if [ -n "${REDIS_HOST}" ]; then
    echo "  - redis: ${REDIS_HOST}:${REDIS_PORT}"
fi
