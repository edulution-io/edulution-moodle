#!/bin/bash

PLUGIN_LIST="/tmp/plugins.csv"
MOODLE_LOG="/tmp/moodle.log"
MOODLE_DIR="/bitnami/moodle"

cat <<'EOF'


           _       _       _   _                                             _ _      
   ___  __| |_   _| |_   _| |_(_) ___  _ __        _ __ ___   ___   ___   __| | | ___ 
  / _ \/ _` | | | | | | | | __| |/ _ \| '_ \ _____| '_ ` _ \ / _ \ / _ \ / _` | |/ _ \
 |  __/ (_| | |_| | | |_| | |_| | (_) | | | |_____| | | | | | (_) | (_) | (_| | |  __/
  \___|\__,_|\__,_|_|\__,_|\__|_|\___/|_| |_|     |_| |_| |_|\___/ \___/ \__,_|_|\___|
                                                                                      


EOF

# Start Bitnami entrypoint in background
/opt/bitnami/scripts/moodle/entrypoint.sh /opt/bitnami/scripts/moodle/run.sh >> $MOODLE_LOG 2>&1 &

# 📝 Function to log messages with emojis and timestamps
log_message() {
    local message="$1"
    echo "$message"
}

# 🚦 Function to check if Moodle is fully installed
is_moodle_installed() {
    #log_message "🔄 Checking if Moodle is fully installed..."
    local db_host="${MOODLE_DATABASE_HOST:-mariadb}"
    local db_user="${MOODLE_DATABASE_USER:-bn_moodle}"
    local db_password="${MOODLE_DATABASE_PASSWORD:-supersecure123}"
    local db_name="${MOODLE_DATABASE_NAME:-bitnami_moodle}"

    local query="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$db_name';"
    local result=$(/opt/bitnami/mysql/bin/mariadb -h "$db_host" -u "$db_user" -p"$db_password" -e "$query" -s -N)

    if [[ "$result" -gt 0 ]]; then
        echo
        log_message "✅ Moodle is fully installed."
        return 0
    else
        #log_message "❌ Moodle is not fully installed yet."
        echo -n "."
        return 1
    fi
}

# 🚦 Function to check Moodle service health
is_moodle_healthy() {
    #log_message "🔄 Checking Moodle service health..."
    if curl -fs http://localhost:8080/login/index.php && curl -fs http://localhost:8080/admin/index.php; then
        echo
        log_message "✅ Moodle service is healthy."
        return 0
    else
        #log_message "❌ Moodle service is not healthy yet."
        echo -n "."
        fix_moodle_configuration
        return 1
    fi
}

# Function to fix moodle configuration
fix_moodle_configuration() {
    sed -i "s|^\$CFG->wwwroot\s*=.*|\$CFG->wwwroot = 'https://' . \$_SERVER['HTTP_HOST'] . '/moodle-app';|" /opt/bitnami/moodle/config.php
}

# Download list of plugins
download_plugin_list() {
    curl -s https://raw.githubusercontent.com/edulution-io/edulution-moodle/refs/heads/main/plugins.csv > "$PLUGIN_LIST"
}

# ⚙️ Function to install Moosh
install_moosh() {
    if ! command -v moosh >/dev/null 1>&1; then
        log_message "🔧 Installing Moosh..."
        apt-get update
        apt-get install -y git unzip php-cli php-zip curl wget
        git clone https://github.com/tmuras/moosh.git /opt/moosh
        cd /opt/moosh
        composer install || true
        ln -s /opt/moosh/moosh.php /usr/local/bin/moosh
        cd /
        log_message "✅ Moosh installed successfully."
    else
        log_message "✅ Moosh is already installed – skipping installation."
    fi
}

# 🌐 Install German locale and configure system language
install_german_locale() {
    log_message "🔄 Installing German locale and setting default language in Moodle..."
    apt-get update
    apt-get install -y locales
    locale-gen de_DE.UTF-8
    update-locale de_DE.UTF-8
    log_message "✅ System locale set to de_DE.UTF-8"

    cd /bitnami/moodle
    moosh language-install de
    moosh language-install de_comm
    log_message "✅ Installed Moodle languages: de, de_comm"

    moosh config-set defaultlang de_comm
    log_message "✅ Default Moodle language set to de_comm"
}

# Check if plugin is avaliable for current moodle version
# $1 = Plugin name
check_plugin_avaliable() {
    moosh plugin-list | grep $MOODLE_VERSION | grep $1 2>$1 >/dev/zero
    return $?
}

# Install plugin by name
# $1 = Plugin name
install_plugin() {
    moosh -n plugin-install "$plugin" 2>$1 >/dev/zero
    return $?
}

install_all_plugins() {
    while IFS= read -r plugin || [ -n "$plugin" ]; do
        [[ "$plugin" =~ ^#.*$ || -z "$plugin" ]] && continue

        if check_plugin_avaliable "$plugin"; then
            if install_plugin "$plugin"; then
                log_message "✅ $plugin installed successfully"
            else
                log_message "❌ Error installing $plugin"
            fi
        else
            log_message "❌ Plugin $plugin not avalibale or compatible with this moodle version!"
        fi
    done < "$PLUGIN_LIST"

    chown -R daemon:daemon /bitnami/
}

# Wait for Moodle to be fully installed
log_message "⏳ Waiting for Moodle to be fully installed..."
while ! is_moodle_installed; do
    sleep 5
done

# Wait for Moodle to be healthy
log_message "⏳ Waiting for Moodle to be healthy..."
while ! is_moodle_healthy; do
    sleep 5
done

download_plugin_list
install_moosh
install_german_locale

MOODLE_VERSION=$(grep '$release' "$MOODLE_DIR/version.php" | grep -oP '\d+\.\d+')
log_message "ℹ️ Detected Moodle version: $MOODLE_VERSION"

install_all_plugins

tail -f $MOODLE_LOG
