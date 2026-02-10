#!/bin/bash
# Script to update Moodle configuration dynamically

MOODLE_DIR="/var/www/html/moodle"
CONFIG_FILE="${MOODLE_DIR}/config.php"

# Build wwwroot
HOSTNAME="${MOODLE_HOSTNAME:-localhost}"
PATH_PREFIX="${MOODLE_PATH:-/moodle}"
WWWROOT="https://${HOSTNAME}${PATH_PREFIX}"

echo "[CONFIG] Updating Moodle configuration..."

# Update wwwroot
if grep -q '\$CFG->wwwroot' "${CONFIG_FILE}"; then
    sed -i "s|\\\$CFG->wwwroot.*|\\\$CFG->wwwroot = '${WWWROOT}';|" "${CONFIG_FILE}"
    echo "[CONFIG] wwwroot set to: ${WWWROOT}"
fi

# Update sslproxy
if [ "${MOODLE_SSLPROXY}" = "true" ] || [ "${MOODLE_SSLPROXY}" = "1" ]; then
    if grep -q '\$CFG->sslproxy' "${CONFIG_FILE}"; then
        sed -i "s|\\\$CFG->sslproxy.*|\\\$CFG->sslproxy = true;|" "${CONFIG_FILE}"
    else
        sed -i "/\\\$CFG->wwwroot/a \\\$CFG->sslproxy = true;" "${CONFIG_FILE}"
    fi
    echo "[CONFIG] sslproxy = true"
fi

# Update reverseproxy
if [ "${MOODLE_REVERSEPROXY}" = "true" ] || [ "${MOODLE_REVERSEPROXY}" = "1" ]; then
    if grep -q '\$CFG->reverseproxy' "${CONFIG_FILE}"; then
        sed -i "s|\\\$CFG->reverseproxy.*|\\\$CFG->reverseproxy = true;|" "${CONFIG_FILE}"
    else
        sed -i "/\\\$CFG->wwwroot/a \\\$CFG->reverseproxy = true;" "${CONFIG_FILE}"
    fi
    echo "[CONFIG] reverseproxy = true"
fi

# Update allowframembedding
if [ "${MOODLE_ALLOWFRAMEMBEDDING}" = "true" ] || [ "${MOODLE_ALLOWFRAMEMBEDDING}" = "1" ]; then
    if grep -q '\$CFG->allowframembedding' "${CONFIG_FILE}"; then
        sed -i "s|\\\$CFG->allowframembedding.*|\\\$CFG->allowframembedding = true;|" "${CONFIG_FILE}"
    else
        sed -i "/\\\$CFG->wwwroot/a \\\$CFG->allowframembedding = true;" "${CONFIG_FILE}"
    fi
    echo "[CONFIG] allowframembedding = true"
fi

echo "[CONFIG] Configuration update complete!"
