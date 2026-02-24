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
 * Environment-aware admin settings that show when values come from env vars.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\admin;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Text setting that is aware of environment variable overrides.
 */
class setting_configtext_envaware extends \admin_setting_configtext {

    /** @var string The config key name */
    protected string $configkey;

    /**
     * Constructor.
     *
     * @param string $name Setting name.
     * @param string $visiblename Visible name.
     * @param string $description Description.
     * @param string $defaultsetting Default value.
     * @param mixed $paramtype Parameter type.
     * @param int $size Input size.
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $paramtype = PARAM_RAW, $size = null) {
        // Extract config key from name (e.g., 'local_edulution/keycloak_url' -> 'keycloak_url')
        $parts = explode('/', $name);
        $this->configkey = end($parts);

        parent::__construct($name, $visiblename, $description, $defaultsetting, $paramtype, $size);
    }

    /**
     * Get the current value, preferring environment variable.
     *
     * @return mixed Current value.
     */
    public function get_setting() {
        if (\local_edulution_config_from_env($this->configkey)) {
            return \local_edulution_get_config($this->configkey);
        }
        return parent::get_setting();
    }

    /**
     * Output the HTML for this setting.
     *
     * @param mixed $data Current data.
     * @param string $query Search query.
     * @return string HTML output.
     */
    public function output_html($data, $query = '') {
        $fromEnv = \local_edulution_config_from_env($this->configkey);

        if ($fromEnv) {
            // Get the actual env value.
            $envValue = \local_edulution_get_config($this->configkey);
            $envVarName = LOCAL_EDULUTION_ENV_CONFIG_MAP[$this->configkey] ?? '';

            $html = '<div class="form-text">';
            $html .= '<div class="alert alert-info d-flex align-items-center" style="margin-bottom: 0;">';
            $html .= '<i class="fa fa-lock mr-2" style="margin-right: 8px;"></i>';
            $html .= '<div>';
            $html .= '<strong>Wert aus Umgebungsvariable:</strong> <code>' . s($envVarName) . '</code><br>';
            $html .= '<span class="text-monospace" style="font-family: monospace;">' . s($envValue) . '</span>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';

            return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
        }

        return parent::output_html($data, $query);
    }

    /**
     * Write setting - blocked if from environment.
     *
     * @param mixed $data Data to write.
     * @return string Empty on success.
     */
    public function write_setting($data) {
        if (\local_edulution_config_from_env($this->configkey)) {
            // Don't allow writing if value comes from env.
            return '';
        }
        return parent::write_setting($data);
    }
}

/**
 * Password setting that is aware of environment variable overrides.
 */
class setting_configpassword_envaware extends \admin_setting_configpasswordunmask {

    /** @var string The config key name */
    protected string $configkey;

    /**
     * Constructor.
     *
     * @param string $name Setting name.
     * @param string $visiblename Visible name.
     * @param string $description Description.
     * @param string $defaultsetting Default value.
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        $parts = explode('/', $name);
        $this->configkey = end($parts);

        parent::__construct($name, $visiblename, $description, $defaultsetting);
    }

    /**
     * Output the HTML for this setting.
     *
     * @param mixed $data Current data.
     * @param string $query Search query.
     * @return string HTML output.
     */
    public function output_html($data, $query = '') {
        $fromEnv = \local_edulution_config_from_env($this->configkey);

        if ($fromEnv) {
            $envVarName = LOCAL_EDULUTION_ENV_CONFIG_MAP[$this->configkey] ?? '';

            $html = '<div class="form-text">';
            $html .= '<div class="alert alert-info d-flex align-items-center" style="margin-bottom: 0;">';
            $html .= '<i class="fa fa-lock mr-2" style="margin-right: 8px;"></i>';
            $html .= '<div>';
            $html .= '<strong>Wert aus Umgebungsvariable:</strong> <code>' . s($envVarName) . '</code><br>';
            $html .= '<span class="text-muted">********</span>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';

            return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
        }

        return parent::output_html($data, $query);
    }

    /**
     * Write setting - blocked if from environment.
     *
     * @param mixed $data Data to write.
     * @return string Empty on success.
     */
    public function write_setting($data) {
        if (\local_edulution_config_from_env($this->configkey)) {
            return '';
        }
        return parent::write_setting($data);
    }
}

/**
 * Checkbox setting that is aware of environment variable overrides.
 */
class setting_configcheckbox_envaware extends \admin_setting_configcheckbox {

    /** @var string The config key name */
    protected string $configkey;

    /**
     * Constructor.
     *
     * @param string $name Setting name.
     * @param string $visiblename Visible name.
     * @param string $description Description.
     * @param string $defaultsetting Default value.
     * @param string $yes Yes value.
     * @param string $no No value.
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $yes = '1', $no = '0') {
        $parts = explode('/', $name);
        $this->configkey = end($parts);

        parent::__construct($name, $visiblename, $description, $defaultsetting, $yes, $no);
    }

    /**
     * Get the current value, preferring environment variable.
     *
     * @return mixed Current value.
     */
    public function get_setting() {
        if (\local_edulution_config_from_env($this->configkey)) {
            return \local_edulution_get_config($this->configkey) ? $this->yes : $this->no;
        }
        return parent::get_setting();
    }

    /**
     * Output the HTML for this setting.
     *
     * @param mixed $data Current data.
     * @param string $query Search query.
     * @return string HTML output.
     */
    public function output_html($data, $query = '') {
        $fromEnv = \local_edulution_config_from_env($this->configkey);

        if ($fromEnv) {
            $envVarName = LOCAL_EDULUTION_ENV_CONFIG_MAP[$this->configkey] ?? '';
            $envValue = \local_edulution_get_config($this->configkey);
            $valueDisplay = $envValue ? 'Aktiviert' : 'Deaktiviert';

            $html = '<div class="form-text">';
            $html .= '<div class="alert alert-info d-flex align-items-center" style="margin-bottom: 0;">';
            $html .= '<i class="fa fa-lock mr-2" style="margin-right: 8px;"></i>';
            $html .= '<div>';
            $html .= '<strong>Wert aus Umgebungsvariable:</strong> <code>' . s($envVarName) . '</code><br>';
            $html .= '<span>' . $valueDisplay . '</span>';
            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';

            return format_admin_setting($this, $this->visiblename, $html, $this->description, true, '', null, $query);
        }

        return parent::output_html($data, $query);
    }

    /**
     * Write setting - blocked if from environment.
     *
     * @param mixed $data Data to write.
     * @return string Empty on success.
     */
    public function write_setting($data) {
        if (\local_edulution_config_from_env($this->configkey)) {
            return '';
        }
        return parent::write_setting($data);
    }
}
