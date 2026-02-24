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
 * Template variable transformation engine.
 *
 * Processes template strings with variable placeholders and applies
 * pipe-based transformations like {fach|ucfirst} or {name|upper|truncate:20}.
 *
 * @package    local_edulution
 * @copyright  2024 Edulution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edulution\sync;

defined('MOODLE_INTERNAL') || die();

/**
 * Template transformer class.
 */
class template_transformer {

    /** @var array Custom transformer maps (e.g., subject abbreviation expansion) */
    protected array $maps = [];

    /**
     * Constructor.
     *
     * @param array $maps Custom transformer maps.
     */
    public function __construct(array $maps = []) {
        $this->maps = $maps;
    }

    /**
     * Apply template transformations.
     *
     * Template syntax: {variable|transformer1|transformer2:arg1:arg2}
     *
     * @param string $template Template string.
     * @param array $variables Named variables to substitute.
     * @return string Transformed result.
     */
    public function apply(string $template, array $variables): string {
        // Pattern: {variablename} or {variablename|transform1|transform2:arg}
        $pattern = '/\{([a-zA-Z_][a-zA-Z0-9_]*)(\|[^}]+)?\}/u';

        return preg_replace_callback($pattern, function ($match) use ($variables) {
            $var_name = $match[1];
            $transformers = isset($match[2]) ? substr($match[2], 1) : '';

            // Get the variable value.
            $value = $variables[$var_name] ?? '';

            // Apply transformers if any.
            if (!empty($transformers)) {
                $value = $this->apply_transformers($value, $transformers);
            }

            return $value;
        }, $template);
    }

    /**
     * Apply a chain of transformers to a value.
     *
     * @param string $value The value to transform.
     * @param string $transformers Pipe-separated transformer chain.
     * @return string Transformed value.
     */
    protected function apply_transformers(string $value, string $transformers): string {
        $parts = explode('|', $transformers);

        foreach ($parts as $transformer) {
            $value = $this->apply_single_transformer($value, trim($transformer));
        }

        return $value;
    }

    /**
     * Apply a single transformer.
     *
     * @param string $value The value to transform.
     * @param string $transformer Transformer name with optional args.
     * @return string Transformed value.
     */
    protected function apply_single_transformer(string $value, string $transformer): string {
        // Parse transformer and arguments (format: name:arg1:arg2).
        $parts = explode(':', $transformer);
        $name = array_shift($parts);
        $args = $parts;

        switch ($name) {
            case 'upper':
                return mb_strtoupper($value);

            case 'lower':
                return mb_strtolower($value);

            case 'ucfirst':
                return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);

            case 'titlecase':
                // Convert underscores/hyphens to spaces, then title case.
                $text = str_replace(['_', '-'], ' ', $value);
                return mb_convert_case($text, MB_CASE_TITLE);

            case 'replace':
                // Replace first arg with second arg.
                if (count($args) >= 2) {
                    return str_replace($args[0], $args[1], $value);
                }
                return $value;

            case 'truncate':
                $length = isset($args[0]) ? (int) $args[0] : 50;
                if (mb_strlen($value) > $length) {
                    return mb_substr($value, 0, $length - 3) . '...';
                }
                return $value;

            case 'extract_grade':
                // Extract numeric grade from class name (e.g., "10a" -> "10").
                if (preg_match('/^(\d+)/', $value, $m)) {
                    return $m[1];
                }
                return $value;

            case 'map':
                // Use a named transformer map.
                $map_name = $args[0] ?? 'subject_map';
                return $this->apply_map($value, $map_name);

            case 'default':
                // Return default value if empty.
                if (empty($value) && !empty($args[0])) {
                    return $args[0];
                }
                return $value;

            case 'clean':
                // Remove non-alphanumeric characters.
                return preg_replace('/[^a-zA-Z0-9_-]/', '', $value);

            case 'slug':
                // Create URL-safe slug.
                $slug = mb_strtolower($value);
                $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
                return trim($slug, '-');

            case 'pad':
                // Pad with zeros (for class numbers).
                $length = isset($args[0]) ? (int) $args[0] : 2;
                return str_pad($value, $length, '0', STR_PAD_LEFT);

            default:
                return $value;
        }
    }

    /**
     * Apply a map lookup with fallback.
     *
     * @param string $value Value to look up.
     * @param string $map_name Map name.
     * @return string Mapped value or ucfirst original.
     */
    protected function apply_map(string $value, string $map_name): string {
        if (isset($this->maps[$map_name][$value])) {
            return $this->maps[$map_name][$value];
        }

        // Try lowercase lookup.
        $lower = mb_strtolower($value);
        if (isset($this->maps[$map_name][$lower])) {
            return $this->maps[$map_name][$lower];
        }

        // Return original with ucfirst as fallback.
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }

    /**
     * Register a custom transformer map.
     *
     * @param string $name Map name.
     * @param array $map Key-value mapping.
     */
    public function register_map(string $name, array $map): void {
        $this->maps[$name] = $map;
    }

    /**
     * Get all registered maps.
     *
     * @return array All transformer maps.
     */
    public function get_maps(): array {
        return $this->maps;
    }

    /**
     * Get available transformer documentation.
     *
     * @return array List of transformers with descriptions.
     */
    public static function get_transformer_docs(): array {
        return [
            'upper' => 'Großbuchstaben: {name|upper} → "BIOLOGIE"',
            'lower' => 'Kleinbuchstaben: {name|lower} → "biologie"',
            'ucfirst' => 'Erster Buchstabe groß: {name|ucfirst} → "Biologie"',
            'titlecase' => 'Titelschreibung: {name|titlecase} → "Alle Biologie"',
            'replace:von:nach' => 'Ersetzen: {name|replace:_: } → "alle biologie"',
            'truncate:n' => 'Kürzen auf n Zeichen: {name|truncate:10}',
            'extract_grade' => 'Klassenstufe extrahieren: {klasse|extract_grade} → "10" aus "10a"',
            'map:mapname' => 'Wert nachschlagen: {fach|map:subject_map} → "Biologie" aus "bio"',
            'default:wert' => 'Standardwert wenn leer: {var|default:Unbekannt}',
            'clean' => 'Nur Buchstaben/Zahlen: {name|clean}',
            'slug' => 'URL-freundlich: {name|slug}',
            'pad:n' => 'Mit Nullen auffüllen: {num|pad:2} → "05"',
        ];
    }
}
