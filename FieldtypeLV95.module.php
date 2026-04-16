<?php namespace ProcessWire;

/**
 * FieldtypeLV95
 *
 * Stores Swiss LV95 coordinates (Easting / Northing) for a ProcessWire page.
 * LV95 valid ranges: E 2'485'000–2'834'000 / N 1'075'000–1'296'000
 *
 */
class FieldtypeLV95 extends Fieldtype {

    public static function getModuleInfo(): array {
        return [
            'title'    => 'LV95 Swiss Coordinates',
            'version'  => 100,
            'summary'  => 'Stores Swiss LV95 coordinate pair (Easting / Northing). Used with SwissMap to render SVG map markers.',
            'requires' => ['ProcessWire>=3.0.0'],
        ];
    }

    /** Strip apostrophe thousands-separators and cast to float */
    protected function parseCoord($raw): float {
        return (float) str_replace("'", '', (string) $raw);
    }

    /** Return a blank/default value */
    public function getBlankValue(Page $page, Field $field): array {
        return ['e' => 0.0, 'n' => 0.0];
    }

    /** Sanitize before setting on page */
    public function sanitizeValue(Page $page, Field $field, $value): array {
        if (!is_array($value)) $value = ['e' => 0.0, 'n' => 0.0];
        return [
            'e' => $this->parseCoord($value['e'] ?? 0),
            'n' => $this->parseCoord($value['n'] ?? 0),
        ];
    }

    /** Load from DB row */
    public function ___wakeupValue(Page $page, Field $field, $value): array {
        return [
            'e' => (float) ($value['data']  ?? 0),
            'n' => (float) ($value['data2'] ?? 0),
        ];
    }

    /** Prepare for DB save */
    public function ___sleepValue(Page $page, Field $field, $value): array {
        return [
            'data'  => $this->parseCoord($value['e'] ?? 0),
            'data2' => $this->parseCoord($value['n'] ?? 0),
        ];
    }

    /** DB schema: two DECIMAL(12,2) — supports apostrophe-formatted LV95 floats */
    public function getDatabaseSchema(Field $field): array {
        $schema          = parent::getDatabaseSchema($field);
        $schema['data']  = 'DECIMAL(12,2) NOT NULL DEFAULT 0.00';  // Easting
        $schema['data2'] = 'DECIMAL(12,2) NOT NULL DEFAULT 0.00';  // Northing
        $schema['keys']['data'] = 'KEY data (data)';
        return $schema;
    }

    /** Which Inputfield renders this type */
    public function getInputfield(Page $page, Field $field): InputfieldLV95 {
        /** @var InputfieldLV95 $f */
        $f = $this->modules->get('InputfieldLV95');
        return $f;
    }

    /** Allow casting to string: "E 2600123 / N 1199456" */
    public function ___exportValue(Page $page, Field $field, $value, array $options = []): string {
        if (empty($value['e']) && empty($value['n'])) return '';
        return "E {$value['e']} / N {$value['n']}";
    }
}
