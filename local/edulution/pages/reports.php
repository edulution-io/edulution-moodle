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
 * Reports page for local_edulution.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../lib.php');

// Require login and capability.
require_login();
require_capability('local/edulution:viewreports', context_system::instance());

// Get parameters.
$tab = optional_param('tab', 'sync', PARAM_ALPHA);
$validtabs = ['sync', 'export', 'errors'];
if (!in_array($tab, $validtabs)) {
    $tab = 'sync';
}

// Set up the page.
$PAGE->set_url(new moodle_url('/local/edulution/pages/reports.php', ['tab' => $tab]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('reports_title', 'local_edulution'));
$PAGE->set_heading(get_string('reports_title', 'local_edulution'));
$PAGE->set_pagelayout('admin');

// Get data based on selected tab.
$records = [];

switch ($tab) {
    case 'export':
        $records = local_edulution_get_export_history(20, 0);
        break;
    case 'errors':
        $allactivity = local_edulution_get_recent_activity(100);
        $records = array_filter($allactivity, function ($r) {
            return ($r->status ?? '') === 'failed' || ($r->status ?? '') === 'error';
        });
        $records = array_values($records);
        break;
    case 'sync':
    default:
        $records = local_edulution_get_sync_history(20, 0);
        break;
}

// Summary statistics.
$synchistory = local_edulution_get_sync_history(100, 0);
$exporthistory = local_edulution_get_export_history(100, 0);
$totalsync = count($synchistory);
$totalexport = count($exporthistory);
$lastsynctime = get_config('local_edulution', 'last_sync_time');

// Output the page.
echo $OUTPUT->header();
echo local_edulution_render_nav('reports');
?>

<style>
    .reports-container {
        max-width: 1000px;
        margin: 0 auto;
    }

    .stat-row {
        display: flex;
        gap: 12px;
        margin-bottom: 16px;
    }

    .stat-item {
        flex: 1;
        background: #f8f9fa;
        border-radius: 4px;
        padding: 12px;
        text-align: center;
        border: 1px solid #e9ecef;
    }

    .stat-item .num {
        font-size: 20px;
        font-weight: 600;
        color: #212529;
    }

    .stat-item .lbl {
        font-size: 11px;
        color: #6c757d;
        margin-top: 2px;
    }

    .tab-row {
        display: flex;
        gap: 4px;
        margin-bottom: 16px;
    }

    .tab-btn {
        padding: 8px 16px;
        border: 1px solid #dee2e6;
        background: #fff;
        border-radius: 4px;
        color: #495057;
        text-decoration: none;
        font-size: 13px;
    }

    .tab-btn:hover {
        background: #f8f9fa;
        color: #212529;
        text-decoration: none;
    }

    .tab-btn.active {
        background: #0d6efd;
        border-color: #0d6efd;
        color: #fff;
    }

    .tab-btn i {
        margin-right: 4px;
    }

    .report-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .report-table th {
        background: #f8f9fa;
        padding: 8px 10px;
        text-align: left;
        font-weight: 600;
        border-bottom: 2px solid #dee2e6;
    }

    .report-table td {
        padding: 8px 10px;
        border-bottom: 1px solid #e9ecef;
    }

    .report-table tr:hover {
        background: #f8f9fa;
    }

    .badge-ok {
        background: #d4edda;
        color: #155724;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
    }

    .badge-err {
        background: #f8d7da;
        color: #721c24;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
    }

    .empty-state {
        text-align: center;
        padding: 40px;
        color: #6c757d;
    }

    .empty-state i {
        font-size: 32px;
        margin-bottom: 8px;
        display: block;
    }
</style>

<div class="reports-container">

    <!-- Summary Stats -->
    <div class="stat-row">
        <div class="stat-item">
            <div class="num"><?php echo $totalsync; ?></div>
            <div class="lbl"><?php echo get_string('total_syncs', 'local_edulution'); ?></div>
        </div>
        <div class="stat-item">
            <div class="num"><?php echo $totalexport; ?></div>
            <div class="lbl"><?php echo get_string('total_exports', 'local_edulution'); ?></div>
        </div>
        <div class="stat-item">
            <div class="num"><?php echo $lastsynctime ? userdate($lastsynctime, '%d.%m. %H:%M') : '—'; ?></div>
            <div class="lbl"><?php echo get_string('last_sync', 'local_edulution'); ?></div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="tab-row">
        <a href="<?php echo new moodle_url('/local/edulution/pages/reports.php', ['tab' => 'sync']); ?>"
            class="tab-btn <?php echo $tab === 'sync' ? 'active' : ''; ?>">
            <i class="fa fa-refresh"></i> <?php echo get_string('sync_history', 'local_edulution'); ?>
        </a>
        <a href="<?php echo new moodle_url('/local/edulution/pages/reports.php', ['tab' => 'export']); ?>"
            class="tab-btn <?php echo $tab === 'export' ? 'active' : ''; ?>">
            <i class="fa fa-download"></i> <?php echo get_string('export_history', 'local_edulution'); ?>
        </a>
        <a href="<?php echo new moodle_url('/local/edulution/pages/reports.php', ['tab' => 'errors']); ?>"
            class="tab-btn <?php echo $tab === 'errors' ? 'active' : ''; ?>">
            <i class="fa fa-exclamation-triangle"></i> <?php echo get_string('error_logs', 'local_edulution'); ?>
        </a>
    </div>

    <!-- Content -->
    <?php if (empty($records)): ?>
        <div class="empty-state">
            <i class="fa fa-inbox"></i>
            <?php echo get_string('no_records', 'local_edulution'); ?>
        </div>
    <?php elseif ($tab === 'sync'): ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th><?php echo get_string('date', 'local_edulution'); ?></th>
                    <th><?php echo get_string('status', 'local_edulution'); ?></th>
                    <th><?php echo get_string('users_created', 'local_edulution'); ?></th>
                    <th><?php echo get_string('users_updated', 'local_edulution'); ?></th>
                    <th><?php echo get_string('duration', 'local_edulution'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r): ?>
                    <tr>
                        <td><?php echo isset($r->timecreated) ? userdate($r->timecreated, '%d.%m.%Y %H:%M') : '-'; ?></td>
                        <td>
                            <?php if (($r->status ?? '') === 'success'): ?>
                                <span class="badge-ok"><?php echo get_string('success', 'local_edulution'); ?></span>
                            <?php else: ?>
                                <span class="badge-err"><?php echo get_string('failed', 'local_edulution'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int) ($r->users_created ?? 0); ?></td>
                        <td><?php echo (int) ($r->users_updated ?? 0); ?></td>
                        <td><?php echo isset($r->duration) ? gmdate('i:s', $r->duration) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($tab === 'export'): ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th><?php echo get_string('date', 'local_edulution'); ?></th>
                    <th><?php echo get_string('filename', 'local_edulution'); ?></th>
                    <th><?php echo get_string('file_size', 'local_edulution'); ?></th>
                    <th><?php echo get_string('status', 'local_edulution'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r): ?>
                    <tr>
                        <td><?php echo isset($r->timecreated) ? userdate($r->timecreated, '%d.%m.%Y %H:%M') : '-'; ?></td>
                        <td><code style="font-size: 11px;"><?php echo s($r->filename ?? '-'); ?></code></td>
                        <td><?php echo isset($r->filesize) ? local_edulution_format_filesize($r->filesize) : '-'; ?></td>
                        <td>
                            <?php if (($r->status ?? '') === 'success'): ?>
                                <span class="badge-ok"><?php echo get_string('success', 'local_edulution'); ?></span>
                            <?php else: ?>
                                <span class="badge-err"><?php echo get_string('failed', 'local_edulution'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th><?php echo get_string('date', 'local_edulution'); ?></th>
                    <th><?php echo get_string('type', 'local_edulution'); ?></th>
                    <th><?php echo get_string('description', 'local_edulution'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $r): ?>
                    <tr>
                        <td><?php echo isset($r->timecreated) ? userdate($r->timecreated, '%d.%m.%Y %H:%M') : '-'; ?></td>
                        <td><span class="badge-err"><?php echo s($r->type ?? '-'); ?></span></td>
                        <td><?php echo s($r->description ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</div>

<?php
echo $OUTPUT->footer();
