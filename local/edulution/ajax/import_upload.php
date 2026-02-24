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
 * AJAX handler to upload import file.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

// Require login and capability.
require_login();
require_capability('local/edulution:import', context_system::instance());
require_sesskey();

// Set JSON header.
header('Content-Type: application/json');

try {
    // Check if file was uploaded.
    if (empty($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];
        $errorCode = $_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        throw new Exception($errorMessages[$errorCode] ?? 'Unknown upload error');
    }

    $uploadedFile = $_FILES['import_file'];

    // Validate file type.
    $fileInfo = pathinfo($uploadedFile['name']);
    if (strtolower($fileInfo['extension'] ?? '') !== 'zip') {
        throw new Exception(get_string('error_invalid_file', 'local_edulution'));
    }

    // Get import directory.
    $importDir = local_edulution_get_import_path();
    if (!local_edulution_ensure_directory($importDir)) {
        throw new Exception(get_string('error_directory_not_writable', 'local_edulution', $importDir));
    }

    // Generate unique filename.
    $filename = 'import_' . date('Y-m-d_His') . '_' . substr(md5(uniqid()), 0, 8) . '.zip';
    $destPath = $importDir . '/' . $filename;

    // Move uploaded file.
    if (!move_uploaded_file($uploadedFile['tmp_name'], $destPath)) {
        throw new Exception('Failed to save uploaded file');
    }

    // Validate ZIP file.
    $zip = new ZipArchive();
    if ($zip->open($destPath) !== true) {
        unlink($destPath);
        throw new Exception(get_string('invalid_export_file', 'local_edulution'));
    }

    // Read manifest.
    $manifest = null;
    $manifestContent = $zip->getFromName('manifest.json');
    if ($manifestContent) {
        $manifest = json_decode($manifestContent, true);
    }

    // Get list of files in archive.
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[] = $zip->getNameIndex($i);
    }

    $zip->close();

    // Prepare preview data.
    $preview = [
        'filename' => $filename,
        'filepath' => $destPath,
        'filesize' => filesize($destPath),
        'filesizeformatted' => local_edulution_format_filesize(filesize($destPath)),
        'manifest' => $manifest,
        'files' => $files,
        'hasusers' => in_array('users.json', $files),
        'hascourses' => in_array('courses.json', $files),
        'hascategories' => in_array('categories.json', $files),
        'hasenrollments' => in_array('enrollments.json', $files),
        'hasdatabase' => in_array('database.sql', $files) || in_array('database.sql.gz', $files),
    ];

    // Count items if available.
    if ($preview['hasusers']) {
        $zip->open($destPath);
        $usersContent = $zip->getFromName('users.json');
        if ($usersContent) {
            $users = json_decode($usersContent, true);
            $preview['usercount'] = is_array($users) ? count($users) : 0;
        }
        $zip->close();
    }

    if ($preview['hascourses']) {
        $zip->open($destPath);
        $coursesContent = $zip->getFromName('courses.json');
        if ($coursesContent) {
            $courses = json_decode($coursesContent, true);
            $preview['coursecount'] = is_array($courses) ? count($courses) : 0;
        }
        $zip->close();
    }

    echo json_encode([
        'success' => true,
        'message' => get_string('file_uploaded', 'local_edulution'),
        'preview' => $preview,
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
