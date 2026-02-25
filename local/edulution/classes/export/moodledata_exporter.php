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
 * Moodledata directory exporter.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

/**
 * Exporter for moodledata directory.
 *
 * Copies essential moodledata directories (filedir, lang, repository)
 * while skipping temporary, cache, and session directories.
 */
class moodledata_exporter extends base_exporter
{

    /** @var array Directories to include in export */
    protected const INCLUDE_DIRS = [
        'filedir',       // Main file storage (required for course files, user files, etc.).
        'lang',          // Custom language strings.
        'repository',    // Repository plugin files.
    ];

    /** @var array Directories to always exclude */
    protected const EXCLUDE_DIRS = [
        'temp',
        'cache',
        'localcache',
        'sessions',
        'trashdir',
        'lock',
        'antivirus_quarantine',
        'muc',
    ];

    /** @var int Total file count (calculated during export) */
    protected int $file_count = 0;

    /** @var int Total size in bytes */
    protected int $total_size = 0;

    /**
     * Get the exporter name.
     *
     * @return string Human-readable name.
     */
    public function get_name(): string
    {
        return get_string('exporter_moodledata', 'local_edulution');
    }

    /**
     * Get the language string key.
     *
     * @return string Language string key.
     */
    public function get_string_key(): string
    {
        return 'moodledata';
    }

    /**
     * Get total count for progress tracking.
     *
     * @return int Number of directories to process.
     */
    public function get_total_count(): int
    {
        return count(self::INCLUDE_DIRS);
    }

    /**
     * Execute the moodledata export.
     *
     * @return array Exported data metadata.
     * @throws \moodle_exception On export failure.
     */
    public function export(): array
    {
        global $CFG;

        $this->log('info', 'Starting moodledata export...');
        $dataroot = $CFG->dataroot;

        if (!is_dir($dataroot)) {
            throw new \moodle_exception('error_dataroot_not_found', 'local_edulution', '', $dataroot);
        }

        // Create moodledata export directory.
        $moodledataDir = $this->get_subdir('moodledata');

        $directories = [];
        $step = 0;

        foreach (self::INCLUDE_DIRS as $dir) {
            $step++;
            $this->update_progress($step, "Exporting {$dir}...");

            $sourcePath = $dataroot . '/' . $dir;
            $destPath = $moodledataDir . '/' . $dir;

            if (!is_dir($sourcePath)) {
                $this->log('warning', "Directory not found: {$dir}");
                $directories[$dir] = [
                    'status' => 'not_found',
                    'size_bytes' => 0,
                    'files_count' => 0,
                ];
                continue;
            }

            // Copy directory recursively.
            $result = $this->copy_directory_recursive($sourcePath, $destPath, $dir);

            $directories[$dir] = [
                'status' => 'copied',
                'size_bytes' => $result['size'],
                'size_formatted' => $this->format_size($result['size']),
                'files_count' => $result['files'],
                'dirs_count' => $result['dirs'],
                'skipped_count' => $result['skipped'],
            ];

            $this->total_size += $result['size'];
            $this->file_count += $result['files'];

            $this->log('info', sprintf(
                'Copied %s: %s (%d files)',
                $dir,
                $this->format_size($result['size']),
                $result['files']
            ));
        }

        // Build result data.
        $data = [
            'export_timestamp' => date('c'),
            'source_path' => $dataroot,
            'directories' => $directories,
            'summary' => [
                'total_size_bytes' => $this->total_size,
                'total_size_formatted' => $this->format_size($this->total_size),
                'total_files' => $this->file_count,
                'directories_exported' => count(array_filter($directories, fn($d) => $d['status'] === 'copied')),
                'directories_missing' => count(array_filter($directories, fn($d) => $d['status'] === 'not_found')),
            ],
            'excluded_directories' => self::EXCLUDE_DIRS,
        ];

        // Write metadata file.
        $this->write_json($data, 'moodledata/metadata.json');

        // Update statistics.
        $this->stats = [
            'total_size' => $this->total_size,
            'total_size_formatted' => $this->format_size($this->total_size),
            'files_copied' => $this->file_count,
            'directories_exported' => $data['summary']['directories_exported'],
        ];

        $this->log('info', sprintf(
            'Moodledata export complete: %s (%d files)',
            $this->stats['total_size_formatted'],
            $this->file_count
        ));

        return $data;
    }

    /**
     * Recursively copy a directory.
     *
     * @param string $source Source directory.
     * @param string $destination Destination directory.
     * @param string $dirName Directory name for logging.
     * @return array Copy statistics.
     */
    protected function copy_directory_recursive(string $source, string $destination, string $dirName): array
    {
        $stats = [
            'size' => 0,
            'files' => 0,
            'dirs' => 0,
            'skipped' => 0,
        ];

        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        // Use RecursiveIteratorIterator for efficient directory traversal.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $lastProgressUpdate = time();
        $filesProcessed = 0;

        foreach ($iterator as $item) {
            $subPath = $iterator->getSubPathname();
            $destPath = $destination . '/' . $subPath;

            // Skip excluded subdirectories.
            if ($this->should_skip_path($subPath)) {
                $stats['skipped']++;
                continue;
            }

            if ($item->isDir()) {
                // Create directory.
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
                $stats['dirs']++;
            } else {
                // Copy file.
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }

                if (copy($item->getPathname(), $destPath)) {
                    $stats['size'] += $item->getSize();
                    $stats['files']++;
                    $filesProcessed++;

                    // Update progress periodically (every 5 seconds).
                    if (time() - $lastProgressUpdate >= 5) {
                        $this->log('debug', "Copying {$dirName}: {$filesProcessed} files...");
                        $lastProgressUpdate = time();
                    }
                } else {
                    $stats['skipped']++;
                    $this->log('warning', "Failed to copy: {$item->getPathname()}");
                }
            }
        }

        return $stats;
    }

    /**
     * Check if a path should be skipped.
     *
     * @param string $path Relative path.
     * @return bool True if path should be skipped.
     */
    protected function should_skip_path(string $path): bool
    {
        // Check for excluded directories in path.
        $pathParts = explode(DIRECTORY_SEPARATOR, $path);
        foreach ($pathParts as $part) {
            if (in_array($part, self::EXCLUDE_DIRS, true)) {
                return true;
            }
        }

        // Skip hidden files/directories (starting with .).
        foreach ($pathParts as $part) {
            if (strpos($part, '.') === 0 && $part !== '.' && $part !== '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get summary of moodledata without copying.
     *
     * @return array Summary data.
     */
    public function get_summary(): array
    {
        global $CFG;

        $dataroot = $CFG->dataroot;
        $summary = [
            'path' => $dataroot,
            'directories' => [],
            'estimated_export_size' => 0,
        ];

        foreach (self::INCLUDE_DIRS as $dir) {
            $path = $dataroot . '/' . $dir;
            if (is_dir($path)) {
                $dirStats = $this->get_directory_stats($path);
                $summary['directories'][$dir] = [
                    'exists' => true,
                    'readable' => is_readable($path),
                    'estimated_size' => $dirStats['size'],
                    'estimated_size_formatted' => $this->format_size($dirStats['size']),
                    'estimated_files' => $dirStats['files'],
                ];
                $summary['estimated_export_size'] += $dirStats['size'];
            } else {
                $summary['directories'][$dir] = [
                    'exists' => false,
                ];
            }
        }

        $summary['estimated_export_size_formatted'] = $this->format_size($summary['estimated_export_size']);

        return $summary;
    }

    /**
     * Get quick statistics for a directory.
     *
     * Uses sampling for large directories to estimate size.
     *
     * @param string $path Directory path.
     * @return array Statistics array.
     */
    protected function get_directory_stats(string $path): array
    {
        $stats = [
            'size' => 0,
            'files' => 0,
        ];

        // For filedir, use sampling approach (it can be very large).
        if (basename($path) === 'filedir') {
            return $this->get_filedir_stats($path);
        }

        // For other directories, do a full scan (they're usually smaller).
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $stats['size'] += $file->getSize();
                    $stats['files']++;
                }
            }
        } catch (\Exception $e) {
            $this->log('warning', "Could not scan directory {$path}: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Get estimated statistics for filedir using sampling.
     *
     * @param string $path Filedir path.
     * @return array Statistics array.
     */
    protected function get_filedir_stats(string $path): array
    {
        $stats = [
            'size' => 0,
            'files' => 0,
        ];

        // Get first-level directories (00-ff).
        $firstLevel = glob($path . '/*', GLOB_ONLYDIR);
        $totalFirstLevel = count($firstLevel);

        if ($totalFirstLevel === 0) {
            return $stats;
        }

        // Sample a subset of directories.
        $sampleSize = min(16, $totalFirstLevel);
        $sampleDirs = array_slice($firstLevel, 0, $sampleSize);
        $sampleSize = 0;
        $sampleFiles = 0;

        foreach ($sampleDirs as $dir) {
            $secondLevel = glob($dir . '/*', GLOB_ONLYDIR);
            foreach ($secondLevel as $subdir) {
                $files = glob($subdir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $sampleSize += filesize($file);
                        $sampleFiles++;
                    }
                }
            }
        }

        // Extrapolate to full directory.
        if (count(array_slice($firstLevel, 0, $sampleSize)) > 0) {
            $multiplier = $totalFirstLevel / count(array_slice($firstLevel, 0, min(16, $totalFirstLevel)));
            $stats['size'] = (int) ($sampleSize * $multiplier);
            $stats['files'] = (int) ($sampleFiles * $multiplier);
        }

        return $stats;
    }
}
