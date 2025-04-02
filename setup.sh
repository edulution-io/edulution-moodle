#!/bin/bash

set -e

MOODLE_DIR="/bitnami/moodle"
PLUGIN_DB="/tmp/plugins-available.csv"
FILTERED_LIST="/tmp/plugins-install.txt"
LOG_FILE="/var/log/moodle/install.log"

curl -s https://raw.githubusercontent.com/netzint/ni-moodle/refs/heads/main/plugins.csv > "$PLUGIN_LIST"


# 📝 Function to log messages with emojis and timestamps
log_message() {
    local message="$1"
    echo "$message" | tee -a "$LOG_FILE"
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

# 🔄 Function to update available plugin list
update_plugin_list() {
    log_message "📥 Updating plugin list and saving to $PLUGIN_DB ..."
    moosh plugin-list > /tmp/plugins.raw

    > "$PLUGIN_DB"
    while IFS= read -r line; do
        [[ "$line" =~ ^#.*$ || -z "$line" ]] && continue

        plugin=$(echo "$line" | cut -d',' -f1)
        versions=$(echo "$line" | cut -d',' -f2- | rev | cut -d',' -f2- | rev)
        url=$(echo "$line" | awk -F',' '{print $NF}')

        if [[ "$url" == http* ]] && echo "$versions" | grep -q "$MOODLE_VERSION"; then
            echo "$plugin,$versions,$url" >> "$PLUGIN_DB"
        fi
    done < /tmp/plugins.raw

    rm /tmp/plugins.raw
    log_message "📚 Plugin list updated."
}

# 🔍 Function to filter requested plugins
filter_plugin_list() {
    log_message "🧹 Filtering $PLUGIN_LIST for Moodle $MOODLE_VERSION..."
    > "$FILTERED_LIST"

    while IFS= read -r plugin || [ -n "$plugin" ]; do
        [[ "$plugin" =~ ^#.*$ || -z "$plugin" ]] && continue

        line=$(grep -E "^$plugin," "$PLUGIN_DB" || true)
        if [[ -z "$line" ]]; then
            log_message "⚠️ $plugin is not available for Moodle $MOODLE_VERSION – skipping."
            continue
        fi

        echo "$plugin" >> "$FILTERED_LIST"
    done < "$PLUGIN_LIST"
}

# 📦 Function to install plugins and log status
install_plugins_and_generate_report() {
    log_message "🚀 Starting plugin installation ..."

    {
        echo ""
        echo "======================================================"
        echo "📦 Moodle Plugin Installation Report"
        echo "======================================================"
        echo "- 🧠 Moodle Version: $MOODLE_VERSION"
        echo "- 🗓️ Date: $(date '+%Y-%m-%d %H:%M:%S')"
        echo ""
        echo "| Plugin | Status |"
        echo "|--------|--------|"
    } >> "$LOG_FILE"

    cd "$MOODLE_DIR"

    while IFS= read -r plugin || [ -n "$plugin" ]; do
        [[ "$plugin" =~ ^#.*$ || -z "$plugin" ]] && continue

        if moosh -n plugin-install "$plugin"; then
            log_message "✅ $plugin installed successfully."
            echo "| $plugin | ✅ Successful |" >> "$LOG_FILE"
        else
            log_message "❌ Error installing $plugin."
            echo "| $plugin | ❌ Error |" >> "$LOG_FILE"
        fi
    done < "$FILTERED_LIST"

    while IFS= read -r plugin || [ -n "$plugin" ]; do
        [[ "$plugin" =~ ^#.*$ || -z "$plugin" ]] && continue

        if ! grep -q "^$plugin$" "$FILTERED_LIST"; then
            echo "| $plugin | ⛔ Not available/compatible |" >> "$LOG_FILE"
        fi
    done < "$PLUGIN_LIST"

    log_message "✅ All compatible plugins have been installed! Please open /admin/index.php for final activation."
}

# 🌐 Install German locale and configure system language
install_german_locale() {
    log_message "🔄 Installing German locale and setting default language in Moodle..."
    apt-get update
    apt-get install -y locales
    locale-gen de_DE.UTF-8
    update-locale LANG=de_DE.UTF-8
    log_message "✅ System locale set to de_DE.UTF-8"

    cd /bitnami/moodle
    moosh language-install de
    moosh language-install de_comm
    log_message "✅ Installed Moodle languages: de, de_comm"

    moosh config-set defaultlang de_comm
    log_message "✅ Default Moodle language set to de_comm"
}

# 🚦 Function to check if Moodle is fully installed
is_moodle_installed() {
    log_message "🔄 Checking if Moodle is fully installed..."
    local db_host="${MOODLE_DATABASE_HOST:-mariadb}"
    local db_user="${MOODLE_DATABASE_USER:-bn_moodle}"
    local db_password="${MOODLE_DATABASE_PASSWORD:-supersecure123}"
    local db_name="${MOODLE_DATABASE_NAME:-bitnami_moodle}"

    local query="SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$db_name';"
    local result=$(mysql -h "$db_host" -u "$db_user" -p"$db_password" -e "$query" -s -N)

    if [[ "$result" -gt 0 ]]; then
        log_message "✅ Moodle is fully installed."
        return 0
    else
        log_message "❌ Moodle is not fully installed yet."
        return 1
    fi
}

# 🚦 Function to check Moodle service health
is_moodle_healthy() {
    log_message "🔄 Checking Moodle service health..."
    if curl -fs http://localhost:8080/login/index.php && curl -fs http://localhost:8080/admin/index.php; then
        log_message "✅ Moodle service is healthy."
        return 0
    else
        log_message "❌ Moodle service is not healthy yet."
        return 1
    fi
}

# 🚦 Script Execution
log_message "🏁 Starting setup script..."

# Wait for Moodle to be fully installed and healthy
while ! is_moodle_installed || ! is_moodle_healthy; do
    log_message "⏳ Waiting for Moodle to be fully installed and healthy..."
    sleep 30
done

MOODLE_VERSION=$(grep '$release' "$MOODLE_DIR/version.php" | grep -oP '\d+\.\d+')
log_message "ℹ️ Detected Moodle version: $MOODLE_VERSION"

install_moosh
update_plugin_list
filter_plugin_list
install_plugins_and_generate_report
install_german_locale

log_message "🎉 Setup script completed!"
