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
 * Main exporter orchestrator class.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

/**
 * Main orchestrator for the export process.
 *
 * Coordinates all sub-exporters, generates manifest.json, and creates
 * the final ZIP package.
 */
class exporter {

    /** @var string Export format version */
    public const EXPORT_VERSION = '2.0.0';

    /** @var export_options Export configuration options */
    protected export_options $options;

    /** @var progress_tracker Progress tracker for status updates */
    protected progress_tracker $tracker;

    /** @var string Temporary directory for export */
    protected string $temp_dir;

    /** @var string Export ID (unique identifier) */
    protected string $export_id;

    /** @var array Results from all sub-exporters */
    protected array $results = [];

    /** @var array Statistics collected during export */
    protected array $stats = [];

    /** @var array Errors encountered during export */
    protected array $errors = [];

    /** @var array Warnings collected during export */
    protected array $warnings = [];

    /** @var int Export start timestamp */
    protected int $start_time;

    /** @var int Export end timestamp */
    protected int $end_time = 0;

    /** @var string Final package path */
    protected string $package_path = '';

    /**
     * Constructor.
     *
     * @param export_options $options Export configuration options.
     * @param progress_tracker|null $tracker Progress tracker instance.
     */
    public function __construct(export_options $options, ?progress_tracker $tracker = null) {
        $this->options = $options;
        $this->export_id = uniqid('export_', true);
        $this->start_time = time();

        // Create progress tracker if not provided.
        if ($tracker === null) {
            $this->tracker = new progress_tracker(
                defined('CLI_SCRIPT') && CLI_SCRIPT,
                $options->verbose ?? false,
                $options->quiet ?? false
            );
        } else {
            $this->tracker = $tracker;
        }
    }

    /**
     * Execute the complete export process.
     *
     * This is the main entry point that coordinates all export operations:
     * 1. Creates temporary directory
     * 2. Runs all configured exporters
     * 3. Generates the manifest
     * 4. Creates the final ZIP package
     * 5. Cleans up temporary files
     *
     * @return array Export results including package path, statistics, and any errors.
     * @throws \moodle_exception On critical export failure.
     */
    public function execute(): array {
        global $CFG;

        $this->tracker->log('info', 'Starting Edulution export...');
        $this->tracker->log('info', 'Export ID: ' . $this->export_id);

        try {
            // Validate options first.
            $validationErrors = $this->options->validate();
            if (!empty($validationErrors)) {
                throw new \moodle_exception('error_validation_failed', 'local_edulution', '', implode('; ', $validationErrors));
            }

            // Check for dry run mode.
            if ($this->options->dry_run) {
                return $this->perform_dry_run();
            }

            // Step 1: Create temporary directory.
            $this->tracker->start_phase(get_string('export_phase_init', 'local_edulution'), 2);
            $this->create_temp_directory();
            $this->tracker->increment('Temporary directory created');

            // Set up progress file for AJAX polling.
            $progressFile = progress_tracker::generate_progress_file($this->export_id);
            $this->tracker->set_progress_file($progressFile);
            $this->tracker->increment('Progress tracking initialized');
            $this->tracker->complete_phase();

            // Step 2: Run all configured exporters.
            $this->run_exporters();

            // Step 3: Generate manifest file.
            $this->tracker->start_phase(get_string('export_phase_manifest', 'local_edulution'), 1);
            $this->generate_manifest();
            $this->tracker->complete_phase();

            // Step 4: Create final ZIP package.
            $this->tracker->start_phase(get_string('export_phase_package', 'local_edulution'), 3);
            $this->create_package();
            $this->tracker->complete_phase();

            // Step 5: Cleanup temporary files.
            $this->tracker->start_phase(get_string('export_phase_cleanup', 'local_edulution'), 1);
            $this->cleanup();
            $this->tracker->complete_phase();

            // Mark export as complete.
            $this->end_time = time();
            $downloadUrl = $this->get_download_url();
            $this->tracker->complete(true, get_string('export_success', 'local_edulution'), $downloadUrl);

            return $this->get_results();

        } catch (\Exception $e) {
            $this->errors[] = $e->getMessage();
            $this->tracker->error('Export failed: ' . $e->getMessage());
            $this->tracker->complete(false, 'Export failed: ' . $e->getMessage());
            $this->cleanup(true);
            throw $e;
        }
    }

    /**
     * Perform a dry run (validation only).
     *
     * @return array Summary of what would be exported.
     */
    protected function perform_dry_run(): array {
        $this->tracker->log('info', 'Dry run mode - no files will be created');

        $summary = [
            'dry_run' => true,
            'success' => true,
            'export_id' => $this->export_id,
            'timestamp' => date('c'),
            'options' => $this->options->to_array(),
            'would_export' => [],
        ];

        // Check what would be exported.
        if ($this->options->full_db) {
            $summary['would_export']['database'] = 'Full MySQL dump';

            if ($this->options->include_moodledata) {
                $exporter = new moodledata_exporter($this->options, $this->tracker, '');
                $summary['would_export']['moodledata'] = $exporter->get_summary();
            }
        }

        if ($this->options->include_plugins) {
            $exporter = new plugin_exporter($this->options, $this->tracker, '');
            $summary['would_export']['plugins'] = $exporter->get_total_count() . ' plugins';
        }

        if ($this->options->include_config) {
            $summary['would_export']['config'] = 'Admin settings and theme configuration';
        }

        if ($this->options->include_users) {
            $exporter = new user_exporter($this->options, $this->tracker, '');
            $summary['would_export']['users'] = $exporter->get_total_count() . ' users';
        }

        if ($this->options->include_courses) {
            $exporter = new course_exporter($this->options, $this->tracker, '');
            $summary['would_export']['courses'] = $exporter->get_total_count() . ' courses';
        }

        $this->tracker->log('info', 'Dry run summary:');
        foreach ($summary['would_export'] as $key => $value) {
            if (is_array($value)) {
                $this->tracker->log('info', "  {$key}: " . json_encode($value));
            } else {
                $this->tracker->log('info', "  {$key}: {$value}");
            }
        }

        $this->tracker->complete(true, 'Dry run completed');

        return $summary;
    }

    /**
     * Create temporary directory for export files.
     *
     * @throws \moodle_exception If directory cannot be created.
     */
    protected function create_temp_directory(): void {
        global $CFG;

        // Get configured export directory or use default.
        $exportDir = get_config('local_edulution', 'export_dir');
        if (empty($exportDir)) {
            $exportDir = $CFG->tempdir . '/edulution_exports';
        }

        // Create base export directory if it doesn't exist.
        if (!is_dir($exportDir)) {
            if (!mkdir($exportDir, 0755, true)) {
                throw new \moodle_exception('error_create_dir', 'local_edulution', '', $exportDir);
            }
        }

        // Create unique temporary directory for this export.
        $this->temp_dir = $exportDir . '/' . $this->export_id;
        if (!mkdir($this->temp_dir, 0755, true)) {
            throw new \moodle_exception('error_create_dir', 'local_edulution', '', $this->temp_dir);
        }

        $this->tracker->log('debug', 'Created temporary directory: ' . $this->temp_dir);
    }

    /**
     * Run all configured exporters.
     */
    protected function run_exporters(): void {
        // Determine export mode.
        if ($this->options->full_db) {
            $this->run_full_database_export();
        } else {
            $this->run_selective_export();
        }
    }

    /**
     * Run full database export mode.
     */
    protected function run_full_database_export(): void {
        $this->tracker->log('info', 'Running full database export mode...');

        // Always export plugins first (for compatibility checking).
        $this->run_single_exporter('plugins', plugin_exporter::class);

        // Export database dump.
        $this->run_single_exporter('database', database_exporter::class);

        // Export moodledata if enabled.
        if ($this->options->include_moodledata) {
            $this->run_single_exporter('moodledata', moodledata_exporter::class);
        }

        // Export configuration.
        if ($this->options->include_config) {
            $this->run_single_exporter('config', config_exporter::class);
        }
    }

    /**
     * Run selective export mode.
     */
    protected function run_selective_export(): void {
        $this->tracker->log('info', 'Running selective export mode...');

        // Plugins (always first for compatibility checking).
        if ($this->options->include_plugins) {
            $this->run_single_exporter('plugins', plugin_exporter::class);
        }

        // Configuration.
        if ($this->options->include_config) {
            $this->run_single_exporter('config', config_exporter::class);
        }

        // Users.
        if ($this->options->include_users) {
            $this->run_single_exporter('users', user_exporter::class);
        }

        // Courses.
        if ($this->options->include_courses) {
            $this->run_single_exporter('courses', course_exporter::class);
        }

        // Categories (if courses not exported).
        if ($this->options->include_categories && !$this->options->include_courses) {
            $this->export_categories();
        }

        // Groups.
        if ($this->options->include_groups) {
            $this->export_groups();
        }

        // Enrollments (if users not exported with enrollments).
        if ($this->options->include_enrollments && !$this->options->include_users) {
            $this->export_enrollments();
        }
    }

    /**
     * Run a single exporter.
     *
     * @param string $key Exporter key.
     * @param string $exporterClass Exporter class name.
     */
    protected function run_single_exporter(string $key, string $exporterClass): void {
        $this->tracker->log('info', "Starting exporter: {$key}");

        try {
            /** @var base_exporter $exporter */
            $exporter = new $exporterClass($this->options, $this->tracker, $this->temp_dir);

            // Start phase for this exporter.
            $totalCount = $exporter->get_total_count();
            $this->tracker->start_phase($exporter->get_name(), max(1, $totalCount));

            // Execute export.
            $result = $exporter->export();

            // Store results.
            $this->results[$key] = [
                'success' => true,
                'data' => $result,
                'stats' => $exporter->get_stats(),
                'files' => $exporter->get_exported_files(),
            ];

            $this->stats[$key] = $exporter->get_stats();

            $this->tracker->complete_phase();
            $this->tracker->log('info', "Completed exporter: {$key}");

        } catch (\Exception $e) {
            $this->errors[] = "[{$key}] " . $e->getMessage();
            $this->tracker->error("Exporter {$key} failed: " . $e->getMessage());

            $this->results[$key] = [
                'success' => false,
                'error' => $e->getMessage(),
            ];

            // Database export failure is critical in full_db mode.
            if ($key === 'database' && $this->options->full_db) {
                throw $e;
            }
        }
    }

    /**
     * Export categories (standalone).
     */
    protected function export_categories(): void {
        global $DB;

        $this->tracker->start_phase(get_string('exporter_categories', 'local_edulution'), 1);

        $categories = $DB->get_records('course_categories', null, 'sortorder ASC');

        $data = [];
        foreach ($categories as $cat) {
            if (!empty($this->options->category_ids) && !in_array($cat->id, $this->options->category_ids)) {
                continue;
            }

            $data[] = [
                'id' => (int) $cat->id,
                'name' => $cat->name,
                'idnumber' => $cat->idnumber ?: null,
                'description' => $cat->description,
                'parent' => (int) $cat->parent,
                'path' => $cat->path,
                'visible' => (bool) $cat->visible,
                'sortorder' => (int) $cat->sortorder,
                'timecreated' => date('c', $cat->timecreated ?? time()),
                'timemodified' => date('c', $cat->timemodified ?? time()),
            ];
        }

        $this->write_json([
            'export_timestamp' => date('c'),
            'total_categories' => count($data),
            'categories' => $data,
        ], 'categories.json');

        $this->stats['categories'] = ['total' => count($data)];
        $this->results['categories'] = [
            'success' => true,
            'stats' => $this->stats['categories'],
        ];

        $this->tracker->increment('Categories exported');
        $this->tracker->complete_phase();
    }

    /**
     * Export groups.
     */
    protected function export_groups(): void {
        global $DB;

        $this->tracker->start_phase(get_string('exporter_groups', 'local_edulution'), 1);

        // Build course filter.
        $courseFilter = '';
        $params = [];
        if (!empty($this->options->course_ids)) {
            list($insql, $params) = $DB->get_in_or_equal($this->options->course_ids);
            $courseFilter = " WHERE courseid {$insql}";
        }

        // Get groups.
        $sql = "SELECT * FROM {groups}" . $courseFilter . " ORDER BY courseid, name";
        $groups = $DB->get_records_sql($sql, $params);

        $groupsData = [];
        foreach ($groups as $group) {
            $groupsData[] = [
                'id' => (int) $group->id,
                'course_id' => (int) $group->courseid,
                'name' => $group->name,
                'idnumber' => $group->idnumber ?: null,
                'description' => $group->description,
                'timecreated' => date('c', $group->timecreated),
                'timemodified' => date('c', $group->timemodified),
            ];
        }

        // Get groupings.
        $sql = "SELECT * FROM {groupings}" . $courseFilter . " ORDER BY courseid, name";
        $groupings = $DB->get_records_sql($sql, $params);

        $groupingsData = [];
        foreach ($groupings as $grouping) {
            $groupingsData[] = [
                'id' => (int) $grouping->id,
                'course_id' => (int) $grouping->courseid,
                'name' => $grouping->name,
                'idnumber' => $grouping->idnumber ?: null,
                'description' => $grouping->description,
                'timecreated' => date('c', $grouping->timecreated),
                'timemodified' => date('c', $grouping->timemodified),
            ];
        }

        // Get group members.
        $membersData = [];
        if (!empty($groupsData)) {
            $groupIds = array_column($groupsData, 'id');
            list($insql, $params) = $DB->get_in_or_equal($groupIds);
            $sql = "SELECT gm.*, g.courseid
                    FROM {groups_members} gm
                    JOIN {groups} g ON g.id = gm.groupid
                    WHERE gm.groupid {$insql}
                    ORDER BY gm.groupid, gm.userid";
            $members = $DB->get_records_sql($sql, $params);

            foreach ($members as $member) {
                if (!empty($this->options->user_ids) && !in_array($member->userid, $this->options->user_ids)) {
                    continue;
                }

                $membersData[] = [
                    'group_id' => (int) $member->groupid,
                    'user_id' => (int) $member->userid,
                    'timeadded' => date('c', $member->timeadded),
                ];
            }
        }

        $this->write_json([
            'export_timestamp' => date('c'),
            'groups' => $groupsData,
            'groupings' => $groupingsData,
            'members' => $membersData,
            'statistics' => [
                'total_groups' => count($groupsData),
                'total_groupings' => count($groupingsData),
                'total_members' => count($membersData),
            ],
        ], 'groups.json');

        $this->stats['groups'] = [
            'groups' => count($groupsData),
            'groupings' => count($groupingsData),
            'members' => count($membersData),
        ];
        $this->results['groups'] = [
            'success' => true,
            'stats' => $this->stats['groups'],
        ];

        $this->tracker->increment('Groups exported');
        $this->tracker->complete_phase();
    }

    /**
     * Export enrollments (standalone).
     */
    protected function export_enrollments(): void {
        global $DB;

        $this->tracker->start_phase(get_string('exporter_enrollments', 'local_edulution'), 1);

        $sql = "SELECT ue.id, ue.userid, e.courseid, e.enrol as method,
                       ue.status, ue.timestart, ue.timeend, ue.timecreated, ue.timemodified
                FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid
                ORDER BY e.courseid, ue.userid";

        $enrollments = $DB->get_records_sql($sql);

        $data = [];
        foreach ($enrollments as $enrol) {
            // Apply filters.
            if (!empty($this->options->course_ids) && !in_array($enrol->courseid, $this->options->course_ids)) {
                continue;
            }
            if (!empty($this->options->user_ids) && !in_array($enrol->userid, $this->options->user_ids)) {
                continue;
            }

            $data[] = [
                'id' => (int) $enrol->id,
                'user_id' => (int) $enrol->userid,
                'course_id' => (int) $enrol->courseid,
                'method' => $enrol->method,
                'status' => $enrol->status == 0 ? 'active' : 'suspended',
                'timestart' => $enrol->timestart ? date('c', $enrol->timestart) : null,
                'timeend' => $enrol->timeend ? date('c', $enrol->timeend) : null,
                'timecreated' => date('c', $enrol->timecreated),
                'timemodified' => date('c', $enrol->timemodified),
            ];
        }

        $this->write_json([
            'export_timestamp' => date('c'),
            'total_enrollments' => count($data),
            'enrollments' => $data,
        ], 'enrollments.json');

        $this->stats['enrollments'] = ['total' => count($data)];
        $this->results['enrollments'] = [
            'success' => true,
            'stats' => $this->stats['enrollments'],
        ];

        $this->tracker->increment('Enrollments exported');
        $this->tracker->complete_phase();
    }

    /**
     * Generate the manifest file.
     */
    protected function generate_manifest(): void {
        global $CFG, $SITE;

        $duration = time() - $this->start_time;
        $totalSize = $this->calculate_directory_size($this->temp_dir);

        $manifest = [
            'format_version' => self::EXPORT_VERSION,
            'export_id' => $this->export_id,
            'export_type' => $this->options->full_db ? 'full_database' : 'selective',
            'export_timestamp' => date('c', $this->start_time),
            'export_duration_seconds' => $duration,

            'source_moodle' => [
                'version' => $CFG->version,
                'release' => $CFG->release,
                'branch' => $CFG->branch ?? 'unknown',
                'wwwroot' => $CFG->wwwroot,
                'dbtype' => $CFG->dbtype,
                'php_version' => PHP_VERSION,
            ],

            'site' => [
                'fullname' => $SITE->fullname ?? 'Unknown',
                'shortname' => $SITE->shortname ?? 'moodle',
            ],

            'target_platform' => [
                'type' => 'edulution',
                'features' => ['keycloak_sso', 'category_sync', 'user_sync', 'course_migration'],
            ],

            'options' => $this->options->to_array(),

            'components' => [],
            'statistics' => $this->stats,

            'summary' => [
                'total_size_bytes' => $totalSize,
                'total_size_formatted' => $this->format_size($totalSize),
                'components_exported' => count(array_filter($this->results, fn($r) => $r['success'] ?? false)),
                'components_failed' => count(array_filter($this->results, fn($r) => !($r['success'] ?? false))),
            ],

            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];

        // Add component information from results.
        foreach ($this->results as $key => $result) {
            if ($result['success'] ?? false) {
                $manifest['components'][$key] = [
                    'exported' => true,
                    'stats' => $result['stats'] ?? [],
                    'files' => array_map(function ($file) {
                        return [
                            'filename' => $file['filename'] ?? $file,
                            'size' => $file['size'] ?? 0,
                            'type' => $file['type'] ?? 'unknown',
                        ];
                    }, $result['files'] ?? []),
                ];
            } else {
                $manifest['components'][$key] = [
                    'exported' => false,
                    'error' => $result['error'] ?? 'Unknown error',
                ];
            }
        }

        // Add security warning for full database exports.
        if ($this->options->full_db) {
            $manifest['security_warning'] = 'This export contains sensitive data including password hashes. ' .
                'Handle with extreme care and delete after migration is complete.';
        }

        // Write manifest file.
        $this->write_json($manifest, 'manifest.json');

        $this->tracker->log('info', 'Generated manifest file');
        $this->tracker->increment('Manifest generated');
    }

    /**
     * Create the final ZIP package.
     *
     * @throws \moodle_exception If ZIP creation fails.
     */
    protected function create_package(): void {
        $this->tracker->increment('Creating ZIP archive...');

        // Determine output path.
        $this->package_path = $this->options->get_output_path();

        // Use package builder.
        $builder = new package_builder(
            $this->temp_dir,
            $this->package_path,
            $this->options->compression_level,
            $this->tracker,
            $this->options->split_threshold_mb
        );

        $this->package_path = $builder->build();

        // Verify the archive.
        if (!$builder->verify()) {
            $this->warnings[] = 'ZIP archive verification completed with warnings';
        }

        // Store package stats.
        $this->stats['package'] = [
            'filename' => basename($this->package_path),
            'path' => $this->package_path,
            'size_bytes' => $builder->get_size(),
            'size_formatted' => $this->format_size($builder->get_size()),
            'checksum_sha256' => $builder->get_zip_checksum(),
        ];

        $this->tracker->increment('ZIP archive created');
        $this->tracker->log('info', sprintf(
            'Created package: %s (%s)',
            basename($this->package_path),
            $this->stats['package']['size_formatted']
        ));
    }

    /**
     * Write data to JSON file.
     *
     * @param array $data Data to write.
     * @param string $filename Filename (relative to temp_dir).
     * @return string Full path to written file.
     */
    protected function write_json(array $data, string $filename): string {
        $path = $this->temp_dir . '/' . $filename;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($path, $json);

        return $path;
    }

    /**
     * Calculate total size of a directory.
     *
     * @param string $dir Directory path.
     * @return int Size in bytes.
     */
    protected function calculate_directory_size(string $dir): int {
        $size = 0;

        if (!is_dir($dir)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Clean up temporary files.
     *
     * @param bool $force Force cleanup even if keep_temp option is set.
     */
    protected function cleanup(bool $force = false): void {
        if (!empty($this->temp_dir) && is_dir($this->temp_dir)) {
            $this->delete_directory($this->temp_dir);
            $this->tracker->log('debug', 'Cleaned up temporary directory');
        }

        $this->tracker->increment('Cleanup completed');
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir Directory path.
     * @return bool True on success.
     */
    protected function delete_directory(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_directory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * Get the download URL for the export package.
     *
     * @return string Download URL.
     */
    protected function get_download_url(): string {
        global $CFG;

        if (empty($this->package_path) || !file_exists($this->package_path)) {
            return '';
        }

        $filename = basename($this->package_path);

        $url = new \moodle_url('/local/edulution/download.php', [
            'file' => $filename,
            'sesskey' => sesskey(),
        ]);

        return $url->out(false);
    }

    /**
     * Get the export results.
     *
     * @return array Export results.
     */
    public function get_results(): array {
        return [
            'success' => empty($this->errors),
            'export_id' => $this->export_id,
            'package' => $this->stats['package'] ?? null,
            'download_url' => $this->get_download_url(),
            'duration_seconds' => ($this->end_time ?: time()) - $this->start_time,
            'duration_formatted' => $this->tracker->get_elapsed_time_formatted(),
            'components' => $this->results,
            'statistics' => $this->stats,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Get the export ID.
     *
     * @return string Export ID.
     */
    public function get_export_id(): string {
        return $this->export_id;
    }

    /**
     * Get the package path.
     *
     * @return string Package file path.
     */
    public function get_package_path(): string {
        return $this->package_path;
    }

    /**
     * Get the progress tracker.
     *
     * @return progress_tracker Progress tracker instance.
     */
    public function get_tracker(): progress_tracker {
        return $this->tracker;
    }

    /**
     * Get export options.
     *
     * @return export_options Export options.
     */
    public function get_options(): export_options {
        return $this->options;
    }

    /**
     * Format file size for display.
     *
     * @param int $bytes File size in bytes.
     * @return string Formatted size string.
     */
    protected function format_size(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Clean up old export files based on retention policy.
     *
     * @param int|null $retention_days Number of days to retain exports.
     * @return int Number of files deleted.
     */
    public static function cleanup_old_exports(?int $retention_days = null): int {
        global $CFG;

        if ($retention_days === null) {
            $retention_days = (int) get_config('local_edulution', 'export_retention_days');
            if ($retention_days <= 0) {
                $retention_days = 30;
            }
        }

        $exportDir = get_config('local_edulution', 'export_dir');
        if (empty($exportDir)) {
            $exportDir = $CFG->tempdir . '/edulution_exports';
        }

        if (!is_dir($exportDir)) {
            return 0;
        }

        $threshold = time() - ($retention_days * 86400);
        $deleted = 0;

        // Clean up old ZIP files.
        $files = glob($exportDir . '/*.zip');
        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }

        // Clean up old temp directories.
        $dirs = glob($exportDir . '/export_*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            if (filemtime($dir) < $threshold) {
                self::delete_directory_static($dir);
                $deleted++;
            }
        }

        // Clean up progress files.
        progress_tracker::cleanup_progress_files($retention_days * 86400);

        return $deleted;
    }

    /**
     * Static method to recursively delete a directory.
     *
     * @param string $dir Directory path.
     * @return bool True on success.
     */
    protected static function delete_directory_static(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::delete_directory_static($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }
}
