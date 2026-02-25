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
 * Download handler for export files.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Require login and capability.
require_login();
require_capability('local/edulution:export', context_system::instance());
require_sesskey();

// Get filename parameter.
$filename = required_param('file', PARAM_FILE);

// Get export directory.
$exportDir = local_edulution_get_export_path();
$filepath = $exportDir . '/' . $filename;

// Validate file exists and is within export directory.
$realpath = realpath($filepath);
$realexportdir = realpath($exportDir);

if ($realpath === false) {
    throw new moodle_exception('error_file_not_found', 'local_edulution');
}

// Security check: ensure the file is within the export directory.
if (strpos($realpath, $realexportdir) !== 0) {
    throw new moodle_exception('error_file_not_found', 'local_edulution');
}

// Validate it's a zip file.
$pathinfo = pathinfo($realpath);
if (strtolower($pathinfo['extension'] ?? '') !== 'zip') {
    throw new moodle_exception('error_invalid_file', 'local_edulution');
}

// Get file info.
$filesize = filesize($realpath);
$filemtime = filemtime($realpath);

// Set headers for download.
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Content-Transfer-Encoding: binary');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Expires: 0');
header('Pragma: public');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $filemtime) . ' GMT');

// Clear output buffer.
if (ob_get_level()) {
    ob_end_clean();
}

// Output file.
readfile($realpath);
exit;
