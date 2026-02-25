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
 * Schema documentation and testing page.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('local/edulution:manage', context_system::instance());

admin_externalpage_setup('local_edulution_dashboard');

$PAGE->set_url(new moodle_url('/local/edulution/pages/schema_docs.php'));
$PAGE->set_title('Namensschema-Dokumentation');
$PAGE->set_heading('Namensschema-Dokumentation');

// Process test request.
$test_result = null;
$test_group = optional_param('test_group', '', PARAM_TEXT);
if (!empty($test_group) && confirm_sesskey()) {
    require_once(__DIR__ . '/../classes/sync/naming_schema_processor.php');
    require_once(__DIR__ . '/../classes/sync/naming_schema.php');
    require_once(__DIR__ . '/../classes/sync/template_transformer.php');

    // Load current configuration.
    $schema_json = get_config('local_edulution', 'naming_schemas');
    if (!empty($schema_json)) {
        $config = json_decode($schema_json, true);
    }
    if (empty($config)) {
        $config = \local_edulution\sync\naming_schema_processor::get_german_school_defaults();
    }

    $processor = new \local_edulution\sync\naming_schema_processor($config);
    $test_result = $processor->process($test_group, 'test-id');
}

echo $OUTPUT->header();

// Navigation bar.
echo local_edulution_render_nav('docs');
?>

<div class="container-fluid">
    <div class="alert alert-info mb-4">
        <strong>Hinweis:</strong> Eine einfachere Erklärung finden Sie in der
        <a href="https://docs.edulution.io/docs/edulution-moodle/konfiguration/namensschemas"
            target="_blank">Online-Dokumentation</a>.
        Diese Seite richtet sich an fortgeschrittene Benutzer.
    </div>

    <div class="row">
        <div class="col-md-8">
            <h2>Namensschema-System</h2>
            <p class="lead">
                Das Namensschema-System wandelt Keycloak-Gruppennamen automatisch in Moodle-Kurse um
                und platziert sie in der richtigen Kategorie.
            </p>

            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title mb-0">So funktioniert's</h3>
                </div>
                <div class="card-body">
                    <ol>
                        <li><strong>Muster-Erkennung:</strong> Gruppennamen werden gegen Regex-Muster in
                            Prioritätsreihenfolge geprüft</li>
                        <li><strong>Daten-Extraktion:</strong> Benannte Capture-Gruppen extrahieren Teile des
                            Gruppennamens</li>
                        <li><strong>Template-Verarbeitung:</strong> Templates nutzen die extrahierten Daten für
                            Kursnamen</li>
                        <li><strong>Kategorie-Erstellung:</strong> Kategorien werden automatisch erstellt, wenn sie
                            fehlen</li>
                    </ol>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title mb-0">Muster-Syntax (Regex)</h3>
                </div>
                <div class="card-body">
                    <p>Muster verwenden PHP/PCRE Regex-Syntax mit benannten Capture-Gruppen:</p>
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Syntax</th>
                                <th>Bedeutung</th>
                                <th>Beispiel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>(?P&lt;name&gt;...)</code></td>
                                <td>Benannte Capture-Gruppe</td>
                                <td><code>(?P&lt;fach&gt;[a-z]+)</code></td>
                            </tr>
                            <tr>
                                <td><code>^</code></td>
                                <td>Anfang der Zeichenkette</td>
                                <td><code>^p_</code></td>
                            </tr>
                            <tr>
                                <td><code>$</code></td>
                                <td>Ende der Zeichenkette</td>
                                <td><code>_students$</code></td>
                            </tr>
                            <tr>
                                <td><code>\d+</code></td>
                                <td>Eine oder mehrere Ziffern</td>
                                <td><code>(?P&lt;stufe&gt;\d+)</code></td>
                            </tr>
                            <tr>
                                <td><code>[a-z]+</code></td>
                                <td>Ein oder mehrere Kleinbuchstaben</td>
                                <td><code>(?P&lt;klasse&gt;\d+[a-z])</code></td>
                            </tr>
                            <tr>
                                <td><code>.+</code></td>
                                <td>Ein oder mehrere beliebige Zeichen</td>
                                <td><code>(?P&lt;name&gt;.+)</code></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title mb-0">Template-Syntax</h3>
                </div>
                <div class="card-body">
                    <p>Templates verwenden <code>{variable|transformer}</code> Syntax:</p>
                    <pre class="bg-light p-2">{fach|map:subject_map} Klasse {klasse|upper}</pre>
                    <p>Ergebnis mit fach=bio, klasse=10a: <strong>Biologie Klasse 10A</strong></p>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title mb-0">Verfügbare Transformer</h3>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Transformer</th>
                                <th>Beschreibung</th>
                                <th>Beispiel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>upper</code></td>
                                <td>Großbuchstaben</td>
                                <td><code>{name|upper}</code> → "BIOLOGIE"</td>
                            </tr>
                            <tr>
                                <td><code>lower</code></td>
                                <td>Kleinbuchstaben</td>
                                <td><code>{name|lower}</code> → "biologie"</td>
                            </tr>
                            <tr>
                                <td><code>ucfirst</code></td>
                                <td>Erster Buchstabe groß</td>
                                <td><code>{name|ucfirst}</code> → "Biologie"</td>
                            </tr>
                            <tr>
                                <td><code>titlecase</code></td>
                                <td>Jedes Wort groß</td>
                                <td><code>{name|titlecase}</code> → "Alle Biologie"</td>
                            </tr>
                            <tr>
                                <td><code>replace:a:b</code></td>
                                <td>Ersetze a durch b</td>
                                <td><code>{name|replace:_: }</code></td>
                            </tr>
                            <tr>
                                <td><code>truncate:n</code></td>
                                <td>Auf n Zeichen kürzen</td>
                                <td><code>{name|truncate:20}</code></td>
                            </tr>
                            <tr>
                                <td><code>extract_grade</code></td>
                                <td>Stufe extrahieren</td>
                                <td><code>{klasse|extract_grade}</code> "10a" → "10"</td>
                            </tr>
                            <tr>
                                <td><code>map:name</code></td>
                                <td>In Tabelle nachschlagen</td>
                                <td><code>{fach|map:subject_map}</code> "bio" → "Biologie"</td>
                            </tr>
                            <tr>
                                <td><code>default:wert</code></td>
                                <td>Standardwert wenn leer</td>
                                <td><code>{name|default:Unbekannt}</code></td>
                            </tr>
                            <tr>
                                <td><code>pad:n</code></td>
                                <td>Mit Nullen auffüllen</td>
                                <td><code>{num|pad:2}</code> "5" → "05"</td>
                            </tr>
                            <tr>
                                <td><code>clean</code></td>
                                <td>Sonderzeichen entfernen</td>
                                <td><code>{name|clean}</code></td>
                            </tr>
                            <tr>
                                <td><code>slug</code></td>
                                <td>URL-sicherer Name</td>
                                <td><code>{name|slug}</code></td>
                            </tr>
                        </tbody>
                    </table>
                    <p><strong>Verkettung:</strong> Transformer können verkettet werden:
                        <code>{name|upper|truncate:20}</code></p>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title mb-0">Beispiel-Schemas</h3>
                </div>
                <div class="card-body">
                    <h5>Fachschaft</h5>
                    <pre class="bg-light p-2">{
  "id": "fachschaft",
  "pattern": "^p_alle_(?P&lt;fach&gt;[a-zA-Z]+)$",
  "course_name": "Fachschaft {fach|map:subject_map}",
  "category_path": "Fachschaften"
}</pre>
                    <p><code>p_alle_bio</code> → Kurs: <strong>Fachschaft Biologie</strong> in Kategorie
                        <strong>Fachschaften</strong></p>

                    <h5>Lehrerkurs</h5>
                    <pre class="bg-light p-2">{
  "id": "lehrer_kurs",
  "pattern": "^p_(?P&lt;lehrer&gt;[a-z]+)_(?P&lt;fach&gt;[a-zA-Z]+)_(?P&lt;stufe&gt;\\d+[a-z]?)$",
  "course_name": "{fach|map:subject_map} Stufe {stufe} ({lehrer|upper})",
  "category_path": "Kurse/Stufe {stufe|extract_grade}"
}</pre>
                    <p><code>p_mueller_bio_10a</code> → Kurs: <strong>Biologie Stufe 10A (MUELLER)</strong> in Kategorie
                        <strong>Kurse/Stufe 10</strong></p>

                    <h5>Klassenkurs</h5>
                    <pre class="bg-light p-2">{
  "id": "klasse_fach",
  "pattern": "^p_(?P&lt;klasse&gt;\\d+[a-z])_(?P&lt;fach&gt;[a-zA-Z]+)$",
  "course_name": "{fach|map:subject_map} Klasse {klasse|upper}",
  "category_path": "Klassen/Stufe {klasse|extract_grade}"
}</pre>
                    <p><code>p_10a_mathe</code> → Kurs: <strong>Mathematik Klasse 10A</strong> in Kategorie
                        <strong>Klassen/Stufe 10</strong></p>

                    <h5>Lehrer-zentrierte Struktur (Alternative)</h5>
                    <pre class="bg-light p-2">{
  "id": "lehrer_zentral",
  "pattern": "^p_(?P&lt;lehrer&gt;[a-z]+)_(?P&lt;fach&gt;[a-zA-Z]+)_(?P&lt;klasse&gt;\\d+[a-z]?)$",
  "course_name": "{fach|map:subject_map} {klasse|upper}",
  "category_path": "Lehrer/{lehrer|titlecase}"
}</pre>
                    <p><code>p_mueller_bio_10a</code> → Kurs: <strong>Biologie 10A</strong> in Kategorie
                        <strong>Lehrer/Mueller</strong></p>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h3 class="card-title mb-0">Schema testen</h3>
                </div>
                <div class="card-body">
                    <form method="post" action="">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <div class="form-group">
                            <label for="test_group">Keycloak-Gruppenname eingeben:</label>
                            <input type="text" name="test_group" id="test_group" class="form-control"
                                placeholder="p_alle_bio" value="<?php echo s($test_group); ?>">
                            <small class="text-muted">z.B. p_alle_mathe, p_mueller_bio_10a, 10a-students</small>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block mt-2">Testen</button>
                    </form>

                    <?php if ($test_result !== null): ?>
                        <hr>
                        <h5>Ergebnis:</h5>
                        <table class="table table-sm">
                            <tr>
                                <th>Schema</th>
                                <td><?php echo s($test_result['schema_id']); ?></td>
                            </tr>
                            <tr>
                                <th>Kursname</th>
                                <td><strong><?php echo s($test_result['course_fullname']); ?></strong></td>
                            </tr>
                            <tr>
                                <th>Kurzname</th>
                                <td><code><?php echo s($test_result['course_shortname']); ?></code></td>
                            </tr>
                            <tr>
                                <th>ID-Nummer</th>
                                <td><code><?php echo s($test_result['course_idnumber']); ?></code></td>
                            </tr>
                            <tr>
                                <th>Kategorie</th>
                                <td><?php echo s($test_result['category_path']); ?></td>
                            </tr>
                        </table>
                        <details>
                            <summary>Extrahierte Variablen</summary>
                            <pre class="bg-light p-2 mt-2"><?php print_r($test_result['captured_groups']); ?></pre>
                        </details>
                    <?php elseif (!empty($test_group)): ?>
                        <hr>
                        <div class="alert alert-warning">
                            <strong>Kein Treffer!</strong> Der Gruppenname passt zu keinem Schema.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="card-title mb-0">Fächerkürzel</h3>
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Kürzel</th>
                                <th>Fachname</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>bio</td>
                                <td>Biologie</td>
                            </tr>
                            <tr>
                                <td>m, ma, mathe</td>
                                <td>Mathematik</td>
                            </tr>
                            <tr>
                                <td>d, de, deutsch</td>
                                <td>Deutsch</td>
                            </tr>
                            <tr>
                                <td>e, en, eng</td>
                                <td>Englisch</td>
                            </tr>
                            <tr>
                                <td>f, fr, franz</td>
                                <td>Französisch</td>
                            </tr>
                            <tr>
                                <td>ph, phy</td>
                                <td>Physik</td>
                            </tr>
                            <tr>
                                <td>ch, chem</td>
                                <td>Chemie</td>
                            </tr>
                            <tr>
                                <td>g, ge, gesch</td>
                                <td>Geschichte</td>
                            </tr>
                            <tr>
                                <td>geo, ek</td>
                                <td>Geografie/Erdkunde</td>
                            </tr>
                            <tr>
                                <td>mu, mus</td>
                                <td>Musik</td>
                            </tr>
                            <tr>
                                <td>ku, bk</td>
                                <td>Kunst</td>
                            </tr>
                            <tr>
                                <td>sp, spo</td>
                                <td>Sport</td>
                            </tr>
                            <tr>
                                <td>eth, rel</td>
                                <td>Ethik/Religion</td>
                            </tr>
                            <tr>
                                <td>inf, it</td>
                                <td>Informatik</td>
                            </tr>
                            <tr>
                                <td>l, la, lat</td>
                                <td>Latein</td>
                            </tr>
                            <tr>
                                <td>spa</td>
                                <td>Spanisch</td>
                            </tr>
                            <tr>
                                <td>nwt</td>
                                <td>NwT</td>
                            </tr>
                            <tr>
                                <td>gk</td>
                                <td>Gemeinschaftskunde</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">Schnellzugriff</h3>
                </div>
                <div class="card-body">
                    <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'local_edulution_advanced']); ?>"
                        class="btn btn-outline-primary btn-block mb-2">Schemas bearbeiten</a>
                    <a href="<?php echo new moodle_url('/local/edulution/dashboard.php'); ?>"
                        class="btn btn-outline-secondary btn-block mb-2">Dashboard</a>
                    <a href="https://docs.edulution.io/docs/edulution-moodle/konfiguration/namensschemas"
                        target="_blank" class="btn btn-outline-info btn-block">Online-Dokumentation</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();
