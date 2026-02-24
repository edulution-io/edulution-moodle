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
 * Plugin installer - downloads and installs Moodle plugins.
 *
 * This class handles downloading plugins from Moodle Plugin Directory
 * or custom URLs, extracting them, and installing to the correct location.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\import;

/**
 * Plugin installer class for downloading and installing Moodle plugins.
 */
class plugin_installer {

    /** @var string Moodle Plugin Directory API URL */
    const MOODLE_PLUGINS_API = 'https://download.moodle.org/api/1.3/pluginfo.php';

    /** @var string Moodle dirroot path */
    protected string $dirroot;

    /** @var callable|null Progress callback */
    protected $progresscallback = null;

    /** @var array Cache of plugin type to directory mappings */
    protected array $plugintypedirs = [];

    /** @var array Installed plugins tracking */
    protected array $installedplugins = [];

    /**
     * Constructor.
     *
     * @param string $dirroot Moodle dirroot path.
     */
    public function __construct(string $dirroot) {
        $this->dirroot = rtrim($dirroot, '/');
        $this->init_plugin_type_dirs();
    }

    /**
     * Initialize plugin type to directory mappings.
     */
    protected function init_plugin_type_dirs(): void {
        // Standard Moodle plugin types and their directories
        $this->plugintypedirs = [
            'mod' => 'mod',
            'auth' => 'auth',
            'enrol' => 'enrol',
            'block' => 'blocks',
            'filter' => 'filter',
            'format' => 'course/format',
            'report' => 'report',
            'local' => 'local',
            'theme' => 'theme',
            'tool' => 'admin/tool',
            'editor' => 'lib/editor',
            'atto' => 'lib/editor/atto/plugins',
            'tiny' => 'lib/editor/tiny/plugins',
            'assignment' => 'mod/assignment/type',
            'assignsubmission' => 'mod/assign/submission',
            'assignfeedback' => 'mod/assign/feedback',
            'availability' => 'availability/condition',
            'booktool' => 'mod/book/tool',
            'calendartype' => 'calendar/type',
            'contenttype' => 'contentbank/contenttype',
            'coursereport' => 'course/report',
            'customfield' => 'customfield/field',
            'datafield' => 'mod/data/field',
            'dataformat' => 'dataformat',
            'datapreset' => 'mod/data/preset',
            'fileconverter' => 'files/converter',
            'forumreport' => 'mod/forum/report',
            'gradeexport' => 'grade/export',
            'gradeimport' => 'grade/import',
            'gradereport' => 'grade/report',
            'gradingform' => 'grade/grading/form',
            'h5plib' => 'h5p/h5plib',
            'logstore' => 'admin/tool/log/store',
            'ltiservice' => 'mod/lti/service',
            'ltisource' => 'mod/lti/source',
            'media' => 'media/player',
            'message' => 'message/output',
            'mlbackend' => 'lib/mlbackend',
            'mnetservice' => 'mnet/service',
            'paygw' => 'payment/gateway',
            'plagiarism' => 'plagiarism',
            'portfolio' => 'portfolio',
            'profilefield' => 'user/profile/field',
            'qbank' => 'question/bank',
            'qbehaviour' => 'question/behaviour',
            'qformat' => 'question/format',
            'qtype' => 'question/type',
            'quiz' => 'mod/quiz/report',
            'quizaccess' => 'mod/quiz/accessrule',
            'repository' => 'repository',
            'scormreport' => 'mod/scorm/report',
            'search' => 'search/engine',
            'tinymce' => 'lib/editor/tinymce/plugins',
            'webservice' => 'webservice',
            'workshopallocation' => 'mod/workshop/allocation',
            'workshopeval' => 'mod/workshop/eval',
            'workshopform' => 'mod/workshop/form',
            'cachelock' => 'cache/locks',
            'cachestore' => 'cache/stores',
            'antivirus' => 'lib/antivirus',
        ];
    }

    /**
     * Set progress callback function.
     *
     * @param callable $callback Function that receives (step, message).
     */
    public function set_progress_callback(callable $callback): void {
        $this->progresscallback = $callback;
    }

    /**
     * Report progress.
     *
     * @param int $step Step number.
     * @param string $message Progress message.
     */
    protected function progress(int $step, string $message): void {
        if ($this->progresscallback) {
            call_user_func($this->progresscallback, $step, $message);
        }
    }

    /**
     * Install a plugin from export data.
     *
     * @param array $plugindata Plugin data from export.
     * @return bool True if installed, false if skipped.
     * @throws \Exception On installation failure.
     */
    public function install_plugin(array $plugindata): bool {
        $component = $plugindata['component'] ?? '';
        if (empty($component)) {
            throw new \Exception("Plugin component name is required");
        }

        // Parse component into type and name
        list($type, $name) = $this->parse_component($component);
        if (!$type || !$name) {
            throw new \Exception("Invalid plugin component: {$component}");
        }

        // Check if plugin already exists
        $targetdir = $this->get_plugin_directory($type, $name);
        if (is_dir($targetdir)) {
            $this->progress(0, "Plugin {$component} already installed, skipping...");
            return false;
        }

        // Try to get download URL
        $downloadurl = $this->get_plugin_download_url($component, $plugindata);
        if (!$downloadurl) {
            throw new \Exception("Cannot find download URL for plugin: {$component}");
        }

        $this->progress(1, "Downloading {$component}...");

        // Download plugin
        $tempfile = $this->download_plugin($downloadurl);

        try {
            // Extract plugin
            $this->progress(2, "Extracting {$component}...");
            $this->extract_plugin($tempfile, $type, $name);

            // Cleanup temp file
            @unlink($tempfile);

            $this->installedplugins[] = $component;
            $this->progress(3, "Installed {$component}");

            return true;

        } catch (\Exception $e) {
            // Cleanup on failure
            @unlink($tempfile);
            throw $e;
        }
    }

    /**
     * Install plugin from a direct URL.
     *
     * @param string $component Plugin component name.
     * @param string $url Download URL.
     * @return bool True if installed.
     * @throws \Exception On failure.
     */
    public function install_from_url(string $component, string $url): bool {
        list($type, $name) = $this->parse_component($component);
        if (!$type || !$name) {
            throw new \Exception("Invalid plugin component: {$component}");
        }

        // Check if already installed
        $targetdir = $this->get_plugin_directory($type, $name);
        if (is_dir($targetdir)) {
            return false;
        }

        $tempfile = $this->download_plugin($url);

        try {
            $this->extract_plugin($tempfile, $type, $name);
            @unlink($tempfile);
            $this->installedplugins[] = $component;
            return true;
        } catch (\Exception $e) {
            @unlink($tempfile);
            throw $e;
        }
    }

    /**
     * Install plugin from a local ZIP file.
     *
     * @param string $component Plugin component name.
     * @param string $zippath Path to ZIP file.
     * @return bool True if installed.
     * @throws \Exception On failure.
     */
    public function install_from_zip(string $component, string $zippath): bool {
        list($type, $name) = $this->parse_component($component);
        if (!$type || !$name) {
            throw new \Exception("Invalid plugin component: {$component}");
        }

        if (!file_exists($zippath)) {
            throw new \Exception("ZIP file not found: {$zippath}");
        }

        // Check if already installed
        $targetdir = $this->get_plugin_directory($type, $name);
        if (is_dir($targetdir)) {
            return false;
        }

        $this->extract_plugin($zippath, $type, $name);
        $this->installedplugins[] = $component;

        return true;
    }

    /**
     * Parse a component name into type and name.
     *
     * @param string $component Component name (e.g., 'mod_forum').
     * @return array [type, name] or [null, null] if invalid.
     */
    protected function parse_component(string $component): array {
        if (strpos($component, '_') === false) {
            return [null, null];
        }

        $parts = explode('_', $component, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }

        return $parts;
    }

    /**
     * Get the installation directory for a plugin.
     *
     * @param string $type Plugin type.
     * @param string $name Plugin name.
     * @return string Full path to plugin directory.
     * @throws \Exception If plugin type is unknown.
     */
    protected function get_plugin_directory(string $type, string $name): string {
        if (!isset($this->plugintypedirs[$type])) {
            throw new \Exception("Unknown plugin type: {$type}");
        }

        $relativepath = $this->plugintypedirs[$type];
        return $this->dirroot . '/' . $relativepath . '/' . $name;
    }

    /**
     * Get download URL for a plugin.
     *
     * @param string $component Plugin component name.
     * @param array $plugindata Plugin data from export.
     * @return string|null Download URL or null if not found.
     */
    protected function get_plugin_download_url(string $component, array $plugindata): ?string {
        // First check if URL is provided in plugin data
        if (!empty($plugindata['download_url'])) {
            return $plugindata['download_url'];
        }

        // Try to get from Moodle Plugin Directory API
        $url = $this->get_moodle_plugin_url($component, $plugindata['version'] ?? null);
        if ($url) {
            return $url;
        }

        return null;
    }

    /**
     * Get plugin download URL from Moodle Plugin Directory.
     *
     * @param string $component Plugin component name.
     * @param int|null $version Specific version to download.
     * @return string|null Download URL or null.
     */
    protected function get_moodle_plugin_url(string $component, ?int $version = null): ?string {
        // Query the Moodle Plugin API
        $apiurl = self::MOODLE_PLUGINS_API . '?plugin=' . urlencode($component);

        $response = $this->http_get($apiurl);
        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || empty($data['versions'])) {
            return null;
        }

        // Find the best matching version
        foreach ($data['versions'] as $ver) {
            if ($version && isset($ver['version']) && $ver['version'] == $version) {
                return $ver['downloadurl'] ?? null;
            }
        }

        // Return latest version if no specific match
        $latest = reset($data['versions']);
        return $latest['downloadurl'] ?? null;
    }

    /**
     * Download a plugin from URL.
     *
     * @param string $url Download URL.
     * @return string Path to downloaded file.
     * @throws \Exception On download failure.
     */
    protected function download_plugin(string $url): string {
        $tempfile = tempnam(sys_get_temp_dir(), 'moodle_plugin_');
        $tempfile .= '.zip';

        $ch = curl_init($url);
        $fp = fopen($tempfile, 'wb');

        if (!$fp) {
            throw new \Exception("Cannot create temp file: {$tempfile}");
        }

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Edulution-Importer/1.0');

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        if (!$result || $httpcode >= 400) {
            @unlink($tempfile);
            throw new \Exception("Failed to download plugin: {$error} (HTTP {$httpcode})");
        }

        // Verify it's a valid ZIP
        if (!$this->is_valid_zip($tempfile)) {
            @unlink($tempfile);
            throw new \Exception("Downloaded file is not a valid ZIP archive");
        }

        return $tempfile;
    }

    /**
     * Extract plugin ZIP to the correct directory.
     *
     * @param string $zipfile Path to ZIP file.
     * @param string $type Plugin type.
     * @param string $name Plugin name.
     * @throws \Exception On extraction failure.
     */
    protected function extract_plugin(string $zipfile, string $type, string $name): void {
        $targetbase = $this->get_plugin_directory($type, $name);
        $targetparent = dirname($targetbase);

        // Ensure parent directory exists
        if (!is_dir($targetparent)) {
            if (!mkdir($targetparent, 0755, true)) {
                throw new \Exception("Cannot create plugin directory: {$targetparent}");
            }
        }

        // Create temp extraction directory
        $tempextract = sys_get_temp_dir() . '/plugin_extract_' . uniqid();
        mkdir($tempextract, 0755, true);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipfile) !== true) {
                throw new \Exception("Cannot open plugin ZIP file");
            }

            if (!$zip->extractTo($tempextract)) {
                $zip->close();
                throw new \Exception("Cannot extract plugin ZIP file");
            }
            $zip->close();

            // Find the actual plugin directory in the extracted content
            // Usually there's a single directory at the root
            $extracted = $this->find_plugin_root($tempextract, $name);
            if (!$extracted) {
                throw new \Exception("Cannot find plugin directory in extracted archive");
            }

            // Move to target location
            if (!rename($extracted, $targetbase)) {
                // Try copy if rename fails (cross-filesystem)
                $this->copy_directory($extracted, $targetbase);
            }

            // Cleanup temp extraction directory
            $this->delete_directory_recursive($tempextract);

        } catch (\Exception $e) {
            // Cleanup on failure
            if (is_dir($tempextract)) {
                $this->delete_directory_recursive($tempextract);
            }
            throw $e;
        }
    }

    /**
     * Find the plugin root directory in extracted content.
     *
     * @param string $extractdir Extraction directory.
     * @param string $expectedname Expected plugin name.
     * @return string|null Path to plugin root or null.
     */
    protected function find_plugin_root(string $extractdir, string $expectedname): ?string {
        // Check if the expected name exists directly
        if (is_dir($extractdir . '/' . $expectedname)) {
            return $extractdir . '/' . $expectedname;
        }

        // Look for a single directory that contains version.php
        $entries = scandir($extractdir);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $extractdir . '/' . $entry;
            if (is_dir($path) && file_exists($path . '/version.php')) {
                return $path;
            }
        }

        // Last resort: return first directory
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $extractdir . '/' . $entry;
            if (is_dir($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Check if file is a valid ZIP archive.
     *
     * @param string $filepath Path to file.
     * @return bool True if valid ZIP.
     */
    protected function is_valid_zip(string $filepath): bool {
        $zip = new \ZipArchive();
        $result = $zip->open($filepath, \ZipArchive::CHECKCONS);
        if ($result === true) {
            $zip->close();
            return true;
        }
        return false;
    }

    /**
     * Copy a directory recursively.
     *
     * @param string $source Source directory.
     * @param string $dest Destination directory.
     */
    protected function copy_directory(string $source, string $dest): void {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destpath = $dest . '/' . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($destpath)) {
                    mkdir($destpath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $destpath);
            }
        }
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir Directory path.
     */
    protected function delete_directory_recursive(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_directory_recursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Perform HTTP GET request.
     *
     * @param string $url URL to fetch.
     * @return string|false Response body or false on failure.
     */
    protected function http_get(string $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Edulution-Importer/1.0');

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode >= 200 && $httpcode < 300) {
            return $response;
        }

        return false;
    }

    /**
     * Get list of installed plugins.
     *
     * @return array List of component names.
     */
    public function get_installed_plugins(): array {
        return $this->installedplugins;
    }

    /**
     * Check if a plugin is compatible with a Moodle version.
     *
     * @param array $plugindata Plugin data.
     * @param int $moodleversion Moodle version to check against.
     * @return bool True if compatible.
     */
    public function check_compatibility(array $plugindata, int $moodleversion): bool {
        $requires = $plugindata['requires'] ?? 0;
        return $requires <= $moodleversion;
    }

    /**
     * Get all known plugin types.
     *
     * @return array Plugin type to directory mapping.
     */
    public function get_plugin_types(): array {
        return $this->plugintypedirs;
    }

    /**
     * Add or update a plugin type mapping.
     *
     * @param string $type Plugin type.
     * @param string $dir Directory relative to dirroot.
     */
    public function add_plugin_type(string $type, string $dir): void {
        $this->plugintypedirs[$type] = $dir;
    }

    /**
     * Verify plugin installation by checking version.php.
     *
     * @param string $component Plugin component name.
     * @return bool True if properly installed.
     */
    public function verify_installation(string $component): bool {
        list($type, $name) = $this->parse_component($component);
        if (!$type || !$name) {
            return false;
        }

        try {
            $targetdir = $this->get_plugin_directory($type, $name);
            return file_exists($targetdir . '/version.php');
        } catch (\Exception $e) {
            return false;
        }
    }
}
