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
 * English language strings for local_edulution.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// ============================================================================
// PLUGIN INFO
// ============================================================================
$string['pluginname'] = 'edulution';
$string['plugindescription'] = 'Complete migration, sync and management solution for edulution Moodle';
$string['pluginversion'] = 'Version';
$string['pluginauthor'] = 'Author';
$string['pluginlicense'] = 'License';
$string['pluginsupport'] = 'Support';
$string['plugindocumentation'] = 'Documentation';

// ============================================================================
// CAPABILITIES
// ============================================================================
$string['edulution:manage'] = 'Manage edulution settings';
$string['edulution:export'] = 'Export data from Moodle';
$string['edulution:import'] = 'Import data into Moodle';
$string['edulution:sync'] = 'Synchronize with Keycloak';
$string['edulution:viewreports'] = 'View edulution reports';
$string['edulution:viewdashboard'] = 'View edulution dashboard';
$string['edulution:managekeycloak'] = 'Manage Keycloak integration';
$string['edulution:manageusers'] = 'Manage user synchronization';
$string['edulution:managecourses'] = 'Manage course export/import';
$string['edulution:viewlogs'] = 'View activity logs';
$string['edulution:deletedata'] = 'Delete exported/imported data';
$string['edulution:schedule'] = 'Schedule automated tasks';

// ============================================================================
// NAVIGATION
// ============================================================================
$string['nav_dashboard'] = 'Dashboard';
$string['nav_export'] = 'Export';
$string['nav_import'] = 'Import';
$string['nav_sync'] = 'Keycloak Sync';
$string['nav_keycloak'] = 'Keycloak Settings';
$string['nav_settings'] = 'Settings';
$string['nav_reports'] = 'Reports';
$string['nav_logs'] = 'Activity Logs';
$string['nav_users'] = 'User Management';
$string['nav_courses'] = 'Course Management';
$string['nav_schedule'] = 'Scheduled Tasks';
$string['nav_help'] = 'Help & Support';
$string['nav_back'] = 'Back';
$string['nav_home'] = 'Home';

// Legacy navigation strings
$string['dashboard'] = 'Dashboard';
$string['export'] = 'Export';
$string['import'] = 'Import';
$string['sync'] = 'Keycloak Sync';
$string['keycloak'] = 'Keycloak';
$string['settings'] = 'Settings';
$string['reports'] = 'Reports';

// ============================================================================
// DASHBOARD
// ============================================================================
$string['dashboard_title'] = 'edulution Dashboard';
$string['dashboard_subtitle'] = 'Overview of your edulution integration';
$string['dashboard_welcome'] = 'Welcome to edulution';
$string['dashboard_welcome_message'] = 'Manage your Moodle migration, synchronization, and data management from this central hub.';
$string['dashboard_description'] = 'Manage your Moodle exports, imports, and Keycloak synchronization from one place.';

// Dashboard statistics
$string['dashboard_users_count'] = 'Total Users';
$string['dashboard_courses_count'] = 'Total Courses';
$string['dashboard_categories_count'] = 'Total Categories';
$string['dashboard_enrolments_count'] = 'Total Enrolments';
$string['dashboard_active_users'] = 'Active Users';
$string['dashboard_synced_users'] = 'Synced Users';
$string['dashboard_pending_sync'] = 'Pending Sync';
$string['dashboard_failed_sync'] = 'Failed Syncs';
$string['total_users'] = 'Total Users';
$string['total_courses'] = 'Total Courses';

// Dashboard timestamps
$string['dashboard_last_sync'] = 'Last Sync';
$string['dashboard_last_export'] = 'Last Export';
$string['dashboard_last_import'] = 'Last Import';
$string['dashboard_last_activity'] = 'Last Activity';
$string['dashboard_next_scheduled'] = 'Next Scheduled';
$string['dashboard_never'] = 'Never';
$string['dashboard_ago'] = '{$a} ago';
$string['dashboard_in'] = 'in {$a}';
$string['last_sync'] = 'Last Sync';
$string['export_status'] = 'Export Status';
$string['never'] = 'Never';
$string['no_exports'] = 'No exports';

// Dashboard cards
$string['dashboard_card_export'] = 'Export Data';
$string['dashboard_card_export_desc'] = 'Export users, courses, and enrolments to external systems';
$string['dashboard_card_import'] = 'Import Data';
$string['dashboard_card_import_desc'] = 'Import data from external sources into Moodle';
$string['dashboard_card_sync'] = 'Keycloak Sync';
$string['dashboard_card_sync_desc'] = 'Synchronize users with Keycloak identity provider';
$string['dashboard_card_settings'] = 'Settings';
$string['dashboard_card_settings_desc'] = 'Configure edulution plugin settings';
$string['dashboard_card_reports'] = 'Reports';
$string['dashboard_card_reports_desc'] = 'View detailed reports and analytics';
$string['dashboard_card_logs'] = 'Activity Logs';
$string['dashboard_card_logs_desc'] = 'Review recent activities and operations';

// Dashboard status
$string['dashboard_status_healthy'] = 'Healthy';
$string['dashboard_status_warning'] = 'Warning';
$string['dashboard_status_error'] = 'Error';
$string['dashboard_status_unknown'] = 'Unknown';
$string['dashboard_status_connected'] = 'Connected';
$string['dashboard_status_disconnected'] = 'Disconnected';
$string['dashboard_status_syncing'] = 'Syncing...';
$string['dashboard_status_idle'] = 'Idle';

// Dashboard actions
$string['dashboard_action_refresh'] = 'Refresh';
$string['dashboard_action_viewall'] = 'View All';
$string['dashboard_action_configure'] = 'Configure';
$string['dashboard_action_start'] = 'Start';
$string['dashboard_action_stop'] = 'Stop';
$string['dashboard_action_details'] = 'Details';

// Dashboard quick actions
$string['dashboard_quick_export'] = 'Quick Export';
$string['dashboard_quick_sync'] = 'Quick Sync';
$string['dashboard_quick_import'] = 'Quick Import';
$string['dashboard_quick_report'] = 'Generate Report';
$string['quick_actions'] = 'Quick Actions';
$string['start_sync'] = 'Start Sync';
$string['new_export'] = 'New Export';
$string['view_reports'] = 'View Reports';

// Dashboard alerts
$string['dashboard_alert_sync_required'] = 'User synchronization is required';
$string['dashboard_alert_export_pending'] = 'Export tasks are pending';
$string['dashboard_alert_import_failed'] = 'Recent import failed';
$string['dashboard_alert_keycloak_disconnected'] = 'Keycloak connection lost';
$string['dashboard_alert_update_available'] = 'Plugin update available';

// Dashboard misc
$string['recent_activity'] = 'Recent Activity';
$string['no_recent_activity'] = 'No recent activity';
$string['system_status'] = 'System Status';
$string['keycloak_connection'] = 'Keycloak Connection';
$string['disk_space'] = 'Disk Space';
$string['connected'] = 'Connected';
$string['disconnected'] = 'Disconnected';
$string['not_configured'] = 'Not Configured';
$string['available'] = 'Available';
$string['used'] = 'Used';
$string['free'] = 'Free';

// ============================================================================
// EXPORT
// ============================================================================
$string['export_title'] = 'Export Data';
$string['export_subtitle'] = 'Export Moodle data to external systems';
$string['export_description'] = 'Export your Moodle data for backup or migration.';

// Export types
$string['export_type'] = 'Export Type';
$string['export_type_users'] = 'Users';
$string['export_type_courses'] = 'Courses';
$string['export_type_categories'] = 'Categories';
$string['export_type_enrolments'] = 'Enrolments';
$string['export_type_grades'] = 'Grades';
$string['export_type_completions'] = 'Completions';
$string['export_type_groups'] = 'Groups';
$string['export_type_cohorts'] = 'Cohorts';
$string['export_type_roles'] = 'Roles';
$string['export_type_all'] = 'All Data';
$string['export_type_custom'] = 'Custom Selection';

// Export form labels
$string['export_format'] = 'Export Format';
$string['export_format_json'] = 'JSON';
$string['export_format_csv'] = 'CSV';
$string['export_format_xml'] = 'XML';
$string['export_format_sql'] = 'SQL';
$string['export_destination'] = 'Destination';
$string['export_destination_file'] = 'Download File';
$string['export_destination_server'] = 'Server Storage';
$string['export_destination_api'] = 'External API';
$string['export_filename'] = 'File Name';
$string['export_filename_help'] = 'Enter the file name without extension';
$string['export_include_header'] = 'Include Header Row';
$string['export_include_timestamps'] = 'Include Timestamps';
$string['export_include_ids'] = 'Include Internal IDs';
$string['export_compress'] = 'Compress Output';
$string['export_compress_help'] = 'Create a ZIP archive of the exported files';

// Export options (legacy)
$string['export_options'] = 'Export Options';
$string['full_database_export'] = 'Full Database Export';
$string['full_database_export_help'] = 'Export the complete database. Recommended for full migration.';
$string['tables_to_exclude'] = 'Tables to Exclude';
$string['tables_to_exclude_help'] = 'Comma-separated list of table names to exclude (without prefix).';
$string['include_moodledata'] = 'Include Moodledata';
$string['include_moodledata_help'] = 'Include the moodledata directory in the export.';
$string['include_course_backups'] = 'Include Course Backups';
$string['include_course_backups_help'] = 'Create .mbz backup files for each course.';
$string['compression_level'] = 'Compression Level';
$string['compression_level_help'] = 'Higher compression results in smaller files but takes longer.';
$string['compression_none'] = 'None (fastest)';
$string['compression_normal'] = 'Normal';
$string['compression_maximum'] = 'Maximum (smallest)';
$string['selective_export'] = 'Selective Export';
$string['select_categories'] = 'Select Categories';
$string['select_categories_help'] = 'Select specific categories to export. Leave empty for all.';
$string['select_courses'] = 'Select Courses';
$string['select_courses_help'] = 'Select specific courses to export. Leave empty for all.';

// Export filters
$string['export_filter_title'] = 'Filter Options';
$string['export_filter_daterange'] = 'Date Range';
$string['export_filter_datefrom'] = 'From Date';
$string['export_filter_dateto'] = 'To Date';
$string['export_filter_category'] = 'Category';
$string['export_filter_course'] = 'Course';
$string['export_filter_role'] = 'Role';
$string['export_filter_status'] = 'Status';
$string['export_filter_active'] = 'Active Only';
$string['export_filter_suspended'] = 'Include Suspended';
$string['export_filter_deleted'] = 'Include Deleted';

// Export options advanced
$string['export_options_title'] = 'Export Options';
$string['export_option_incremental'] = 'Incremental Export';
$string['export_option_incremental_help'] = 'Only export records changed since last export';
$string['export_option_fullexport'] = 'Full Export';
$string['export_option_anonymize'] = 'Anonymize Personal Data';
$string['export_option_anonymize_help'] = 'Replace personal data with anonymous placeholders';
$string['export_option_encrypt'] = 'Encrypt Output';
$string['export_option_encrypt_help'] = 'Encrypt the exported data using AES-256';
$string['export_option_validate'] = 'Validate Before Export';
$string['export_option_notify'] = 'Send Notification on Complete';

// Export progress
$string['export_progress_title'] = 'Export Progress';
$string['export_progress_preparing'] = 'Preparing export...';
$string['export_progress_processing'] = 'Processing records...';
$string['export_progress_processed'] = 'Processed {$a->current} of {$a->total} records';
$string['export_progress_writing'] = 'Writing output file...';
$string['export_progress_compressing'] = 'Compressing files...';
$string['export_progress_uploading'] = 'Uploading to destination...';
$string['export_progress_finalizing'] = 'Finalizing export...';
$string['export_progress_complete'] = 'Export complete!';
$string['export_progress_failed'] = 'Export failed';
$string['export_progress_cancelled'] = 'Export cancelled';
$string['export_progress_percent'] = '{$a}% complete';
$string['export_progress'] = 'Export Progress';
$string['start_export'] = 'Start Export';
$string['export_complete'] = 'Export Complete';
$string['export_failed'] = 'Export Failed';
$string['download_export'] = 'Download Export';
$string['export_running'] = 'Export in progress...';
$string['export_cancelled'] = 'Export cancelled';

// Export results
$string['export_result_title'] = 'Export Results';
$string['export_result_success'] = 'Export completed successfully';
$string['export_result_partial'] = 'Export completed with warnings';
$string['export_result_failed'] = 'Export failed';
$string['export_result_records'] = '{$a} records exported';
$string['export_result_filesize'] = 'File size: {$a}';
$string['export_result_duration'] = 'Duration: {$a}';
$string['export_result_download'] = 'Download Export';
$string['export_result_viewlog'] = 'View Export Log';

// Export errors
$string['export_error_nodata'] = 'No data to export';
$string['export_error_permission'] = 'You do not have permission to export this data';
$string['export_error_writefailed'] = 'Failed to write export file';
$string['export_error_invalidformat'] = 'Invalid export format selected';
$string['export_error_invaliddestination'] = 'Invalid export destination';
$string['export_error_timeout'] = 'Export operation timed out';
$string['export_error_memory'] = 'Insufficient memory to complete export';
$string['export_error_connection'] = 'Failed to connect to destination';
$string['export_error_validation'] = 'Data validation failed';
$string['export_error_unknown'] = 'An unknown error occurred during export';

// Export actions
$string['export_action_start'] = 'Start Export';
$string['export_action_cancel'] = 'Cancel Export';
$string['export_action_pause'] = 'Pause Export';
$string['export_action_resume'] = 'Resume Export';
$string['export_action_retry'] = 'Retry Export';
$string['export_action_schedule'] = 'Schedule Export';
$string['export_action_preview'] = 'Preview Data';
$string['export_action_configure'] = 'Configure Export';

// Export scheduling
$string['export_schedule_title'] = 'Schedule Export';
$string['export_schedule_frequency'] = 'Frequency';
$string['export_schedule_daily'] = 'Daily';
$string['export_schedule_weekly'] = 'Weekly';
$string['export_schedule_monthly'] = 'Monthly';
$string['export_schedule_custom'] = 'Custom';
$string['export_schedule_time'] = 'Time';
$string['export_schedule_dayofweek'] = 'Day of Week';
$string['export_schedule_dayofmonth'] = 'Day of Month';
$string['export_schedule_enabled'] = 'Enable Scheduled Export';
$string['export_schedule_next'] = 'Next scheduled run: {$a}';

// Settings page strings (for admin settings.php).
$string['exportsettings'] = 'Export Settings';
$string['exportdir'] = 'Export Directory';
$string['exportdir_desc'] = 'Directory where export files will be stored.';
$string['exportretention'] = 'Export Retention (days)';
$string['exportretention_desc'] = 'Number of days to keep export files before cleanup.';
$string['keycloakurl'] = 'Keycloak URL';
$string['keycloakurl_desc'] = 'The base URL of your Keycloak server.';
$string['keycloakrealm'] = 'Keycloak Realm';
$string['keycloakrealm_desc'] = 'The Keycloak realm to use.';
$string['keycloakclientid'] = 'Keycloak Client ID';
$string['keycloakclientid_desc'] = 'The OAuth2 client ID configured in Keycloak.';
$string['keycloakclientsecret'] = 'Keycloak Client Secret';
$string['keycloakclientsecret_desc'] = 'The client secret for the OAuth2 client.';
$string['keycloaksyncenabled'] = 'Enable Keycloak Sync';
$string['keycloaksyncenabled_desc'] = 'Enable automatic user synchronization with Keycloak.';

// ============================================================================
// IMPORT
// ============================================================================
$string['import_title'] = 'Import Data';
$string['import_subtitle'] = 'Import data from external sources into Moodle';
$string['import_description'] = 'Import data from an edulution export package.';

// Import types
$string['import_type'] = 'Import Type';
$string['import_type_users'] = 'Users';
$string['import_type_courses'] = 'Courses';
$string['import_type_categories'] = 'Categories';
$string['import_type_enrolments'] = 'Enrolments';
$string['import_type_grades'] = 'Grades';
$string['import_type_groups'] = 'Groups';
$string['import_type_cohorts'] = 'Cohorts';
$string['import_type_custom'] = 'Custom Import';

// Import source
$string['import_source'] = 'Import Source';
$string['import_source_file'] = 'Upload File';
$string['import_source_url'] = 'External URL';
$string['import_source_api'] = 'API Endpoint';
$string['import_source_server'] = 'Server File';
$string['import_file'] = 'Select File';
$string['import_file_help'] = 'Supported formats: CSV, JSON, XML';
$string['import_url'] = 'Source URL';
$string['import_url_help'] = 'Enter the URL of the data source';

// Import form labels
$string['import_format'] = 'File Format';
$string['import_format_auto'] = 'Auto-detect';
$string['import_encoding'] = 'File Encoding';
$string['import_encoding_utf8'] = 'UTF-8';
$string['import_encoding_latin1'] = 'ISO-8859-1';
$string['import_encoding_auto'] = 'Auto-detect';
$string['import_delimiter'] = 'CSV Delimiter';
$string['import_delimiter_comma'] = 'Comma (,)';
$string['import_delimiter_semicolon'] = 'Semicolon (;)';
$string['import_delimiter_tab'] = 'Tab';
$string['import_hasheader'] = 'First Row is Header';
$string['import_skiprows'] = 'Skip Rows';
$string['import_skiprows_help'] = 'Number of rows to skip at the beginning';

// Import options (legacy)
$string['upload_export'] = 'Upload Export File';
$string['upload_export_help'] = 'Upload a ZIP file created by edulution export.';
$string['preview_contents'] = 'Preview Contents';
$string['import_options'] = 'Import Options';
$string['import_users'] = 'Import Users';
$string['import_courses'] = 'Import Courses';
$string['import_categories'] = 'Import Categories';
$string['import_enrollments'] = 'Import Enrollments';
$string['start_import'] = 'Start Import';
$string['import_progress'] = 'Import Progress';
$string['import_complete'] = 'Import Complete';
$string['import_failed'] = 'Import Failed';
$string['import_cli_note'] = 'Note: Full database import must be performed via CLI for safety.';
$string['no_file_uploaded'] = 'No file uploaded';
$string['invalid_export_file'] = 'Invalid export file';
$string['file_uploaded'] = 'File uploaded successfully';

// Import mapping
$string['import_mapping_title'] = 'Field Mapping';
$string['import_mapping_description'] = 'Map source fields to Moodle fields';
$string['import_mapping_source'] = 'Source Field';
$string['import_mapping_target'] = 'Moodle Field';
$string['import_mapping_default'] = 'Default Value';
$string['import_mapping_required'] = 'Required';
$string['import_mapping_skip'] = 'Skip this field';
$string['import_mapping_automap'] = 'Auto-map Fields';
$string['import_mapping_clear'] = 'Clear Mapping';
$string['import_mapping_save'] = 'Save Mapping';
$string['import_mapping_load'] = 'Load Saved Mapping';

// Import options advanced
$string['import_options_title'] = 'Import Options';
$string['import_option_update'] = 'Update Existing Records';
$string['import_option_update_help'] = 'Update records if they already exist';
$string['import_option_create'] = 'Create New Records';
$string['import_option_skip_existing'] = 'Skip Existing Records';
$string['import_option_delete_missing'] = 'Delete Missing Records';
$string['import_option_validate'] = 'Validate Before Import';
$string['import_option_simulate'] = 'Simulation Mode (Dry Run)';
$string['import_option_simulate_help'] = 'Preview import without making changes';
$string['import_option_notify'] = 'Send Notification on Complete';
$string['import_option_sendwelcome'] = 'Send Welcome Email to New Users';

// Import progress
$string['import_progress_title'] = 'Import Progress';
$string['import_progress_uploading'] = 'Uploading file...';
$string['import_progress_parsing'] = 'Parsing data...';
$string['import_progress_validating'] = 'Validating records...';
$string['import_progress_processing'] = 'Processing records...';
$string['import_progress_processed'] = 'Processed {$a->current} of {$a->total} records';
$string['import_progress_finalizing'] = 'Finalizing import...';
$string['import_progress_complete'] = 'Import complete!';
$string['import_progress_failed'] = 'Import failed';
$string['import_progress_cancelled'] = 'Import cancelled';
$string['import_progress_percent'] = '{$a}% complete';

// Import results
$string['import_result_title'] = 'Import Results';
$string['import_result_success'] = 'Import completed successfully';
$string['import_result_partial'] = 'Import completed with errors';
$string['import_result_failed'] = 'Import failed';
$string['import_result_created'] = '{$a} records created';
$string['import_result_updated'] = '{$a} records updated';
$string['import_result_skipped'] = '{$a} records skipped';
$string['import_result_failed_records'] = '{$a} records failed';
$string['import_result_duration'] = 'Duration: {$a}';
$string['import_result_viewlog'] = 'View Import Log';
$string['import_result_download_errors'] = 'Download Error Report';

// Import errors
$string['import_error_nofile'] = 'No file uploaded';
$string['import_error_invalidfile'] = 'Invalid file format';
$string['import_error_emptyfile'] = 'File is empty';
$string['import_error_toolarge'] = 'File is too large';
$string['import_error_permission'] = 'You do not have permission to import data';
$string['import_error_parsing'] = 'Failed to parse file';
$string['import_error_mapping'] = 'Field mapping is incomplete';
$string['import_error_validation'] = 'Data validation failed';
$string['import_error_duplicate'] = 'Duplicate record found';
$string['import_error_required'] = 'Required field is missing';
$string['import_error_invalid_value'] = 'Invalid value for field {$a}';
$string['import_error_connection'] = 'Failed to connect to source';
$string['import_error_timeout'] = 'Import operation timed out';
$string['import_error_unknown'] = 'An unknown error occurred during import';

// Import actions
$string['import_action_start'] = 'Start Import';
$string['import_action_cancel'] = 'Cancel Import';
$string['import_action_pause'] = 'Pause Import';
$string['import_action_resume'] = 'Resume Import';
$string['import_action_retry'] = 'Retry Import';
$string['import_action_preview'] = 'Preview Data';
$string['import_action_validate'] = 'Validate Data';
$string['import_action_configure'] = 'Configure Import';

// Import preview
$string['import_preview_title'] = 'Import Preview';
$string['import_preview_description'] = 'Review the data before importing';
$string['import_preview_records'] = 'Showing {$a->shown} of {$a->total} records';
$string['import_preview_valid'] = 'Valid';
$string['import_preview_invalid'] = 'Invalid';
$string['import_preview_warning'] = 'Warning';

// ============================================================================
// KEYCLOAK SYNC
// ============================================================================
$string['sync_title'] = 'Keycloak Synchronization';
$string['sync_subtitle'] = 'Synchronize users between Moodle and Keycloak';
$string['sync_description'] = 'Synchronize users between Keycloak and Moodle.';

// Sync status
$string['sync_status_title'] = 'Sync Status';
$string['sync_status_connected'] = 'Connected to Keycloak';
$string['sync_status_disconnected'] = 'Disconnected from Keycloak';
$string['sync_status_syncing'] = 'Synchronization in progress';
$string['sync_status_idle'] = 'Idle';
$string['sync_status_error'] = 'Synchronization error';
$string['sync_status_lastrun'] = 'Last sync: {$a}';
$string['sync_status_nextrun'] = 'Next sync: {$a}';
$string['sync_status_never'] = 'Never synchronized';
$string['connection_status'] = 'Connection Status';

// Sync statistics
$string['sync_stats_title'] = 'Sync Statistics';
$string['sync_stats_total_users'] = 'Total Keycloak Users';
$string['sync_stats_synced_users'] = 'Synced Users';
$string['sync_stats_pending_users'] = 'Pending Users';
$string['sync_stats_failed_users'] = 'Failed Users';
$string['sync_stats_new_users'] = 'New Users';
$string['sync_stats_updated_users'] = 'Updated Users';
$string['sync_stats_disabled_users'] = 'Disabled Users';
$string['sync_stats_deleted_users'] = 'Deleted Users';

// Sync preview
$string['sync_preview_title'] = 'Sync Preview';
$string['sync_preview_description'] = 'Preview changes before synchronizing';
$string['sync_preview_create'] = 'Users to Create';
$string['sync_preview_update'] = 'Users to Update';
$string['sync_preview_disable'] = 'Users to Disable';
$string['sync_preview_delete'] = 'Users to Delete';
$string['sync_preview_nochanges'] = 'No changes detected';
$string['sync_preview_changes'] = '{$a} changes detected';
$string['sync_preview_refresh'] = 'Refresh Preview';

// Sync options (legacy)
$string['preview_mode'] = 'Preview Mode';
$string['preview_mode_help'] = 'Show what would happen without making changes.';
$string['sync_options'] = 'Sync Options';
$string['sync_new_users'] = 'Sync New Users';
$string['sync_existing_users'] = 'Update Existing Users';
$string['sync_deletions'] = 'Process Deletions';
$string['start_sync_button'] = 'Start Sync';
$string['preview_sync'] = 'Preview Sync';
$string['users_to_create'] = 'Users to Create';
$string['users_to_update'] = 'Users to Update';
$string['users_to_delete'] = 'Users to Delete';
$string['sync_progress'] = 'Sync Progress';
$string['sync_complete'] = 'Sync Complete';
$string['sync_failed'] = 'Sync Failed';
$string['sync_results'] = 'Sync Results';
$string['users_created'] = 'Users Created';
$string['users_updated'] = 'Users Updated';
$string['users_deleted'] = 'Users Deleted';
$string['users_skipped'] = 'Users Skipped';
$string['errors_occurred'] = 'Errors Occurred';
$string['no_changes_required'] = 'No changes required';
$string['last_sync_time'] = 'Last sync: {$a}';
$string['sync_not_configured'] = 'Keycloak sync is not configured. Please configure Keycloak settings first.';

// Sync options advanced
$string['sync_options_title'] = 'Sync Options';
$string['sync_option_direction'] = 'Sync Direction';
$string['sync_option_keycloak_to_moodle'] = 'Keycloak to Moodle';
$string['sync_option_moodle_to_keycloak'] = 'Moodle to Keycloak';
$string['sync_option_bidirectional'] = 'Bidirectional';
$string['sync_option_create_users'] = 'Create New Users';
$string['sync_option_update_users'] = 'Update Existing Users';
$string['sync_option_disable_missing'] = 'Disable Missing Users';
$string['sync_option_delete_missing'] = 'Delete Missing Users';
$string['sync_option_sync_roles'] = 'Synchronize Roles';
$string['sync_option_sync_groups'] = 'Synchronize Groups';
$string['sync_option_sync_attributes'] = 'Synchronize Custom Attributes';

// Sync field mapping
$string['sync_mapping_title'] = 'Field Mapping';
$string['sync_mapping_keycloak'] = 'Keycloak Field';
$string['sync_mapping_moodle'] = 'Moodle Field';
$string['sync_mapping_direction'] = 'Direction';
$string['sync_mapping_transform'] = 'Transform';
$string['sync_mapping_add'] = 'Add Mapping';
$string['sync_mapping_remove'] = 'Remove Mapping';

// Sync progress
$string['sync_progress_title'] = 'Sync Progress';
$string['sync_progress_connecting'] = 'Connecting to Keycloak...';
$string['sync_progress_fetching'] = 'Fetching users from Keycloak...';
$string['sync_progress_comparing'] = 'Comparing user data...';
$string['sync_progress_creating'] = 'Creating new users...';
$string['sync_progress_updating'] = 'Updating existing users...';
$string['sync_progress_disabling'] = 'Disabling missing users...';
$string['sync_progress_deleting'] = 'Deleting users...';
$string['sync_progress_syncing_roles'] = 'Synchronizing roles...';
$string['sync_progress_syncing_groups'] = 'Synchronizing groups...';
$string['sync_progress_finalizing'] = 'Finalizing synchronization...';
$string['sync_progress_complete'] = 'Synchronization complete!';
$string['sync_progress_processed'] = 'Processed {$a->current} of {$a->total} users';
$string['sync_progress_percent'] = '{$a}% complete';

// Sync results
$string['sync_result_title'] = 'Sync Results';
$string['sync_result_success'] = 'Synchronization completed successfully';
$string['sync_result_partial'] = 'Synchronization completed with errors';
$string['sync_result_failed'] = 'Synchronization failed';
$string['sync_result_created'] = '{$a} users created';
$string['sync_result_updated'] = '{$a} users updated';
$string['sync_result_disabled'] = '{$a} users disabled';
$string['sync_result_deleted'] = '{$a} users deleted';
$string['sync_result_skipped'] = '{$a} users skipped';
$string['sync_result_errors'] = '{$a} errors occurred';
$string['sync_result_duration'] = 'Duration: {$a}';
$string['sync_result_viewlog'] = 'View Sync Log';

// Sync errors
$string['sync_error_connection'] = 'Failed to connect to Keycloak';
$string['sync_error_authentication'] = 'Keycloak authentication failed';
$string['sync_error_permission'] = 'Insufficient permissions in Keycloak';
$string['sync_error_timeout'] = 'Keycloak request timed out';
$string['sync_error_api'] = 'Keycloak API error: {$a}';
$string['sync_error_user_create'] = 'Failed to create user: {$a}';
$string['sync_error_user_update'] = 'Failed to update user: {$a}';
$string['sync_error_user_delete'] = 'Failed to delete user: {$a}';
$string['sync_error_duplicate_email'] = 'Duplicate email address: {$a}';
$string['sync_error_invalid_data'] = 'Invalid user data';
$string['sync_error_unknown'] = 'An unknown error occurred during synchronization';

// Sync actions
$string['sync_action_start'] = 'Start Sync';
$string['sync_action_stop'] = 'Stop Sync';
$string['sync_action_preview'] = 'Preview Changes';
$string['sync_action_fullsync'] = 'Full Sync';
$string['sync_action_incrementalsync'] = 'Incremental Sync';
$string['sync_action_schedule'] = 'Schedule Sync';
$string['sync_action_configure'] = 'Configure Sync';
$string['sync_action_test'] = 'Test Connection';

// Sync scheduling
$string['sync_schedule_title'] = 'Scheduled Sync';
$string['sync_schedule_enabled'] = 'Enable Scheduled Sync';
$string['sync_schedule_frequency'] = 'Frequency';
$string['sync_schedule_hourly'] = 'Hourly';
$string['sync_schedule_daily'] = 'Daily';
$string['sync_schedule_weekly'] = 'Weekly';
$string['sync_schedule_custom'] = 'Custom (Cron)';
$string['sync_schedule_cron'] = 'Cron Expression';
$string['sync_schedule_next'] = 'Next scheduled run: {$a}';

// ============================================================================
// KEYCLOAK SETTINGS / SETUP WIZARD
// ============================================================================
$string['keycloak_title'] = 'Keycloak Configuration';
$string['keycloak_subtitle'] = 'Configure your Keycloak integration';
$string['keycloak_description'] = 'Configure the connection to your Keycloak server.';

// Setup wizard
$string['keycloak_wizard_title'] = 'Keycloak Setup Wizard';
$string['keycloak_wizard_intro'] = 'This wizard will guide you through the Keycloak integration setup.';
$string['keycloak_wizard_step1'] = 'Connection Settings';
$string['keycloak_wizard_step2'] = 'Authentication';
$string['keycloak_wizard_step3'] = 'User Mapping';
$string['keycloak_wizard_step4'] = 'Role Mapping';
$string['keycloak_wizard_step5'] = 'Test Connection';
$string['keycloak_wizard_step6'] = 'Complete';
$string['keycloak_wizard_next'] = 'Next';
$string['keycloak_wizard_previous'] = 'Previous';
$string['keycloak_wizard_finish'] = 'Finish Setup';
$string['keycloak_wizard_skip'] = 'Skip Wizard';
$string['keycloak_setup_wizard'] = 'Keycloak Setup Wizard';
$string['step'] = 'Step {$a}';
$string['step_server'] = 'Server Settings';
$string['step_client'] = 'Client Settings';
$string['step_sync'] = 'Sync Settings';
$string['step_test'] = 'Test Connection';

// Connection settings
$string['keycloak_server_url'] = 'Keycloak Server URL';
$string['keycloak_server_url_help'] = 'The base URL of your Keycloak server (e.g., https://keycloak.example.com)';
$string['keycloak_realm'] = 'Realm';
$string['keycloak_realm_help'] = 'The Keycloak realm to use for authentication';
$string['keycloak_client_id'] = 'Client ID';
$string['keycloak_client_id_help'] = 'The client ID registered in Keycloak';
$string['keycloak_client_secret'] = 'Client Secret';
$string['keycloak_client_secret_help'] = 'The client secret for authentication';
$string['keycloak_admin_username'] = 'Admin Username';
$string['keycloak_admin_username_help'] = 'Admin account for Keycloak API access';
$string['keycloak_admin_password'] = 'Admin Password';
$string['keycloak_admin_password_help'] = 'Password for the admin account';
$string['keycloak_ssl_verify'] = 'Verify SSL Certificate';
$string['keycloak_ssl_verify_help'] = 'Verify the SSL certificate of the Keycloak server';
$string['keycloak_timeout'] = 'Connection Timeout';
$string['keycloak_timeout_help'] = 'Connection timeout in seconds';

// Connection strings (legacy)
$string['test_connection'] = 'Test Connection';
$string['connection_successful'] = 'Connection successful!';
$string['connection_failed'] = 'Connection failed: {$a}';
$string['save_configuration'] = 'Save Configuration';
$string['configuration_saved'] = 'Configuration saved successfully';
$string['previous_step'] = 'Previous';
$string['next_step'] = 'Next';
$string['finish_setup'] = 'Finish Setup';

// Authentication settings
$string['keycloak_auth_method'] = 'Authentication Method';
$string['keycloak_auth_client_credentials'] = 'Client Credentials';
$string['keycloak_auth_password'] = 'Resource Owner Password';
$string['keycloak_auth_token'] = 'Service Account Token';
$string['keycloak_scope'] = 'OAuth Scopes';
$string['keycloak_scope_help'] = 'OAuth scopes to request (comma-separated)';

// User mapping
$string['keycloak_user_mapping_title'] = 'User Attribute Mapping';
$string['keycloak_map_username'] = 'Username Field';
$string['keycloak_map_email'] = 'Email Field';
$string['keycloak_map_firstname'] = 'First Name Field';
$string['keycloak_map_lastname'] = 'Last Name Field';
$string['keycloak_map_idnumber'] = 'ID Number Field';
$string['keycloak_map_department'] = 'Department Field';
$string['keycloak_map_institution'] = 'Institution Field';
$string['keycloak_map_phone'] = 'Phone Field';
$string['keycloak_map_address'] = 'Address Field';
$string['keycloak_map_city'] = 'City Field';
$string['keycloak_map_country'] = 'Country Field';
$string['keycloak_custom_attributes'] = 'Custom Attributes';
$string['keycloak_custom_attributes_help'] = 'Map additional Keycloak attributes to Moodle profile fields';

// Role mapping
$string['keycloak_role_mapping_title'] = 'Role Mapping';
$string['keycloak_role_mapping_description'] = 'Map Keycloak roles to Moodle roles';
$string['keycloak_role_source'] = 'Keycloak Role';
$string['keycloak_role_target'] = 'Moodle Role';
$string['keycloak_role_context'] = 'Context';
$string['keycloak_role_add'] = 'Add Role Mapping';
$string['keycloak_role_remove'] = 'Remove Mapping';
$string['keycloak_sync_realm_roles'] = 'Sync Realm Roles';
$string['keycloak_sync_client_roles'] = 'Sync Client Roles';
$string['keycloak_default_role'] = 'Default Moodle Role';
$string['keycloak_default_role_help'] = 'Default role for users without specific role mapping';

// Group mapping
$string['keycloak_group_mapping_title'] = 'Group Mapping';
$string['keycloak_group_mapping_description'] = 'Map Keycloak groups to Moodle cohorts';
$string['keycloak_sync_groups'] = 'Synchronize Groups';
$string['keycloak_group_source'] = 'Keycloak Group';
$string['keycloak_group_target'] = 'Moodle Cohort';
$string['keycloak_group_prefix'] = 'Group Prefix Filter';
$string['keycloak_auto_create_cohorts'] = 'Auto-create Cohorts';
$string['keycloak_auto_create_cohorts_help'] = 'Automatically create cohorts for new Keycloak groups';

// Test connection
$string['keycloak_test_title'] = 'Test Connection';
$string['keycloak_test_description'] = 'Test the connection to your Keycloak server';
$string['keycloak_test_button'] = 'Test Connection';
$string['keycloak_test_success'] = 'Connection successful!';
$string['keycloak_test_failed'] = 'Connection failed: {$a}';
$string['keycloak_test_users_found'] = '{$a} users found in Keycloak';
$string['keycloak_test_roles_found'] = '{$a} roles found in Keycloak';
$string['keycloak_test_groups_found'] = '{$a} groups found in Keycloak';

// Setup complete
$string['keycloak_setup_complete'] = 'Keycloak Setup Complete';
$string['keycloak_setup_success'] = 'Your Keycloak integration is now configured.';
$string['keycloak_setup_next_steps'] = 'Next Steps';
$string['keycloak_setup_run_sync'] = 'Run Initial Sync';
$string['keycloak_setup_configure_schedule'] = 'Configure Scheduled Sync';
$string['keycloak_setup_view_users'] = 'View Synced Users';

// ============================================================================
// SETTINGS
// ============================================================================
$string['settings_title'] = 'edulution Settings';
$string['settings_subtitle'] = 'Configure plugin settings';
$string['settings_saved'] = 'Settings saved successfully';
$string['settings_error'] = 'Error saving settings';
$string['settings_description'] = 'Configure edulution plugin settings.';

// General settings
$string['settings_general'] = 'General Settings';
$string['settings_enabled'] = 'Enable Plugin';
$string['settings_enabled_help'] = 'Enable or disable the edulution plugin';
$string['settings_debug'] = 'Debug Mode';
$string['settings_debug_help'] = 'Enable verbose logging for troubleshooting';
$string['settings_log_level'] = 'Log Level';
$string['settings_log_level_help'] = 'Set the minimum log level';
$string['settings_log_level_error'] = 'Error';
$string['settings_log_level_warning'] = 'Warning';
$string['settings_log_level_info'] = 'Info';
$string['settings_log_level_debug'] = 'Debug';
$string['settings_log_retention'] = 'Log Retention Days';
$string['settings_log_retention_help'] = 'Number of days to keep log entries';

// Settings legacy
$string['general_settings'] = 'General Settings';
$string['enable_plugin'] = 'Enable Plugin';
$string['enable_plugin_help'] = 'Enable or disable the edulution plugin functionality.';
$string['export_settings'] = 'Export Settings';
$string['export_directory'] = 'Export Directory';
$string['export_directory_help'] = 'Directory where export files will be stored.';
$string['export_retention'] = 'Export Retention (days)';
$string['export_retention_help'] = 'Number of days to keep export files before cleanup.';
$string['sync_settings'] = 'Sync Settings';
$string['enable_keycloak_sync'] = 'Enable Keycloak Sync';
$string['enable_keycloak_sync_help'] = 'Enable automatic synchronization with Keycloak.';
$string['sync_patterns'] = 'Sync Patterns';
$string['sync_patterns_help'] = 'Regular expressions to filter which users to sync.';
$string['user_pattern'] = 'User Pattern';
$string['user_pattern_help'] = 'Regex pattern to match usernames (e.g., ^student_.*)';
$string['email_pattern'] = 'Email Pattern';
$string['email_pattern_help'] = 'Regex pattern to match email addresses';
$string['category_mappings'] = 'Category Mappings';
$string['category_mappings_help'] = 'Map Keycloak groups to Moodle course categories.';
$string['keycloak_group'] = 'Keycloak Group';
$string['moodle_category'] = 'Moodle Category';
$string['add_mapping'] = 'Add Mapping';
$string['remove_mapping'] = 'Remove';
$string['blacklist_settings'] = 'Blacklist Settings';
$string['user_blacklist'] = 'User Blacklist';
$string['user_blacklist_help'] = 'List of usernames to exclude from sync (one per line).';
$string['email_blacklist'] = 'Email Blacklist';
$string['email_blacklist_help'] = 'List of email addresses/domains to exclude (one per line).';
$string['save_settings'] = 'Save Settings';

// Export settings
$string['settings_export'] = 'Export Settings';
$string['settings_export_enabled'] = 'Enable Export';
$string['settings_export_path'] = 'Export Path';
$string['settings_export_path_help'] = 'Directory path for storing export files';
$string['settings_export_format'] = 'Default Export Format';
$string['settings_export_compress'] = 'Compress Exports';
$string['settings_export_max_records'] = 'Max Records per Export';
$string['settings_export_max_records_help'] = 'Maximum number of records per export file';
$string['settings_export_cleanup'] = 'Auto-cleanup Exports';
$string['settings_export_cleanup_help'] = 'Automatically delete old export files';
$string['settings_export_cleanup_days'] = 'Cleanup After Days';

// Import settings
$string['settings_import'] = 'Import Settings';
$string['settings_import_enabled'] = 'Enable Import';
$string['settings_import_path'] = 'Import Path';
$string['settings_import_path_help'] = 'Directory path for import files';
$string['settings_import_max_size'] = 'Max File Size (MB)';
$string['settings_import_max_size_help'] = 'Maximum file size for imports';
$string['settings_import_batch_size'] = 'Batch Size';
$string['settings_import_batch_size_help'] = 'Number of records to process per batch';
$string['settings_import_timeout'] = 'Import Timeout (seconds)';
$string['settings_import_allowed_types'] = 'Allowed File Types';

// Keycloak settings
$string['settings_keycloak'] = 'Keycloak Settings';
$string['settings_keycloak_enabled'] = 'Enable Keycloak Integration';
$string['settings_keycloak_auto_sync'] = 'Auto-sync on Login';
$string['settings_keycloak_auto_sync_help'] = 'Automatically sync user data on login';
$string['settings_keycloak_create_users'] = 'Auto-create Users';
$string['settings_keycloak_create_users_help'] = 'Automatically create Moodle users from Keycloak';
$string['settings_keycloak_update_users'] = 'Auto-update Users';
$string['settings_keycloak_update_users_help'] = 'Automatically update user data from Keycloak';
$string['settings_keycloak_sync_interval'] = 'Sync Interval (minutes)';
$string['settings_keycloak_sync_batch'] = 'Sync Batch Size';

// Notification settings
$string['settings_notifications'] = 'Notification Settings';
$string['settings_notify_admin'] = 'Notify Admin';
$string['settings_notify_admin_help'] = 'Send notifications to administrators';
$string['settings_notify_email'] = 'Notification Email';
$string['settings_notify_email_help'] = 'Email address for notifications';
$string['settings_notify_on_error'] = 'Notify on Error';
$string['settings_notify_on_success'] = 'Notify on Success';
$string['settings_notify_on_sync'] = 'Notify on Sync Complete';
$string['settings_notify_on_export'] = 'Notify on Export Complete';
$string['settings_notify_on_import'] = 'Notify on Import Complete';

// Performance settings
$string['settings_performance'] = 'Performance Settings';
$string['settings_cache_enabled'] = 'Enable Caching';
$string['settings_cache_ttl'] = 'Cache TTL (seconds)';
$string['settings_cache_ttl_help'] = 'Time-to-live for cached data';
$string['settings_async_enabled'] = 'Enable Async Processing';
$string['settings_async_enabled_help'] = 'Process large operations asynchronously';
$string['settings_max_execution_time'] = 'Max Execution Time (seconds)';
$string['settings_memory_limit'] = 'Memory Limit (MB)';

// Security settings
$string['settings_security'] = 'Security Settings';
$string['settings_encrypt_exports'] = 'Encrypt Exports';
$string['settings_encrypt_exports_help'] = 'Encrypt exported files with AES-256';
$string['settings_encryption_key'] = 'Encryption Key';
$string['settings_encryption_key_help'] = 'Key for encrypting/decrypting data';
$string['settings_api_key'] = 'API Key';
$string['settings_api_key_help'] = 'API key for external integrations';
$string['settings_allowed_ips'] = 'Allowed IP Addresses';
$string['settings_allowed_ips_help'] = 'IP addresses allowed to access the API';
$string['settings_rate_limit'] = 'API Rate Limit';
$string['settings_rate_limit_help'] = 'Maximum API requests per minute';

// Settings.php strings
$string['keycloaksync'] = 'Keycloak Sync';
$string['keycloaksettings'] = 'Keycloak Settings';
$string['keycloaksettings_desc'] = 'Configure the connection to your Keycloak identity server.';
$string['generalsettings'] = 'General Settings';
$string['generalsettings_desc'] = 'General plugin configuration options.';
$string['enabled'] = 'Enable edulution';
$string['enabled_desc'] = 'Enable or disable the edulution plugin functionality.';
$string['keycloak_sync_enabled'] = 'Enable Keycloak Sync';
$string['keycloak_sync_enabled_desc'] = 'Enable automatic user synchronization with Keycloak.';
$string['keycloak_url'] = 'Keycloak Server URL';
$string['keycloak_url_desc'] = 'The base URL of your Keycloak server (e.g., https://keycloak.example.com).';
$string['keycloak_realm_desc'] = 'The Keycloak realm to use for authentication.';
$string['keycloak_client_id_desc'] = 'The OAuth2 client ID configured in Keycloak.';
$string['keycloak_client_secret_desc'] = 'The client secret for the OAuth2 client.';
$string['exportimportsettings'] = 'Export/Import Settings';
$string['exportimportsettings_desc'] = 'Configure export and import file paths and options.';
$string['export_path'] = 'Export Path';
$string['export_path_desc'] = 'Directory path where export files will be stored.';
$string['import_path'] = 'Import Path';
$string['import_path_desc'] = 'Directory path for import files.';
$string['export_retention_days'] = 'Export Retention (days)';
$string['export_retention_days_desc'] = 'Number of days to keep export files before automatic cleanup.';

// ============================================================================
// REPORTS
// ============================================================================
$string['reports_title'] = 'Reports';
$string['reports_subtitle'] = 'View detailed reports and analytics';
$string['reports_description'] = 'View sync and export history and logs.';

// Report types
$string['report_sync_history'] = 'Sync History';
$string['report_export_history'] = 'Export History';
$string['report_import_history'] = 'Import History';
$string['report_user_activity'] = 'User Activity';
$string['report_error_log'] = 'Error Log';
$string['report_performance'] = 'Performance Report';
$string['report_audit'] = 'Audit Trail';
$string['sync_history'] = 'Sync History';
$string['export_history'] = 'Export History';
$string['error_logs'] = 'Error Logs';

// Reports summary strings
$string['summary'] = 'Summary';
$string['total_syncs'] = 'Total Syncs';
$string['total_exports'] = 'Total Exports';
$string['total_errors'] = 'Total Errors';
$string['successful'] = 'successful';
$string['run_sync'] = 'Run Sync';
$string['error_details'] = 'Error Details';
$string['filename'] = 'Filename';
$string['unavailable'] = 'Unavailable';

// Report filters
$string['report_filter_daterange'] = 'Date Range';
$string['report_filter_status'] = 'Status';
$string['report_filter_type'] = 'Type';
$string['report_filter_user'] = 'User';
$string['report_filter_apply'] = 'Apply Filters';
$string['report_filter_clear'] = 'Clear Filters';
$string['filter_by_date'] = 'Filter by Date';
$string['filter_by_type'] = 'Filter by Type';
$string['filter_by_status'] = 'Filter by Status';
$string['clear_filters'] = 'Clear Filters';
$string['export_report'] = 'Export Report';

// Report columns
$string['report_col_date'] = 'Date';
$string['report_col_time'] = 'Time';
$string['report_col_type'] = 'Type';
$string['report_col_status'] = 'Status';
$string['report_col_user'] = 'User';
$string['report_col_details'] = 'Details';
$string['report_col_duration'] = 'Duration';
$string['report_col_records'] = 'Records';
$string['report_col_errors'] = 'Errors';
$string['report_col_action'] = 'Action';
$string['date'] = 'Date';
$string['type'] = 'Type';
$string['status'] = 'Status';
$string['details'] = 'Details';
$string['duration'] = 'Duration';
$string['file_size'] = 'File Size';
$string['success'] = 'Success';
$string['failed'] = 'Failed';
$string['partial'] = 'Partial';
$string['pending'] = 'Pending';
$string['running'] = 'Running';
$string['view_details'] = 'View Details';
$string['download'] = 'Download';
$string['delete'] = 'Delete';
$string['no_records'] = 'No records found';

// Report actions
$string['report_download'] = 'Download Report';
$string['report_print'] = 'Print Report';
$string['report_email'] = 'Email Report';
$string['report_schedule'] = 'Schedule Report';
$string['report_refresh'] = 'Refresh';

// ============================================================================
// CLI MESSAGES
// ============================================================================
$string['cli_export_start'] = 'Starting export...';
$string['cli_export_complete'] = 'Export completed successfully';
$string['cli_export_failed'] = 'Export failed: {$a}';
$string['cli_import_start'] = 'Starting import...';
$string['cli_import_complete'] = 'Import completed successfully';
$string['cli_import_failed'] = 'Import failed: {$a}';
$string['cli_sync_start'] = 'Starting synchronization...';
$string['cli_sync_complete'] = 'Synchronization completed successfully';
$string['cli_sync_failed'] = 'Synchronization failed: {$a}';
$string['cli_processing'] = 'Processing {$a->current} of {$a->total}...';
$string['cli_progress'] = 'Progress: {$a}%';
$string['cli_error'] = 'Error: {$a}';
$string['cli_warning'] = 'Warning: {$a}';
$string['cli_info'] = 'Info: {$a}';
$string['cli_usage'] = 'Usage: {$a}';
$string['cli_help'] = 'Use --help for more information';
$string['cli_option_help'] = 'Display this help message';
$string['cli_option_verbose'] = 'Enable verbose output';
$string['cli_option_quiet'] = 'Suppress output';
$string['cli_option_dryrun'] = 'Dry run (no changes)';
$string['cli_option_force'] = 'Force operation';
$string['cli_option_format'] = 'Output format (json, csv, table)';
$string['cli_option_output'] = 'Output file path';
$string['cli_option_type'] = 'Data type to process';
$string['cli_option_limit'] = 'Limit number of records';
$string['cli_option_offset'] = 'Skip records offset';
$string['cli_invalid_option'] = 'Invalid option: {$a}';
$string['cli_missing_argument'] = 'Missing required argument: {$a}';
$string['cli_file_not_found'] = 'File not found: {$a}';
$string['cli_directory_not_found'] = 'Directory not found: {$a}';
$string['cli_permission_denied'] = 'Permission denied: {$a}';

// ============================================================================
// ERROR MESSAGES
// ============================================================================
$string['error_general'] = 'An error occurred';
$string['error_unexpected'] = 'An unexpected error occurred';
$string['error_permission_denied'] = 'Permission denied';
$string['error_not_found'] = 'Resource not found';
$string['error_invalid_request'] = 'Invalid request';
$string['error_invalid_data'] = 'Invalid data provided';
$string['error_missing_data'] = 'Required data is missing';
$string['error_database'] = 'Database error';
$string['error_connection'] = 'Connection error';
$string['error_timeout'] = 'Operation timed out';
$string['error_file_read'] = 'Failed to read file';
$string['error_file_write'] = 'Failed to write file';
$string['error_file_delete'] = 'Failed to delete file';
$string['error_directory_create'] = 'Failed to create directory';
$string['error_api'] = 'API error: {$a}';
$string['error_authentication'] = 'Authentication failed';
$string['error_authorization'] = 'Authorization failed';
$string['error_configuration'] = 'Configuration error';
$string['error_plugin_disabled'] = 'Plugin is disabled';
$string['error_feature_disabled'] = 'This feature is disabled';
$string['error_maintenance'] = 'System is in maintenance mode';
$string['error_quota_exceeded'] = 'Quota exceeded';
$string['error_rate_limit'] = 'Rate limit exceeded';
$string['error_session_expired'] = 'Session expired';
$string['error_try_again'] = 'Please try again later';
$string['error_contact_admin'] = 'Please contact the administrator';

// Error messages (legacy)
$string['error_no_permission'] = 'You do not have permission to perform this action';
$string['error_export_failed'] = 'Export failed: {$a}';
$string['error_import_failed'] = 'Import failed: {$a}';
$string['error_sync_failed'] = 'Sync failed: {$a}';
$string['error_keycloak_connection'] = 'Could not connect to Keycloak: {$a}';
$string['error_file_not_found'] = 'File not found';
$string['error_directory_not_writable'] = 'Directory is not writable: {$a}';
$string['error_invalid_file'] = 'Invalid file format';

// ============================================================================
// SUCCESS MESSAGES
// ============================================================================
$string['success_general'] = 'Operation completed successfully';
$string['success_saved'] = 'Changes saved successfully';
$string['success_created'] = 'Record created successfully';
$string['success_updated'] = 'Record updated successfully';
$string['success_deleted'] = 'Record deleted successfully';
$string['success_imported'] = 'Data imported successfully';
$string['success_exported'] = 'Data exported successfully';
$string['success_synced'] = 'Data synchronized successfully';
$string['success_connected'] = 'Connected successfully';
$string['success_disconnected'] = 'Disconnected successfully';
$string['success_scheduled'] = 'Task scheduled successfully';
$string['success_cancelled'] = 'Operation cancelled';
$string['success_enabled'] = 'Feature enabled';
$string['success_disabled'] = 'Feature disabled';
$string['success_configuration'] = 'Configuration saved';
$string['success_test_passed'] = 'Test passed';

// ============================================================================
// VALIDATION MESSAGES
// ============================================================================
$string['validation_required'] = 'This field is required';
$string['validation_email'] = 'Please enter a valid email address';
$string['validation_url'] = 'Please enter a valid URL';
$string['validation_number'] = 'Please enter a valid number';
$string['validation_integer'] = 'Please enter a whole number';
$string['validation_positive'] = 'Please enter a positive number';
$string['validation_min'] = 'Value must be at least {$a}';
$string['validation_max'] = 'Value must be at most {$a}';
$string['validation_minlength'] = 'Minimum length is {$a} characters';
$string['validation_maxlength'] = 'Maximum length is {$a} characters';
$string['validation_pattern'] = 'Value does not match the required format';
$string['validation_unique'] = 'This value already exists';
$string['validation_exists'] = 'This value does not exist';
$string['validation_date'] = 'Please enter a valid date';
$string['validation_dateformat'] = 'Date format should be {$a}';
$string['validation_datefuture'] = 'Date must be in the future';
$string['validation_datepast'] = 'Date must be in the past';
$string['validation_file_required'] = 'Please select a file';
$string['validation_file_type'] = 'Invalid file type. Allowed types: {$a}';
$string['validation_file_size'] = 'File is too large. Maximum size: {$a}';
$string['validation_file_empty'] = 'File is empty';
$string['validation_json'] = 'Invalid JSON format';
$string['validation_xml'] = 'Invalid XML format';
$string['validation_csv'] = 'Invalid CSV format';
$string['validation_username'] = 'Invalid username format';
$string['validation_password'] = 'Password does not meet requirements';
$string['validation_confirm'] = 'Values do not match';
$string['validation_ip'] = 'Please enter a valid IP address';
$string['validation_port'] = 'Please enter a valid port number (1-65535)';

// ============================================================================
// CONFIRMATION MESSAGES
// ============================================================================
$string['confirm_delete'] = 'Are you sure you want to delete this?';
$string['confirm_delete_multiple'] = 'Are you sure you want to delete {$a} items?';
$string['confirm_cancel'] = 'Are you sure you want to cancel?';
$string['confirm_discard'] = 'Are you sure you want to discard changes?';
$string['confirm_overwrite'] = 'This will overwrite existing data. Continue?';
$string['confirm_sync'] = 'Are you sure you want to start synchronization?';
$string['confirm_export'] = 'Are you sure you want to start the export?';
$string['confirm_import'] = 'Are you sure you want to start the import?';
$string['confirm_reset'] = 'Are you sure you want to reset to defaults?';
$string['confirm_enable'] = 'Are you sure you want to enable this?';
$string['confirm_disable'] = 'Are you sure you want to disable this?';
$string['confirm_delete_export'] = 'Are you sure you want to delete this export?';
$string['confirm_start_sync'] = 'Are you sure you want to start the sync process?';
$string['confirm_cancel_operation'] = 'Are you sure you want to cancel this operation?';

// ============================================================================
// COMMON / UI ELEMENTS
// ============================================================================
$string['yes'] = 'Yes';
$string['no'] = 'No';
$string['ok'] = 'OK';
$string['cancel'] = 'Cancel';
$string['save'] = 'Save';
$string['save_changes'] = 'Save Changes';
$string['apply'] = 'Apply';
$string['close'] = 'Close';
$string['edit'] = 'Edit';
$string['view'] = 'View';
$string['add'] = 'Add';
$string['remove'] = 'Remove';
$string['create'] = 'Create';
$string['update'] = 'Update';
$string['search'] = 'Search';
$string['filter'] = 'Filter';
$string['clear'] = 'Clear';
$string['reset'] = 'Reset';
$string['refresh'] = 'Refresh';
$string['upload'] = 'Upload';
$string['start'] = 'Start';
$string['stop'] = 'Stop';
$string['pause'] = 'Pause';
$string['resume'] = 'Resume';
$string['retry'] = 'Retry';
$string['skip'] = 'Skip';
$string['back'] = 'Back';
$string['next'] = 'Next';
$string['previous'] = 'Previous';
$string['first'] = 'First';
$string['last'] = 'Last';
$string['finish'] = 'Finish';
$string['done'] = 'Done';
$string['enable'] = 'Enable';
$string['disable'] = 'Disable';
$string['active'] = 'Active';
$string['inactive'] = 'Inactive';
$string['name'] = 'Name';
$string['description'] = 'Description';
$string['time'] = 'Time';
$string['datetime'] = 'Date/Time';
$string['created'] = 'Created';
$string['modified'] = 'Modified';
$string['user'] = 'User';
$string['users'] = 'Users';
$string['course'] = 'Course';
$string['courses'] = 'Courses';
$string['category'] = 'Category';
$string['categories'] = 'Categories';
$string['group'] = 'Group';
$string['groups'] = 'Groups';
$string['role'] = 'Role';
$string['roles'] = 'Roles';
$string['all'] = 'All';
$string['none'] = 'None';
$string['select'] = 'Select';
$string['select_all'] = 'Select All';
$string['deselect_all'] = 'Deselect All';
$string['loading'] = 'Loading...';
$string['processing'] = 'Processing...';
$string['please_wait'] = 'Please wait...';
$string['no_data'] = 'No data available';
$string['no_results'] = 'No results found';
$string['showing'] = 'Showing {$a->start} to {$a->end} of {$a->total}';
$string['page'] = 'Page';
$string['of'] = 'of';
$string['items_per_page'] = 'Items per page';
$string['total'] = 'Total';
$string['actions'] = 'Actions';
$string['options'] = 'Options';
$string['more'] = 'More';
$string['less'] = 'Less';
$string['expand'] = 'Expand';
$string['collapse'] = 'Collapse';
$string['show'] = 'Show';
$string['hide'] = 'Hide';
$string['required'] = 'Required';
$string['optional'] = 'Optional';
$string['default'] = 'Default';
$string['custom'] = 'Custom';
$string['advanced'] = 'Advanced';
$string['basic'] = 'Basic';
$string['help'] = 'Help';
$string['info'] = 'Information';
$string['warning'] = 'Warning';
$string['error'] = 'Error';
$string['complete'] = 'Complete';
$string['incomplete'] = 'Incomplete';
$string['unknown'] = 'Unknown';
$string['new'] = 'New';
$string['old'] = 'Old';
$string['current'] = 'Current';
$string['version'] = 'Version';
$string['id'] = 'ID';
$string['preview'] = 'Preview';
$string['configure'] = 'Configure';
$string['test'] = 'Test';
$string['copy'] = 'Copy';
$string['copied'] = 'Copied!';
$string['sort'] = 'Sort';
$string['sort_asc'] = 'Sort Ascending';
$string['sort_desc'] = 'Sort Descending';
$string['more_info'] = 'More Info';

// ============================================================================
// TIME UNITS
// ============================================================================
$string['second'] = 'second';
$string['seconds'] = 'seconds';
$string['minute'] = 'minute';
$string['minutes'] = 'minutes';
$string['hour'] = 'hour';
$string['hours'] = 'hours';
$string['day'] = 'day';
$string['days'] = 'days';
$string['week'] = 'week';
$string['weeks'] = 'weeks';
$string['month'] = 'month';
$string['months'] = 'months';
$string['year'] = 'year';
$string['years'] = 'years';

// Time periods
$string['today'] = 'Today';
$string['yesterday'] = 'Yesterday';
$string['last_7_days'] = 'Last 7 days';
$string['last_30_days'] = 'Last 30 days';
$string['all_time'] = 'All time';

// ============================================================================
// TASK STRINGS
// ============================================================================
$string['task_sync_users'] = 'Synchronize users with Keycloak';
$string['task_export_data'] = 'Export scheduled data';
$string['task_import_data'] = 'Import scheduled data';
$string['task_cleanup_logs'] = 'Clean up old log entries';
$string['task_cleanup_exports'] = 'Clean up old export files';
$string['task_send_notifications'] = 'Send pending notifications';
$string['task_update_statistics'] = 'Update dashboard statistics';

// ============================================================================
// PRIVACY API
// ============================================================================
$string['privacy:metadata:local_edulution_sync'] = 'Information about user synchronization with Keycloak';
$string['privacy:metadata:local_edulution_sync:userid'] = 'The ID of the user being synchronized';
$string['privacy:metadata:local_edulution_sync:keycloakid'] = 'The Keycloak user ID';
$string['privacy:metadata:local_edulution_sync:synced'] = 'When the user was last synchronized';
$string['privacy:metadata:local_edulution_export'] = 'Information about data exports';
$string['privacy:metadata:local_edulution_export:userid'] = 'The ID of the user who performed the export';
$string['privacy:metadata:local_edulution_export:timecreated'] = 'When the export was created';
$string['privacy:metadata:local_edulution_log'] = 'Activity log information';
$string['privacy:metadata:local_edulution_log:userid'] = 'The ID of the user who performed the action';
$string['privacy:metadata:local_edulution_log:action'] = 'The action performed';
$string['privacy:metadata:local_edulution_log:timecreated'] = 'When the action was performed';

// ============================================================================
// EVENT STRINGS
// ============================================================================
$string['event_sync_started'] = 'Keycloak synchronization started';
$string['event_sync_completed'] = 'Keycloak synchronization completed';
$string['event_sync_failed'] = 'Keycloak synchronization failed';
$string['event_export_started'] = 'Data export started';
$string['event_export_completed'] = 'Data export completed';
$string['event_export_failed'] = 'Data export failed';
$string['event_import_started'] = 'Data import started';
$string['event_import_completed'] = 'Data import completed';
$string['event_import_failed'] = 'Data import failed';
$string['event_user_synced'] = 'User synchronized from Keycloak';
$string['event_settings_updated'] = 'Plugin settings updated';

// ============================================================================
// AJAX RESPONSES
// ============================================================================
$string['ajax_error'] = 'An error occurred while processing your request';
$string['ajax_success'] = 'Operation completed successfully';
$string['ajax_unauthorized'] = 'You are not authorized to perform this action';
$string['ajax_invalid_sesskey'] = 'Invalid session key';

// ============================================================================
// PROGRESS MESSAGES
// ============================================================================
$string['progress_initializing'] = 'Initializing...';
$string['progress_exporting_users'] = 'Exporting users...';
$string['progress_exporting_courses'] = 'Exporting courses...';
$string['progress_exporting_categories'] = 'Exporting categories...';
$string['progress_exporting_database'] = 'Exporting database...';
$string['progress_creating_package'] = 'Creating package...';
$string['progress_finalizing'] = 'Finalizing...';
$string['progress_importing'] = 'Importing data...';
$string['progress_syncing_users'] = 'Syncing users...';
$string['progress_complete'] = 'Complete';
$string['progress_of'] = '{$a->current} of {$a->total}';

// ============================================================================
// ACTIVITY LOG TYPES
// ============================================================================
$string['activity_export_started'] = 'Export started';
$string['activity_export_completed'] = 'Export completed';
$string['activity_export_failed'] = 'Export failed';
$string['activity_import_started'] = 'Import started';
$string['activity_import_completed'] = 'Import completed';
$string['activity_import_failed'] = 'Import failed';
$string['activity_sync_started'] = 'Keycloak sync started';
$string['activity_sync_completed'] = 'Keycloak sync completed';
$string['activity_sync_failed'] = 'Keycloak sync failed';
$string['activity_settings_updated'] = 'Settings updated';
$string['activity_keycloak_configured'] = 'Keycloak configuration updated';

// ============================================================================
// HELP TOOLTIPS
// ============================================================================
$string['help_export'] = 'Create a backup of your Moodle data that can be imported into another Moodle instance.';
$string['help_import'] = 'Restore data from a previous edulution export.';
$string['help_sync'] = 'Keep user accounts synchronized between Keycloak and Moodle.';
$string['help_keycloak'] = 'Configure the connection to your Keycloak identity provider.';

// ============================================================================
// SETTINGS.PHP ADMIN STRINGS
// ============================================================================
$string['exportsettings'] = 'Export Settings';
$string['exportdir'] = 'Export Directory';
$string['exportdir_desc'] = 'Directory where export files will be stored. Leave empty for default.';
$string['exportretention'] = 'Export Retention (days)';
$string['exportretention_desc'] = 'Number of days to keep export files before automatic cleanup.';
$string['keycloakurl'] = 'Keycloak Server URL';
$string['keycloakurl_desc'] = 'The base URL of your Keycloak server (e.g., https://keycloak.example.com).';
$string['keycloakrealm'] = 'Keycloak Realm';
$string['keycloakrealm_desc'] = 'The Keycloak realm to use for authentication.';
$string['keycloakclientid'] = 'Client ID';
$string['keycloakclientid_desc'] = 'The OAuth2 client ID configured in Keycloak.';
$string['keycloakclientsecret'] = 'Client Secret';
$string['keycloakclientsecret_desc'] = 'The client secret for the OAuth2 client.';
$string['keycloaksyncenabled'] = 'Enable Keycloak Sync';
$string['keycloaksyncenabled_desc'] = 'Enable automatic user synchronization with Keycloak.';
$string['runsync'] = 'Run Sync';
$string['viewreports'] = 'View Reports';

// ============================================================================
// KEYCLOAK API ERROR STRINGS
// ============================================================================
$string['keycloak_not_configured'] = 'Keycloak is not configured. Please set the server URL, realm, client ID, and client secret in Site Administration > Plugins > Local plugins > edulution.';
$string['keycloak_connected'] = 'Successfully connected to Keycloak realm: {$a}';
$string['keycloak_auth_failed'] = 'Keycloak authentication failed (HTTP {$a}). Check client credentials.';
$string['keycloak_curl_error'] = 'Failed to connect to Keycloak server: {$a}';
$string['keycloak_no_token'] = 'No access token received from Keycloak. Check client configuration.';
$string['keycloak_api_error'] = 'Keycloak API error (HTTP {$a->code}): {$a->message}';
$string['keycloak_json_error'] = 'Invalid JSON response from Keycloak: {$a}';
$string['keycloak_request_timeout'] = 'Keycloak request timed out after {$a} seconds';
$string['sync_started'] = 'Synchronization started. This may take a few minutes.';
$string['sync_queued'] = 'Synchronization queued for background processing. The sync will run in the next cron execution.';
$string['sync_already_running'] = 'A synchronization is already in progress. Please wait for it to complete.';
$string['sync_cancelled'] = 'Synchronization has been cancelled.';
$string['sync_status_pending'] = 'Waiting in queue for background processing...';
$string['sync_status_processing'] = 'Processing synchronization...';
$string['sync_status_processing_progress'] = 'Processing {$a->processed} of {$a->total}...';
$string['sync_status_completed'] = 'Synchronization completed successfully';
$string['sync_status_failed'] = 'Synchronization failed: {$a}';
$string['sync_status_cancelled'] = 'Synchronization was cancelled';
$string['task_adhoc_sync'] = 'Background Keycloak Synchronization';

// ============================================================================
// SYNC REPORT AND EMAIL STRINGS
// ============================================================================
$string['sync_report_subject'] = '[{$a}] Keycloak Sync Report';
$string['sync_report_body_html'] = '<h2>Keycloak Sync Report</h2><p>Site: {$a->site}</p><p>Time: {$a->time}</p><p>Duration: {$a->duration} seconds</p><h3>Summary</h3><ul><li>Created: {$a->created}</li><li>Updated: {$a->updated}</li><li>Skipped: {$a->skipped}</li><li>Errors: {$a->errors}</li></ul>';
$string['sync_report_body_text'] = 'Keycloak Sync Report

Site: {$a->site}
Time: {$a->time}
Duration: {$a->duration} seconds

Summary:
- Created: {$a->created}
- Updated: {$a->updated}
- Skipped: {$a->skipped}
- Errors: {$a->errors}';
$string['sync_error_subject'] = '[{$a}] Keycloak Sync Error';
$string['sync_error_body'] = 'Keycloak synchronization failed.

Site: {$a->site}
Time: {$a->time}
Error: {$a->error}

Please check the Moodle logs for more details.';

// ============================================================================
// GROUP PATTERN CLASSIFICATION STRINGS
// ============================================================================
$string['no_group_patterns'] = 'No group classification patterns configured. Using default patterns.';
$string['pattern_config_source'] = 'Pattern Configuration Source';
$string['pattern_config_json'] = 'JSON configuration file';
$string['pattern_config_settings'] = 'Plugin settings';
$string['pattern_config_defaults'] = 'Default patterns';
$string['group_type_class'] = 'Class Groups';
$string['group_type_teacher'] = 'Teacher Groups';
$string['group_type_project'] = 'Project Groups';
$string['group_type_ignore'] = 'Ignored Groups';
$string['group_type_unknown'] = 'Unknown Groups';

// ============================================================================
// COURSE SYNC STRINGS
// ============================================================================
$string['course_category_classes'] = 'Klassen';
$string['course_category_projects'] = 'Projekte';
$string['course_category_grade'] = 'Klassenstufe {$a}';
$string['course_category_kursstufe'] = 'Kursstufe';
$string['course_sync_created'] = 'Course created: {$a}';
$string['course_sync_updated'] = 'Course updated: {$a}';
$string['course_sync_skipped'] = 'Course skipped (already exists): {$a}';
$string['course_name_prefix_project'] = 'Projekt: ';
$string['course_name_prefix_class'] = 'Klasse ';
$string['course_name_formatted'] = 'Formatted course name: {$a->original} -> {$a->formatted}';

// Course & Category Settings
$string['coursecategorysettings'] = 'Course & Category Settings';
$string['coursecategorysettings_desc'] = 'Configure where synced courses are created and how they are named.';
$string['parentcategory'] = 'Parent Category';
$string['parentcategory_desc'] = 'Select an existing category to use as the parent for synced courses, or create a new one.';
$string['createnewcategory'] = '-- Create new "edulution Sync" category --';
$string['categorynamemain'] = 'Main Category Name';
$string['categorynamemain_desc'] = 'Name for the main sync category (only used if creating new).';
$string['categorynameclasses'] = 'Classes Category Name';
$string['categorynameclasses_desc'] = 'Name for the classes subcategory.';
$string['categorynameprojects'] = 'Projects Category Name';
$string['categorynameprojects_desc'] = 'Name for the projects subcategory.';
$string['coursenaming'] = 'Course Naming';
$string['coursenaming_desc'] = 'Configure how courses are named when created from Keycloak groups.';
$string['courseprefixproject'] = 'Project Course Prefix';
$string['courseprefixproject_desc'] = 'Prefix for project courses (e.g., "Projekt: " makes "p_biologie" become "Projekt: Biologie").';
$string['courseprefixclass'] = 'Class Course Prefix';
$string['courseprefixclass_desc'] = 'Prefix for class courses (e.g., "Klasse " makes "10a" become "Klasse 10A").';
$string['courseformatnames'] = 'Format Course Names';
$string['courseformatnames_desc'] = 'Automatically format course names (remove prefixes like p_, capitalize words, etc.).';

// ============================================================================
// ENROLLMENT SYNC STRINGS
// ============================================================================
$string['enrollment_sync_enrolled'] = 'User {$a->user} enrolled in course {$a->course}';
$string['enrollment_sync_unenrolled'] = 'User {$a->user} unenrolled from course {$a->course}';
$string['enrollment_sync_skipped'] = 'Enrollment skipped for user {$a->user}';
$string['enrollment_role_student'] = 'Student';
$string['enrollment_role_teacher'] = 'Teacher';
$string['enrollment_role_editingteacher'] = 'Editing Teacher';

// ============================================================================
// USER SYNC STRINGS
// ============================================================================
$string['user_sync_created'] = 'User created: {$a}';
$string['user_sync_updated'] = 'User updated: {$a}';
$string['user_sync_skipped'] = 'User skipped: {$a->user} - {$a->reason}';
$string['user_sync_teacher_detected'] = 'Teacher detected (LDAP_ENTRY_DN): {$a}';
$string['user_sync_student_detected'] = 'Student detected: {$a}';
$string['user_profile_field_keycloak_id'] = 'Keycloak ID';
$string['user_profile_field_keycloak_id_desc'] = 'Unique identifier from Keycloak for user linking';
