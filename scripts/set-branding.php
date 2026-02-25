<?php
/**
 * Set edulution branding (logo) in Moodle.
 *
 * This script sets the edulution logo as the Moodle site logo and
 * configures basic branding settings.
 *
 * Usage: php set-branding.php
 *
 * @copyright 2026 edulution
 * @license   MIT
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/filelib.php');

$logopath = $CFG->dirroot . '/local/edulution/pix/logo.svg';

if (!file_exists($logopath)) {
    echo "[WARN] Logo file not found: {$logopath}\n";
    exit(0);
}

// Check if logo is already set.
$existinglogo = get_config('core_admin', 'logo');
if (!empty($existinglogo)) {
    echo "[INFO] Logo already configured, skipping.\n";
    exit(0);
}

// Store the logo file in Moodle's file storage.
$syscontext = context_system::instance();
$fs = get_file_storage();

// Delete any existing logo files.
$fs->delete_area_files($syscontext->id, 'core_admin', 'logo');
$fs->delete_area_files($syscontext->id, 'core_admin', 'logocompact');

// Store the logo as the site logo.
$filerecord = [
    'contextid' => $syscontext->id,
    'component' => 'core_admin',
    'filearea'  => 'logo',
    'itemid'    => 0,
    'filepath'  => '/',
    'filename'  => 'logo.svg',
];

$file = $fs->create_file_from_pathname($filerecord, $logopath);
if ($file) {
    // Set the config to point to this file.
    set_config('logo', '/' . $file->get_filename(), 'core_admin');
    echo "[SUCCESS] Logo set: logo.svg\n";
} else {
    echo "[WARN] Could not store logo file.\n";
}

// Also set as compact logo.
$filerecord['filearea'] = 'logocompact';
$filecompact = $fs->create_file_from_pathname($filerecord, $logopath);
if ($filecompact) {
    set_config('logocompact', '/' . $filecompact->get_filename(), 'core_admin');
    echo "[SUCCESS] Compact logo set: logo.svg\n";
}

// Purge theme caches so the logo is visible immediately.
theme_reset_all_caches();
echo "[SUCCESS] Theme caches purged.\n";
