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
 * Progress tracker for export operations.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\export;

defined('MOODLE_INTERNAL') || die();

/**
 * Progress tracker for export operations.
 *
 * Provides both file-based progress tracking for AJAX polling
 * and CLI output support for command-line exports.
 */
class progress_tracker
{

    /** @var int Total steps across all phases */
    protected int $total_steps = 0;

    /** @var int Current step */
    protected int $current_step = 0;

    /** @var string Current phase name */
    protected string $current_phase = '';

    /** @var int Steps in current phase */
    protected int $phase_steps = 0;

    /** @var int Current step within phase */
    protected int $phase_current = 0;

    /** @var string Current status message */
    protected string $status_message = '';

    /** @var int Start timestamp */
    protected int $start_time;

    /** @var bool CLI mode */
    protected bool $cli_mode;

    /** @var bool Verbose output */
    protected bool $verbose;

    /** @var bool Quiet mode */
    protected bool $quiet;

    /** @var string|null Session key for web progress */
    protected ?string $session_key = null;

    /** @var string|null Progress file path for persistence */
    protected ?string $progress_file = null;

    /** @var array Log entries */
    protected array $log = [];

    /** @var array Phase history */
    protected array $phases = [];

    /** @var bool Export completed flag */
    protected bool $completed = false;

    /** @var bool Export success flag */
    protected bool $success = true;

    /** @var string Download URL (set on completion) */
    protected string $download_url = '';

    /**
     * Constructor.
     *
     * @param bool $cli_mode Whether running in CLI mode.
     * @param bool $verbose Enable verbose output.
     * @param bool $quiet Enable quiet mode.
     */
    public function __construct(bool $cli_mode = false, bool $verbose = false, bool $quiet = false)
    {
        $this->cli_mode = $cli_mode;
        $this->verbose = $verbose;
        $this->quiet = $quiet;
        $this->start_time = time();

        // Generate session key for web UI.
        if (!$cli_mode && function_exists('sesskey')) {
            $this->session_key = 'edulution_export_' . sesskey();
        }
    }

    /**
     * Set progress file for file-based persistence (AJAX polling).
     *
     * @param string $path Full path to progress file.
     */
    public function set_progress_file(string $path): void
    {
        $this->progress_file = $path;

        // Create directory if needed.
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Get the progress file path.
     *
     * @return string|null Progress file path or null if not set.
     */
    public function get_progress_file(): ?string
    {
        return $this->progress_file;
    }

    /**
     * Start a new phase.
     *
     * @param string $phase_name Human-readable phase name.
     * @param int $steps Number of steps in this phase.
     */
    public function start_phase(string $phase_name, int $steps): void
    {
        // Record completed phase.
        if (!empty($this->current_phase)) {
            $this->phases[] = [
                'name' => $this->current_phase,
                'steps' => $this->phase_steps,
                'completed' => $this->phase_current,
                'duration' => time() - ($this->phases[count($this->phases) - 1]['start_time'] ?? $this->start_time),
            ];
        }

        $this->current_phase = $phase_name;
        $this->phase_steps = max(1, $steps);
        $this->phase_current = 0;
        $this->total_steps += $steps;

        $this->log('info', "Starting phase: {$phase_name} ({$steps} items)");
        $this->output_progress();
        $this->persist_progress();
    }

    /**
     * Update progress within current phase.
     *
     * @param int $step Current step number within phase.
     * @param string $message Status message.
     */
    public function update(int $step, string $message = ''): void
    {
        $this->phase_current = min($step, $this->phase_steps);
        $this->current_step++;
        $this->status_message = $message;

        if ($this->verbose && !empty($message)) {
            $this->log('debug', $message);
        }

        $this->output_progress();
        $this->persist_progress();
    }

    /**
     * Increment progress by one step.
     *
     * @param string $message Status message.
     */
    public function increment(string $message = ''): void
    {
        $this->phase_current = min($this->phase_current + 1, $this->phase_steps);
        $this->current_step++;
        $this->status_message = $message;

        if ($this->verbose && !empty($message)) {
            $this->log('debug', $message);
        }

        $this->output_progress();
        $this->persist_progress();
    }

    /**
     * Complete current phase.
     */
    public function complete_phase(): void
    {
        $this->phase_current = $this->phase_steps;
        $this->log('info', "Completed phase: {$this->current_phase}");
        $this->output_progress();
        $this->persist_progress();
    }

    /**
     * Log a message.
     *
     * @param string $level Log level (info, warning, error, debug).
     * @param string $message Log message.
     */
    public function log(string $level, string $message): void
    {
        $entry = [
            'time' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
            'level' => $level,
            'message' => $message,
        ];
        $this->log[] = $entry;

        // CLI output.
        if ($this->cli_mode && !$this->quiet) {
            if ($level === 'error') {
                if (function_exists('cli_problem')) {
                    cli_problem($message);
                } else {
                    fwrite(STDERR, "ERROR: {$message}\n");
                }
            } elseif ($level === 'warning') {
                if (function_exists('cli_problem')) {
                    cli_problem("Warning: {$message}");
                } else {
                    fwrite(STDERR, "WARNING: {$message}\n");
                }
            } elseif ($this->verbose || $level === 'info') {
                if (function_exists('mtrace')) {
                    mtrace($message);
                } else {
                    echo $message . "\n";
                }
            }
        }
    }

    /**
     * Log an error message.
     *
     * @param string $message Error message.
     */
    public function error(string $message): void
    {
        $this->log('error', $message);
        $this->success = false;
    }

    /**
     * Log a warning message.
     *
     * @param string $message Warning message.
     */
    public function warning(string $message): void
    {
        $this->log('warning', $message);
    }

    /**
     * Log an info message.
     *
     * @param string $message Info message.
     */
    public function info(string $message): void
    {
        $this->log('info', $message);
    }

    /**
     * Log a debug message.
     *
     * @param string $message Debug message.
     */
    public function debug(string $message): void
    {
        $this->log('debug', $message);
    }

    /**
     * Get overall progress percentage.
     *
     * @return float Progress percentage (0-100).
     */
    public function get_percentage(): float
    {
        if ($this->total_steps === 0) {
            return 0;
        }
        return round(($this->current_step / $this->total_steps) * 100, 1);
    }

    /**
     * Get current phase progress percentage.
     *
     * @return float Phase progress percentage (0-100).
     */
    public function get_phase_percentage(): float
    {
        if ($this->phase_steps === 0) {
            return 0;
        }
        return round(($this->phase_current / $this->phase_steps) * 100, 1);
    }

    /**
     * Get elapsed time in seconds.
     *
     * @return int Elapsed seconds.
     */
    public function get_elapsed_time(): int
    {
        return time() - $this->start_time;
    }

    /**
     * Get formatted elapsed time.
     *
     * @return string Formatted time (e.g., "5m 30s").
     */
    public function get_elapsed_time_formatted(): string
    {
        $elapsed = $this->get_elapsed_time();

        if ($elapsed < 60) {
            return $elapsed . 's';
        }

        $minutes = floor($elapsed / 60);
        $seconds = $elapsed % 60;

        if ($minutes < 60) {
            return "{$minutes}m {$seconds}s";
        }

        $hours = floor($minutes / 60);
        $minutes = $minutes % 60;
        return "{$hours}h {$minutes}m {$seconds}s";
    }

    /**
     * Get estimated time remaining.
     *
     * @return string Formatted estimated time remaining.
     */
    public function get_estimated_time_remaining(): string
    {
        $percentage = $this->get_percentage();
        if ($percentage <= 0) {
            return 'calculating...';
        }

        $elapsed = $this->get_elapsed_time();
        $totalEstimate = ($elapsed / $percentage) * 100;
        $remaining = (int) ($totalEstimate - $elapsed);

        if ($remaining < 60) {
            return "~{$remaining}s";
        }

        $minutes = floor($remaining / 60);
        return "~{$minutes}m";
    }

    /**
     * Get progress data for web UI (AJAX polling).
     *
     * @return array Progress data.
     */
    public function get_progress(): array
    {
        return [
            'phase' => $this->current_phase,
            'message' => $this->status_message,
            'current_step' => $this->current_step,
            'total_steps' => $this->total_steps,
            'phase_current' => $this->phase_current,
            'phase_steps' => $this->phase_steps,
            'percentage' => $this->get_percentage(),
            'phase_percentage' => $this->get_phase_percentage(),
            'elapsed_time' => $this->get_elapsed_time_formatted(),
            'estimated_remaining' => $this->get_estimated_time_remaining(),
            'completed' => $this->completed,
            'success' => $this->success,
            'download_url' => $this->download_url,
            'log' => array_slice($this->log, -100), // Last 100 entries.
            'phases' => $this->phases,
        ];
    }

    /**
     * Get all log entries.
     *
     * @return array Log entries.
     */
    public function get_log(): array
    {
        return $this->log;
    }

    /**
     * Get errors from log.
     *
     * @return array Error log entries.
     */
    public function get_errors(): array
    {
        return array_filter($this->log, function ($entry) {
            return $entry['level'] === 'error';
        });
    }

    /**
     * Get warnings from log.
     *
     * @return array Warning log entries.
     */
    public function get_warnings(): array
    {
        return array_filter($this->log, function ($entry) {
            return $entry['level'] === 'warning';
        });
    }

    /**
     * Check if export has any errors.
     *
     * @return bool True if errors occurred.
     */
    public function has_errors(): bool
    {
        return !empty($this->get_errors());
    }

    /**
     * Mark export as complete.
     *
     * @param bool $success Whether export was successful.
     * @param string $message Completion message.
     * @param string $download_url Optional download URL.
     */
    public function complete(bool $success, string $message = '', string $download_url = ''): void
    {
        $this->completed = true;
        $this->success = $success;
        $this->download_url = $download_url;

        $defaultMessage = $success
            ? get_string('export_completed', 'local_edulution')
            : get_string('export_failed', 'local_edulution');

        $this->log($success ? 'info' : 'error', $message ?: $defaultMessage);
        $this->persist_progress();

        // CLI summary.
        if ($this->cli_mode && !$this->quiet) {
            echo "\n";
            echo $success ? "Export completed successfully!\n" : "Export failed!\n";
            echo "Duration: " . $this->get_elapsed_time_formatted() . "\n";

            if ($download_url) {
                echo "Download: {$download_url}\n";
            }

            // Show error/warning summary.
            $errors = count($this->get_errors());
            $warnings = count($this->get_warnings());
            if ($errors > 0 || $warnings > 0) {
                echo "Errors: {$errors}, Warnings: {$warnings}\n";
            }
        }
    }

    /**
     * Output progress for CLI.
     */
    protected function output_progress(): void
    {
        if (!$this->cli_mode || $this->quiet) {
            return;
        }

        // Simple progress bar for CLI.
        $percentage = $this->get_percentage();
        $barWidth = 40;
        $filled = (int) round($barWidth * $percentage / 100);
        $empty = $barWidth - $filled;

        $bar = str_repeat('=', $filled) . str_repeat(' ', $empty);
        $status = sprintf(
            "\r[%s] %5.1f%% %s",
            $bar,
            $percentage,
            mb_substr($this->current_phase, 0, 30)
        );

        // Output without newline to allow overwriting.
        echo $status;

        // Flush output buffer.
        if (function_exists('cli_flush')) {
            cli_flush();
        } elseif (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Persist progress to session and/or file.
     */
    protected function persist_progress(): void
    {
        $progress = $this->get_progress();

        // Store in session for web UI.
        if ($this->session_key && isset($_SESSION)) {
            $_SESSION[$this->session_key] = $progress;
        }

        // Store in file for AJAX polling.
        if ($this->progress_file) {
            $json = json_encode($progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($this->progress_file, $json, LOCK_EX);
        }
    }

    /**
     * Load progress from session or file.
     *
     * @param string|null $session_key Session key to check.
     * @param string|null $progress_file Progress file to check.
     * @return array|null Progress data or null if not found.
     */
    public static function load_progress(?string $session_key = null, ?string $progress_file = null): ?array
    {
        // Try session first.
        if ($session_key && isset($_SESSION[$session_key])) {
            return $_SESSION[$session_key];
        }

        // Try file.
        if ($progress_file && file_exists($progress_file)) {
            $content = file_get_contents($progress_file);
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Generate a unique progress file path.
     *
     * @param string|null $export_id Optional export ID.
     * @return string Progress file path.
     */
    public static function generate_progress_file(?string $export_id = null): string
    {
        global $CFG;

        $id = $export_id ?: uniqid('export_', true);
        $dir = $CFG->tempdir . '/edulution_progress';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . '/' . $id . '.json';
    }

    /**
     * Clean up old progress files.
     *
     * @param int $max_age Maximum age in seconds (default: 1 day).
     * @return int Number of files cleaned up.
     */
    public static function cleanup_progress_files(int $max_age = 86400): int
    {
        global $CFG;

        $dir = $CFG->tempdir . '/edulution_progress';
        if (!is_dir($dir)) {
            return 0;
        }

        $threshold = time() - $max_age;
        $deleted = 0;

        $files = glob($dir . '/*.json');
        foreach ($files as $file) {
            if (filemtime($file) < $threshold) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
