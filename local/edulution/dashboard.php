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
 * Edulution Dashboard - Einfache Übersicht und Synchronisierung.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_edulution\sync\keycloak_client;

require_login();
$context = context_system::instance();
require_capability('local/edulution:manage', $context);

$PAGE->set_url(new moodle_url('/local/edulution/dashboard.php'));
$PAGE->set_context($context);
$PAGE->set_title('Edulution');
$PAGE->set_heading('Edulution');
$PAGE->set_pagelayout('admin');

// Prüfe Konfiguration (Environment-Variablen haben Vorrang)
$keycloakurl = local_edulution_get_config('keycloak_url');
$keycloakrealm = local_edulution_get_config('keycloak_realm', 'master');
$keycloakclientid = local_edulution_get_config('keycloak_client_id');
$keycloakclientsecret = local_edulution_get_config('keycloak_client_secret');
$syncenabled = local_edulution_get_config('keycloak_sync_enabled');

$isconfigured = !empty($keycloakurl) && !empty($keycloakclientid) && !empty($keycloakclientsecret);

// Check which configs come from environment variables
$envconfigs = local_edulution_get_env_configs();

// Teste Verbindung wenn konfiguriert
$connectionstatus = null;
$keycloakusercount = 0;
if ($isconfigured) {
    try {
        $client = new keycloak_client($keycloakurl, $keycloakrealm, $keycloakclientid, $keycloakclientsecret);
        $connectionstatus = $client->test_connection();
        if ($connectionstatus['success']) {
            // Zähle Keycloak-Benutzer
            $keycloakusercount = $client->count_users();
        }
    } catch (Exception $e) {
        $connectionstatus = [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

// Hole Statistiken
$lastsynctime = get_config('local_edulution', 'last_sync_time');
$lastsyncstats = get_config('local_edulution', 'last_sync_stats');
if ($lastsyncstats) {
    $lastsyncstats = json_decode($lastsyncstats, true);
}

// Moodle-Statistiken
$totalmoodleusers = $DB->count_records('user', ['deleted' => 0]);
// Zähle synchronisierte Benutzer (oauth2 auth)
$oauth2users = $DB->count_records('user', ['deleted' => 0, 'auth' => 'oauth2']);
$syncedcourses = $DB->count_records_sql("SELECT COUNT(*) FROM {course} WHERE idnumber LIKE 'kc_%' OR idnumber LIKE 'fs_%' OR idnumber LIKE 'ag_%'");

// Nächste geplante Synchronisierung
$nextsynctime = null;
try {
    $task = \core\task\manager::get_scheduled_task('\\local_edulution\\task\\sync_keycloak');
    if ($task) {
        $nextsynctime = $task->get_next_run_time();
    }
} catch (Exception $e) {
    // Task existiert möglicherweise nicht
}

// AMD für Sync
$PAGE->requires->js_call_amd('local_edulution/sync', 'init');

echo $OUTPUT->header();

// Navigation bar.
echo local_edulution_render_nav('dashboard');

// Logo header.
$logourl = $CFG->wwwroot . '/local/edulution/pix/logo.svg';
?>

<style>
.edulution-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding: 12px 16px;
    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
    border-radius: 6px;
}
.edulution-header img {
    height: 28px;
    width: auto;
}
.edulution-header .tagline {
    color: rgba(255,255,255,0.7);
    font-size: 12px;
    margin-left: auto;
}
.dashboard-container {
    max-width: 900px;
    margin: 0 auto;
}
.status-card {
    border-radius: 6px;
    padding: 16px 20px;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.status-card.success { background: #d4edda; border: 1px solid #c3e6cb; }
.status-card.warning { background: #fff3cd; border: 1px solid #ffeeba; }
.status-card.danger { background: #f8d7da; border: 1px solid #f5c6cb; }
.status-card.info { background: #d1ecf1; border: 1px solid #bee5eb; }
.status-icon { font-size: 20px; }
.status-card.success .status-icon { color: #198754; }
.status-card.warning .status-icon { color: #856404; }
.status-card.danger .status-icon { color: #dc3545; }
.status-card.info .status-icon { color: #0c5460; }
.status-content { flex: 1; }
.status-title { font-size: 15px; font-weight: 600; margin-bottom: 2px; }
.status-detail { color: #555; font-size: 13px; }
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}
.stat-box {
    background: #fff;
    border-radius: 6px;
    padding: 12px;
    text-align: center;
    border: 1px solid #e9ecef;
}
.stat-box .stat-icon { font-size: 16px; color: #6c757d; margin-bottom: 4px; }
.stat-number { font-size: 22px; font-weight: 600; color: #212529; }
.stat-label { color: #6c757d; font-size: 11px; margin-top: 2px; }
.sync-section {
    background: #fff;
    border-radius: 6px;
    padding: 16px;
    border: 1px solid #e9ecef;
    margin-bottom: 16px;
}
.sync-section h3 { font-size: 14px; font-weight: 600; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
.sync-section h3 i { color: #6c757d; font-size: 14px; }
.sync-btn { font-size: 13px; padding: 8px 16px; }
.sync-btn i { margin-right: 4px; }
.last-sync-info {
    background: #f8f9fa;
    border-radius: 4px;
    padding: 12px;
    margin-top: 12px;
    font-size: 13px;
}
.last-sync-info h5 { font-size: 13px; font-weight: 600; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.last-sync-info h5 i { color: #6c757d; }
.config-table { font-size: 13px; }
.config-table td:first-child { width: 140px; color: #6c757d; }
.config-table td:first-child i { margin-right: 6px; width: 14px; text-align: center; }
</style>

<div class="dashboard-container">

    <!-- Logo Header -->
    <div class="edulution-header">
        <img src="<?php echo $logourl; ?>" alt="Edulution">
        <span class="tagline">Keycloak Sync for Moodle</span>
    </div>

    <!-- Status-Karte -->
    <?php if (!$isconfigured): ?>
    <div class="status-card warning">
        <div class="status-icon"><i class="fa fa-cogs"></i></div>
        <div class="status-content">
            <div class="status-title">Einrichtung erforderlich</div>
            <div class="status-detail">Keycloak-Verbindung muss konfiguriert werden.</div>
        </div>
        <a href="<?php echo new moodle_url('/local/edulution/setup.php'); ?>" class="btn btn-sm btn-primary">
            <i class="fa fa-magic"></i> Einrichten
        </a>
    </div>

    <?php elseif (!$connectionstatus || !$connectionstatus['success']): ?>
    <div class="status-card danger">
        <div class="status-icon"><i class="fa fa-exclamation-circle"></i></div>
        <div class="status-content">
            <div class="status-title">Verbindungsproblem</div>
            <div class="status-detail"><?php echo s($connectionstatus['message'] ?? 'Keine Verbindung zu Keycloak'); ?></div>
        </div>
        <a href="<?php echo new moodle_url('/local/edulution/setup.php'); ?>" class="btn btn-sm btn-warning">
            <i class="fa fa-wrench"></i> Prüfen
        </a>
    </div>

    <?php elseif (!$syncenabled): ?>
    <div class="status-card info">
        <div class="status-icon"><i class="fa fa-link"></i></div>
        <div class="status-content">
            <div class="status-title">Verbunden, Sync deaktiviert</div>
            <div class="status-detail">Die Verbindung funktioniert, Auto-Sync ist aus.</div>
        </div>
        <form method="post" action="<?php echo new moodle_url('/local/edulution/dashboard.php'); ?>" style="display:inline;">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="enable_sync">
            <button type="submit" class="btn btn-sm btn-success"><i class="fa fa-power-off"></i> Aktivieren</button>
        </form>
    </div>

    <?php else: ?>
    <div class="status-card success">
        <div class="status-icon"><i class="fa fa-check-circle"></i></div>
        <div class="status-content">
            <div class="status-title">Bereit</div>
            <div class="status-detail">
                Keycloak verbunden, Auto-Sync aktiv.
                <?php if ($nextsynctime): ?>
                Nächste Sync: <?php echo userdate($nextsynctime, '%d.%m. %H:%M'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Statistiken -->
    <?php if ($isconfigured && $connectionstatus && $connectionstatus['success']): ?>
    <div class="stat-grid">
        <div class="stat-box">
            <div class="stat-icon"><i class="fa fa-key"></i></div>
            <div class="stat-number"><?php echo number_format($keycloakusercount); ?></div>
            <div class="stat-label">Keycloak</div>
        </div>
        <div class="stat-box">
            <div class="stat-icon"><i class="fa fa-users"></i></div>
            <div class="stat-number"><?php echo number_format($oauth2users); ?></div>
            <div class="stat-label">Synchronisiert</div>
        </div>
        <div class="stat-box">
            <div class="stat-icon"><i class="fa fa-graduation-cap"></i></div>
            <div class="stat-number"><?php echo number_format($syncedcourses); ?></div>
            <div class="stat-label">Kurse</div>
        </div>
        <div class="stat-box">
            <div class="stat-icon"><i class="fa fa-clock-o"></i></div>
            <div class="stat-number"><?php echo $lastsynctime ? userdate($lastsynctime, '%H:%M') : '—'; ?></div>
            <div class="stat-label">Letzte Sync</div>
        </div>
    </div>

    <!-- Sync-Bereich -->
    <div class="sync-section">
        <h3><i class="fa fa-refresh"></i> Synchronisierung</h3>

        <div class="text-center">
            <button id="sync-preview-btn" class="btn btn-outline-primary sync-btn me-2">
                <i class="fa fa-search"></i> Vorschau
            </button>
            <button id="sync-start-btn" class="btn btn-success sync-btn" style="display: none;">
                <i class="fa fa-play"></i> Starten
            </button>
        </div>

        <!-- Vorschau Container -->
        <div id="sync-preview-container" style="display: none;" class="mt-4"></div>

        <!-- Fortschritt Container -->
        <div id="sync-progress-container" style="display: none;" class="mt-4">
            <div class="alert alert-info">
                <strong>Synchronisierung läuft...</strong>
                <p class="mb-2" id="sync-status-text">Initialisiere...</p>
            </div>
            <div class="progress" style="height: 25px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated"
                     role="progressbar" style="width: 0%;" id="sync-progress-bar">0%</div>
            </div>
        </div>

        <!-- Ergebnis Container -->
        <div id="sync-results-container" style="display: none;" class="mt-4"></div>

        <!-- Letzte Synchronisierung -->
        <?php if ($lastsynctime && $lastsyncstats): ?>
        <div class="last-sync-info">
            <h5><i class="fa fa-history"></i> Letzte Sync: <?php echo userdate($lastsynctime, '%d.%m.%Y %H:%M'); ?></h5>
            <div class="d-flex flex-wrap gap-3">
                <?php if (isset($lastsyncstats['users_created'])): ?>
                <span><i class="fa fa-user-plus text-muted"></i> <?php echo $lastsyncstats['users_created']; ?> Benutzer</span>
                <?php endif; ?>
                <?php if (isset($lastsyncstats['courses_created'])): ?>
                <span><i class="fa fa-plus-circle text-muted"></i> <?php echo $lastsyncstats['courses_created']; ?> Kurse</span>
                <?php endif; ?>
                <?php if (isset($lastsyncstats['enrollments_created'])): ?>
                <span><i class="fa fa-sign-in text-muted"></i> <?php echo $lastsyncstats['enrollments_created']; ?> Einschreibungen</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Keycloak Konfiguration -->
    <?php if ($isconfigured): ?>
    <div class="sync-section">
        <h3><i class="fa fa-key"></i> Keycloak</h3>
        <?php if (!empty($envconfigs)): ?>
        <div class="alert alert-info py-2 mb-2" style="font-size: 12px;">
            <i class="fa fa-info-circle"></i> Einige Werte sind über Umgebungsvariablen gesetzt.
        </div>
        <?php endif; ?>
        <table class="table table-sm config-table mb-0">
            <tr>
                <td><i class="fa fa-server"></i> URL</td>
                <td><code><?php echo s($keycloakurl); ?></code><?php if (isset($envconfigs['keycloak_url'])): ?> <span class="badge bg-secondary">ENV</span><?php endif; ?></td>
            </tr>
            <tr>
                <td><i class="fa fa-database"></i> Realm</td>
                <td><code><?php echo s($keycloakrealm); ?></code><?php if (isset($envconfigs['keycloak_realm'])): ?> <span class="badge bg-secondary">ENV</span><?php endif; ?></td>
            </tr>
            <tr>
                <td><i class="fa fa-id-card"></i> Client</td>
                <td><code><?php echo s($keycloakclientid); ?></code><?php if (isset($envconfigs['keycloak_client_id'])): ?> <span class="badge bg-secondary">ENV</span><?php endif; ?></td>
            </tr>
            <tr>
                <td><i class="fa fa-refresh"></i> Auto-Sync</td>
                <td><?php echo $syncenabled ? '<i class="fa fa-check text-success"></i> An' : '<i class="fa fa-times text-muted"></i> Aus'; ?></td>
            </tr>
        </table>
    </div>
    <?php endif; ?>

</div>

<?php
echo $OUTPUT->footer();
