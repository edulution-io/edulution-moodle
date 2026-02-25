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
 * Naming schema processor - manages and applies naming schemas.
 *
 * This class loads schema configurations, matches Keycloak groups to schemas,
 * and generates course names and category paths.
 *
 * @package    local_edulution
 * @copyright  2026 edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * Processes naming schemas and matches groups to schemas.
 */
class naming_schema_processor
{

    /** @var naming_schema[] Array of schema objects sorted by priority */
    protected array $schemas = [];

    /** @var string[] Patterns for groups to ignore */
    protected array $ignore_patterns = [];

    /** @var template_transformer Transformer instance */
    protected template_transformer $transformer;

    /** @var array Default course settings */
    protected array $defaults = [];

    /** @var string Configuration source */
    protected string $config_source = '';

    /**
     * Constructor.
     *
     * @param array|null $config Custom configuration or null to load from settings.
     */
    public function __construct(?array $config = null)
    {
        $this->transformer = new template_transformer();

        if ($config !== null) {
            $this->load_from_array($config);
            $this->config_source = 'custom';
        } else {
            $this->load_config();
        }
    }

    /**
     * Load configuration from available sources.
     */
    protected function load_config(): void
    {
        // Try plugin settings first.
        $loaded = $this->load_from_plugin_settings();
        if ($loaded) {
            return;
        }

        // Try JSON file.
        $config_path = get_config('local_edulution', 'naming_schemas_path');
        if (!empty($config_path) && file_exists($config_path)) {
            $loaded = $this->load_from_json($config_path);
            if ($loaded) {
                return;
            }
        }

        // Use defaults.
        $this->load_defaults();
    }

    /**
     * Load configuration from JSON file.
     *
     * @param string $path Path to JSON file.
     * @return bool True if loaded successfully.
     */
    protected function load_from_json(string $path): bool
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            return false;
        }

        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        $this->load_from_array($config);
        $this->config_source = 'json';
        return true;
    }

    /**
     * Load from plugin settings.
     *
     * @return bool True if loaded.
     */
    protected function load_from_plugin_settings(): bool
    {
        $schemas_json = get_config('local_edulution', 'naming_schemas');
        if (empty($schemas_json)) {
            return false;
        }

        $config = json_decode($schemas_json, true);
        if (!is_array($config) || empty($config['schemas'])) {
            return false;
        }

        $this->load_from_array($config);
        $this->config_source = 'settings';
        return true;
    }

    /**
     * Load configuration from array.
     *
     * @param array $config Configuration array.
     */
    public function load_from_array(array $config): void
    {
        // Load defaults.
        $this->defaults = $config['defaults'] ?? [
            'course_format' => 'topics',
            'num_sections' => 10,
            'visible' => true,
        ];

        // Load transformer maps.
        if (isset($config['transformers']) && is_array($config['transformers'])) {
            foreach ($config['transformers'] as $name => $map) {
                $this->transformer->register_map($name, $map);
            }
        }

        // Load ignore patterns.
        $this->ignore_patterns = $config['ignore_patterns'] ?? [];

        // Load schemas.
        $this->schemas = [];
        if (isset($config['schemas']) && is_array($config['schemas'])) {
            foreach ($config['schemas'] as $schema_config) {
                $schema = new naming_schema($schema_config);
                if ($schema->is_enabled() && $this->validate_pattern($schema->get_pattern())) {
                    $this->schemas[] = $schema;
                }
            }
        }

        // Sort by priority (lower = higher priority).
        usort($this->schemas, function ($a, $b) {
            return $a->get_priority() - $b->get_priority();
        });
    }

    /**
     * Load default German school configuration.
     */
    protected function load_defaults(): void
    {
        $defaults = self::get_german_school_defaults();
        $this->load_from_array($defaults);
        $this->config_source = 'defaults';
    }

    /**
     * Validate a regex pattern.
     *
     * @param string $pattern Pattern to validate.
     * @return bool True if valid.
     */
    protected function validate_pattern(string $pattern): bool
    {
        if (empty($pattern)) {
            return false;
        }

        $delimiter = substr($pattern, 0, 1) === '/' ? '' : '/';
        $regex = $delimiter . $pattern . $delimiter . 'u';

        return @preg_match($regex, '') !== false;
    }

    /**
     * Check if a group should be ignored.
     *
     * @param string $group_name Group name to check.
     * @return bool True if should be ignored.
     */
    public function should_ignore(string $group_name): bool
    {
        foreach ($this->ignore_patterns as $pattern) {
            $delimiter = substr($pattern, 0, 1) === '/' ? '' : '/';
            $regex = $delimiter . $pattern . $delimiter . 'u';

            if (@preg_match($regex, $group_name) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find the matching schema for a group name.
     *
     * @param string $group_name Group name to match.
     * @return naming_schema|null Matching schema or null.
     */
    public function find_schema(string $group_name): ?naming_schema
    {
        if ($this->should_ignore($group_name)) {
            return null;
        }

        foreach ($this->schemas as $schema) {
            if ($schema->matches($group_name)) {
                return $schema;
            }
        }

        return null;
    }

    /**
     * Process a group name and return course configuration.
     *
     * @param string $group_name Keycloak group name.
     * @param string $group_id Keycloak group ID.
     * @return array|null Course configuration or null if no match/ignored.
     */
    public function process(string $group_name, string $group_id = ''): ?array
    {
        $schema = $this->find_schema($group_name);
        if ($schema === null) {
            return null;
        }

        $groups = $schema->extract($group_name);
        if ($groups === null) {
            return null;
        }

        return [
            'schema_id' => $schema->get_id(),
            'group_name' => $group_name,
            'group_id' => $group_id,
            'captured_groups' => $groups,
            'course_fullname' => $schema->generate_course_name($groups, $this->transformer),
            'course_shortname' => $schema->generate_course_shortname($groups, $this->transformer),
            'category_path' => $schema->generate_category_path($groups, $this->transformer),
            'course_idnumber' => $schema->generate_idnumber($group_name),
            'role_map' => $schema->get_role_map(),
            'defaults' => $this->defaults,
        ];
    }

    /**
     * Process multiple groups and return statistics.
     *
     * @param array $groups Array of groups with 'name' and 'id' keys.
     * @return array Results with 'matched', 'ignored', 'unmatched' arrays.
     */
    public function process_all(array $groups): array
    {
        $results = [
            'matched' => [],
            'ignored' => [],
            'unmatched' => [],
        ];

        foreach ($groups as $group) {
            $name = $group['name'] ?? '';
            $id = $group['id'] ?? '';

            if ($this->should_ignore($name)) {
                $results['ignored'][] = $name;
                continue;
            }

            $processed = $this->process($name, $id);
            if ($processed !== null) {
                $results['matched'][] = $processed;
            } else {
                $results['unmatched'][] = $name;
            }
        }

        return $results;
    }

    /**
     * Get all schemas.
     *
     * @return naming_schema[] Array of schemas.
     */
    public function get_schemas(): array
    {
        return $this->schemas;
    }

    /**
     * Get configuration source.
     *
     * @return string Source identifier.
     */
    public function get_config_source(): string
    {
        return $this->config_source;
    }

    /**
     * Get transformer instance.
     *
     * @return template_transformer Transformer.
     */
    public function get_transformer(): template_transformer
    {
        return $this->transformer;
    }

    /**
     * Get default configuration for German schools.
     *
     * This provides sensible defaults for German schools.
     *
     * @return array Default configuration.
     */
    public static function get_german_school_defaults(): array
    {
        return [
            'version' => '1.0',
            'description' => 'Standard-Schemas für deutsche Schulen',
            'defaults' => [
                'course_format' => 'topics',
                'num_sections' => 10,
                'visible' => true,
            ],
            'schemas' => [
                [
                    'id' => 'fachschaft',
                    'description' => 'Fachschaften (alle Lehrer eines Fachs)',
                    'priority' => 10,
                    'pattern' => '^p_alle[_-](?P<fach>[a-zA-Z0-9_-]+)$',
                    'course_name' => 'Fachschaft {fach|map:subject_map}',
                    'course_shortname' => 'FS_{fach|upper}',
                    'category_path' => 'Fachschaften',
                    'course_idnumber_prefix' => 'kc_fachschaft_',
                    'role_map' => ['default' => 'editingteacher'],
                ],
                [
                    'id' => 'lehrer_fach_stufe',
                    'description' => 'Lehrerkurse (Lehrer_Fach_Stufe)',
                    'priority' => 20,
                    'pattern' => '^p_(?P<lehrer>[a-z]{2,6})_(?P<fach>[a-zA-Z]+)_(?P<stufe>\\d{1,2}[a-z]?)$',
                    'course_name' => '{fach|map:subject_map} Klasse {stufe|upper} ({lehrer|upper})',
                    'course_shortname' => '{lehrer|upper}_{fach|upper}_{stufe|upper}',
                    'category_path' => 'Kurse/Stufe {stufe|extract_grade}',
                    'course_idnumber_prefix' => 'kc_lehrer_',
                    'role_map' => ['default' => 'student', 'teacher' => 'editingteacher'],
                ],
                [
                    'id' => 'klasse_fach',
                    'description' => 'Klassenkurse (Klasse_Fach)',
                    'priority' => 30,
                    'pattern' => '^p_(?P<klasse>\\d{1,2}[a-z])_(?P<fach>[a-zA-Z]+)$',
                    'course_name' => '{fach|map:subject_map} {klasse|upper}',
                    'course_shortname' => '{klasse|upper}_{fach|upper}',
                    'category_path' => 'Klassen/Stufe {klasse|extract_grade}',
                    'course_idnumber_prefix' => 'kc_klasse_',
                    'role_map' => ['default' => 'student', 'teacher' => 'editingteacher'],
                ],
                [
                    'id' => 'ag',
                    'description' => 'AGs (Arbeitsgemeinschaften)',
                    'priority' => 40,
                    'pattern' => '^p_(?P<name>[a-zA-Z0-9_-]+)[_-]ag$',
                    'course_name' => 'AG: {name|titlecase}',
                    'course_shortname' => 'AG_{name|upper|truncate:20}',
                    'category_path' => 'AGs',
                    'course_idnumber_prefix' => 'kc_ag_',
                    'role_map' => ['default' => 'student', 'teacher' => 'editingteacher'],
                ],
                [
                    'id' => 'klasse_students',
                    'description' => 'Klassengruppen (-students)',
                    'priority' => 50,
                    'pattern' => '^(?P<klasse>\\d{1,2}[a-z]?)-students$',
                    'course_name' => 'Klasse {klasse|upper}',
                    'course_shortname' => 'K_{klasse|upper}',
                    'category_path' => 'Klassen/Stufe {klasse|extract_grade}',
                    'course_idnumber_prefix' => 'kc_',
                    'role_map' => ['default' => 'student', 'teacher' => 'editingteacher'],
                ],
                [
                    'id' => 'kursstufe',
                    'description' => 'Kursstufe (K1/K2/J1/J2)',
                    'priority' => 55,
                    'pattern' => '^(?P<stufe>k[12s]\\d?|j[12]|1[12])-students$',
                    'course_name' => 'Kursstufe {stufe|upper}',
                    'course_shortname' => 'KS_{stufe|upper}',
                    'category_path' => 'Klassen/Kursstufe',
                    'course_idnumber_prefix' => 'kc_ks_',
                    'role_map' => ['default' => 'student', 'teacher' => 'editingteacher'],
                ],
                [
                    'id' => 'projekt_default',
                    'description' => 'Projekte (alle p_ Gruppen)',
                    'priority' => 100,
                    'pattern' => '^p_(?P<name>.+)$',
                    'course_name' => 'Projekt: {name|titlecase}',
                    'course_shortname' => 'P_{name|upper|clean|truncate:25}',
                    'category_path' => 'Projekte',
                    'course_idnumber_prefix' => 'kc_project_',
                    'role_map' => ['default' => 'student', 'teacher' => 'editingteacher'],
                ],
            ],
            'ignore_patterns' => [
                '^_internal_',
                '-parents$',
                '-eltern$',
                '^test_',
                '^debug_',
            ],
            'transformers' => [
                'subject_map' => [
                    'bio' => 'Biologie',
                    'biologie' => 'Biologie',
                    'm' => 'Mathematik',
                    'ma' => 'Mathematik',
                    'mathe' => 'Mathematik',
                    'math' => 'Mathematik',
                    'd' => 'Deutsch',
                    'de' => 'Deutsch',
                    'deutsch' => 'Deutsch',
                    'e' => 'Englisch',
                    'en' => 'Englisch',
                    'eng' => 'Englisch',
                    'englisch' => 'Englisch',
                    'f' => 'Französisch',
                    'fr' => 'Französisch',
                    'franz' => 'Französisch',
                    'l' => 'Latein',
                    'la' => 'Latein',
                    'lat' => 'Latein',
                    'latein' => 'Latein',
                    'g' => 'Geschichte',
                    'ge' => 'Geschichte',
                    'gesch' => 'Geschichte',
                    'geo' => 'Geografie',
                    'ek' => 'Erdkunde',
                    'ph' => 'Physik',
                    'phy' => 'Physik',
                    'physik' => 'Physik',
                    'ch' => 'Chemie',
                    'chem' => 'Chemie',
                    'chemie' => 'Chemie',
                    'mus' => 'Musik',
                    'mu' => 'Musik',
                    'musik' => 'Musik',
                    'bk' => 'Bildende Kunst',
                    'ku' => 'Kunst',
                    'kunst' => 'Kunst',
                    'spo' => 'Sport',
                    'sp' => 'Sport',
                    'sport' => 'Sport',
                    'eth' => 'Ethik',
                    'ethik' => 'Ethik',
                    'rel' => 'Religion',
                    'evrel' => 'Ev. Religion',
                    'krel' => 'Kath. Religion',
                    'spa' => 'Spanisch',
                    'spanisch' => 'Spanisch',
                    'rus' => 'Russisch',
                    'russisch' => 'Russisch',
                    'nwt' => 'NwT',
                    'bnt' => 'BNT',
                    'gk' => 'Gemeinschaftskunde',
                    'wbs' => 'WBS',
                    'inf' => 'Informatik',
                    'it' => 'Informatik',
                    'informatik' => 'Informatik',
                    'lehrer' => 'Alle Lehrer',
                ],
            ],
        ];
    }

    /**
     * Export current configuration as JSON.
     *
     * @return string JSON configuration.
     */
    public function export_config(): string
    {
        $config = [
            'version' => '1.0',
            'defaults' => $this->defaults,
            'schemas' => [],
            'ignore_patterns' => $this->ignore_patterns,
            'transformers' => $this->transformer->get_maps(),
        ];

        foreach ($this->schemas as $schema) {
            $config['schemas'][] = $schema->export();
        }

        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
