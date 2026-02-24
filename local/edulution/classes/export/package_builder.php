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
 * ZIP package builder for exports.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

use ZipArchive;

/**
 * Builds the final ZIP package from exported data.
 *
 * Creates final ZIP with all exported data, supports compression levels,
 * and generates checksums.
 */
class package_builder {

    /** @var string Source directory containing export files */
    protected string $source_dir;

    /** @var string Output path for the ZIP file */
    protected string $output_path;

    /** @var int Compression level (0-9) */
    protected int $compression_level;

    /** @var progress_tracker Progress tracker */
    protected progress_tracker $tracker;

    /** @var int Split threshold in bytes */
    protected int $split_threshold;

    /** @var array File checksums */
    protected array $checksums = [];

    /**
     * Constructor.
     *
     * @param string $source_dir Source directory containing export files.
     * @param string $output_path Output ZIP file path.
     * @param int $compression_level Compression level (0-9).
     * @param progress_tracker $tracker Progress tracker.
     * @param int $split_threshold_mb Split threshold in MB (default: 500).
     */
    public function __construct(
        string $source_dir,
        string $output_path,
        int $compression_level,
        progress_tracker $tracker,
        int $split_threshold_mb = 500
    ) {
        $this->source_dir = rtrim($source_dir, '/');
        $this->output_path = $output_path;
        $this->compression_level = max(0, min(9, $compression_level));
        $this->tracker = $tracker;
        $this->split_threshold = $split_threshold_mb * 1024 * 1024;
    }

    /**
     * Build the ZIP package.
     *
     * @return string Path to the created ZIP file.
     * @throws \moodle_exception If ZIP creation fails.
     */
    public function build(): string {
        $this->tracker->log('info', 'Creating ZIP package...');

        // Ensure output directory exists.
        $outputDir = dirname($this->output_path);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Count files for progress tracking.
        $files = $this->get_all_files();
        $totalFiles = count($files);

        $this->tracker->start_phase(get_string('phase_packaging', 'local_edulution'), $totalFiles);

        // Create ZIP archive.
        $zip = new ZipArchive();
        $result = $zip->open($this->output_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new \moodle_exception('error_zip_create_failed', 'local_edulution', '', $this->get_zip_error($result));
        }

        // Determine compression method.
        $compressionMethod = $this->compression_level === 0 ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;

        // Add files to archive.
        $count = 0;
        $totalSize = 0;

        foreach ($files as $file) {
            $relativePath = $this->get_relative_path($file);

            // Add file to archive.
            if (!$zip->addFile($file, $relativePath)) {
                $this->tracker->warning("Failed to add file to archive: {$relativePath}");
                continue;
            }

            // Set compression method for the file.
            if (method_exists($zip, 'setCompressionName')) {
                $zip->setCompressionName($relativePath, $compressionMethod);
            }

            // Calculate checksum for important files.
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['json', 'sql', 'gz', 'mbz'])) {
                $this->checksums[$relativePath] = hash_file('sha256', $file);
            }

            $totalSize += filesize($file);
            $count++;

            // Update progress periodically.
            if ($count % 100 === 0 || $count === $totalFiles) {
                $this->tracker->update($count, "Adding files: {$count}/{$totalFiles}");
            }
        }

        // Add checksums file.
        $checksumContent = $this->generate_checksums_file();
        $zip->addFromString('checksums.sha256', $checksumContent);

        // Close archive.
        if (!$zip->close()) {
            throw new \moodle_exception('error_zip_close_failed', 'local_edulution');
        }

        // Verify the archive was created.
        if (!file_exists($this->output_path)) {
            throw new \moodle_exception('error_zip_not_created', 'local_edulution');
        }

        $zipSize = filesize($this->output_path);

        $this->tracker->log('info', sprintf(
            'ZIP package created: %s (%d files, %s -> %s)',
            basename($this->output_path),
            $count,
            $this->format_size($totalSize),
            $this->format_size($zipSize)
        ));

        $this->tracker->complete_phase();

        return $this->output_path;
    }

    /**
     * Get all files in the source directory.
     *
     * @return array Array of file paths.
     */
    protected function get_all_files(): array {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Get relative path for a file within the archive.
     *
     * @param string $filepath Full file path.
     * @return string Relative path.
     */
    protected function get_relative_path(string $filepath): string {
        return ltrim(str_replace($this->source_dir, '', $filepath), '/\\');
    }

    /**
     * Generate checksums file content.
     *
     * @return string Checksums in sha256sum format.
     */
    protected function generate_checksums_file(): string {
        $lines = [];
        $lines[] = '# SHA256 checksums for Edulution export';
        $lines[] = '# Generated: ' . date('c');
        $lines[] = '';

        ksort($this->checksums);
        foreach ($this->checksums as $file => $hash) {
            $lines[] = "{$hash}  {$file}";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Get ZIP error message.
     *
     * @param int $code Error code.
     * @return string Error message.
     */
    protected function get_zip_error(int $code): string {
        $errors = [
            ZipArchive::ER_EXISTS => 'File already exists',
            ZipArchive::ER_INCONS => 'Zip archive inconsistent',
            ZipArchive::ER_INVAL => 'Invalid argument',
            ZipArchive::ER_MEMORY => 'Memory allocation failure',
            ZipArchive::ER_NOENT => 'No such file',
            ZipArchive::ER_NOZIP => 'Not a zip archive',
            ZipArchive::ER_OPEN => 'Cannot open file',
            ZipArchive::ER_READ => 'Read error',
            ZipArchive::ER_SEEK => 'Seek error',
        ];

        return $errors[$code] ?? "Unknown error (code: {$code})";
    }

    /**
     * Split a large file into parts.
     *
     * @param string $filepath Path to the large file.
     * @param int|null $maxSize Maximum size per part in bytes (uses split_threshold if null).
     * @return array Array of part file paths.
     */
    public function split_file(string $filepath, ?int $maxSize = null): array {
        $maxSize = $maxSize ?? $this->split_threshold;

        if (!file_exists($filepath) || filesize($filepath) <= $maxSize) {
            return [$filepath];
        }

        $parts = [];
        $partNum = 1;
        $handle = fopen($filepath, 'rb');

        if (!$handle) {
            return [$filepath];
        }

        $baseName = pathinfo($filepath, PATHINFO_FILENAME);
        $extension = pathinfo($filepath, PATHINFO_EXTENSION);
        $dir = dirname($filepath);

        while (!feof($handle)) {
            $chunk = fread($handle, $maxSize);
            if ($chunk === false || strlen($chunk) === 0) {
                break;
            }

            $partPath = sprintf('%s/%s.part%03d.%s', $dir, $baseName, $partNum, $extension);
            file_put_contents($partPath, $chunk);
            $parts[] = $partPath;
            $partNum++;
        }

        fclose($handle);

        // Only remove original if we actually split it.
        if (count($parts) > 1) {
            unlink($filepath);

            // Create a manifest for the split file.
            $splitManifest = [
                'original_name' => basename($filepath),
                'parts' => array_map('basename', $parts),
                'total_parts' => count($parts),
                'original_size' => array_sum(array_map('filesize', $parts)),
                'split_timestamp' => date('c'),
            ];
            $manifestPath = $dir . '/' . $baseName . '.split.json';
            file_put_contents($manifestPath, json_encode($splitManifest, JSON_PRETTY_PRINT));
            $parts[] = $manifestPath;

            $this->tracker->log('info', sprintf(
                'Split %s into %d parts',
                basename($filepath),
                count($parts) - 1
            ));
        }

        return $parts;
    }

    /**
     * Get the final ZIP file size.
     *
     * @return int Size in bytes, or 0 if file doesn't exist.
     */
    public function get_size(): int {
        if (file_exists($this->output_path)) {
            return filesize($this->output_path);
        }
        return 0;
    }

    /**
     * Get the checksums.
     *
     * @return array File checksums.
     */
    public function get_checksums(): array {
        return $this->checksums;
    }

    /**
     * Calculate checksum for the final ZIP file.
     *
     * @return string|null SHA256 checksum or null if file doesn't exist.
     */
    public function get_zip_checksum(): ?string {
        if (file_exists($this->output_path)) {
            return hash_file('sha256', $this->output_path);
        }
        return null;
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
     * Verify ZIP archive integrity.
     *
     * @return bool True if archive is valid.
     */
    public function verify(): bool {
        if (!file_exists($this->output_path)) {
            return false;
        }

        $zip = new ZipArchive();
        $result = $zip->open($this->output_path, ZipArchive::CHECKCONS);

        if ($result !== true) {
            $this->tracker->error('ZIP verification failed: ' . $this->get_zip_error($result));
            return false;
        }

        $isValid = true;

        // Check that essential files exist.
        $essentialFiles = ['manifest.json'];
        foreach ($essentialFiles as $file) {
            if ($zip->locateName($file) === false) {
                $this->tracker->warning("Essential file missing from archive: {$file}");
                $isValid = false;
            }
        }

        $zip->close();

        return $isValid;
    }

    /**
     * Get archive information.
     *
     * @return array Archive info.
     */
    public function get_archive_info(): array {
        if (!file_exists($this->output_path)) {
            return ['exists' => false];
        }

        $zip = new ZipArchive();
        if ($zip->open($this->output_path) !== true) {
            return [
                'exists' => true,
                'valid' => false,
            ];
        }

        $info = [
            'exists' => true,
            'valid' => true,
            'path' => $this->output_path,
            'filename' => basename($this->output_path),
            'size' => filesize($this->output_path),
            'size_formatted' => $this->format_size(filesize($this->output_path)),
            'num_files' => $zip->numFiles,
            'checksum' => $this->get_zip_checksum(),
        ];

        $zip->close();

        return $info;
    }
}
