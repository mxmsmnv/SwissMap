<?php namespace ProcessWire;

/**
 * InputfieldLV95
 *
 * Admin input for Swiss LV95 coordinate pair.
 * Shows two number inputs (E / N) and a small inline SVG preview dot.
 *
 */
class InputfieldLV95 extends Inputfield {

    public static function getModuleInfo(): array {
        return [
            'title'    => 'LV95 Swiss Coordinates Input',
            'version'  => 100,
            'summary'  => 'Input for Swiss LV95 Easting/Northing coordinate pair with inline map preview.',
            'requires' => ['FieldtypeLV95'],
        ];
    }

    /** Loaded translations for the current language */
    protected array $lang = [];

    public function init(): void {
        parent::init();
        $this->attr('value', ['e' => 0, 'n' => 0]);
        $this->loadLanguage();
    }

    /**
     * Load a translations JSON file based on the current ProcessWire language.
     * Supported language codes: en, de, fr, it, rm.
     * Falls back to English if no match is found.
     */
    protected function loadLanguage(): void {
        $code = 'en';
        $languages = $this->wire('languages');
        if ($languages) {
            $lang = $this->wire('user')->language;
            if ($lang && $lang->id) {
                $name = strtolower($lang->name); // e.g. "default", "de", "fr", ...
                // PW default language is always English
                if ($name === 'default') {
                    $code = 'en';
                } elseif (in_array($name, ['de', 'fr', 'it', 'rm'])) {
                    $code = $name;
                }
            }
        }
        $file = __DIR__ . "/languages/{$code}.json";
        if (!is_file($file)) $file = __DIR__ . '/languages/en.json';
        $data = json_decode(file_get_contents($file), true);
        $this->lang = is_array($data) ? $data : [];
    }

    /**
     * Translate a string using the loaded language file,
     * falling back to PW built-in _() translation system.
     */
    protected function t(string $key): string {
        if (isset($this->lang[$key])) return $this->lang[$key];
        return $this->_($key);
    }

    public function ___render(): string {
        $value = $this->attr('value');
        $e     = ($value['e'] ?? 0) ? number_format((float)$value['e'], 2, '.', '') : '';
        $n     = ($value['n'] ?? 0) ? number_format((float)$value['n'], 2, '.', '') : '';
        $name  = $this->attr('name');

        $labelE = $this->t('Easting (E)');
        $labelN = $this->t('Northing (N)');
        $hint   = $this->t("Valid range: E 2'485'000 \u2013 2'834'000 / N 1'075'000 \u2013 1'296'000");

        // Inline styles — AdminTheme CSS loads after module CSS and would override classes
        $out  = "<style>
            .lv95-wrap { display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap; }
            .lv95-field label { display:block; font-size:12px; font-weight:600; margin-bottom:4px; color:#555; }
            .lv95-field input { width:160px; }
            .lv95-hint { font-size:11px; color:#888; margin-top:6px; }
            .lv95-preview-wrap { flex:1; min-width:220px; }
            .lv95-preview-wrap svg { width:100%; max-width:280px; display:block; border:1px solid #ddd; border-radius:4px; background:#f4f6f8; }
            .lv95-preview-dot { fill:#e74c3c; }
        </style>";

        $out .= "<div class='lv95-wrap'>";

        // E input — type=text: accepts apostrophe-formatted values like 2'682'190.75
        $out .= "<div class='lv95-field'>
            <label for='{$name}_e'>{$labelE}</label>
            <input type='text' id='{$name}_e' name='{$name}[e]' value='{$e}'
                   class='uk-input' placeholder=\"2'682'190.75\" />
        </div>";

        // N input — type=text: accepts apostrophe-formatted values like 1'223'152.79
        $out .= "<div class='lv95-field'>
            <label for='{$name}_n'>{$labelN}</label>
            <input type='text' id='{$name}_n' name='{$name}[n]' value='{$n}'
                   class='uk-input' placeholder=\"1'223'152.79\" />
        </div>";

        // Mini SVG preview
        $svgPath = $this->getSwitzerlandPath();
        $out .= "<div class='lv95-preview-wrap'>
            <svg id='{$name}_preview' viewBox='0 0 280 180' xmlns='http://www.w3.org/2000/svg'
                 data-name='{$name}'>
                <path d='{$svgPath}' fill='#cde' stroke='#89a' stroke-width='1'/>
                <circle id='{$name}_dot' class='lv95-preview-dot' r='5' cx='-100' cy='-100'/>
            </svg>
        </div>";

        $out .= "</div>";
        $out .= "<p class='lv95-hint'>{$hint}</p>";

        // Inline JS for live preview dot
        $out .= $this->renderJS($name);

        return $out;
    }

    public function ___processInput(WireInputData $input): self {
        $name = $this->attr('name');

        // WireInputData doesn't handle nested array keys — read from $_POST directly
        $raw = isset($_POST[$name]) && is_array($_POST[$name]) ? $_POST[$name] : [];

        $e = isset($raw['e']) ? (float) str_replace("'", '', $raw['e']) : 0.0;
        $n = isset($raw['n']) ? (float) str_replace("'", '', $raw['n']) : 0.0;

        $new = ['e' => $e, 'n' => $n];
        $old = $this->attr('value');

        if ($new !== $old) {
            $this->attr('value', $new);
            $this->trackChange('value');
        }

        return $this;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** LV95 → SVG pixel using viewBox 0 0 280 180 */
    private function lv95ToPixel(int $e, int $n): array {
        $eMin = 2485000; $eMax = 2834000;
        $nMin = 1075000; $nMax = 1296000;
        $x = round(($e - $eMin) / ($eMax - $eMin) * 280, 2);
        $y = round((1 - ($n - $nMin) / ($nMax - $nMin)) * 180, 2);
        return [$x, $y];
    }

    private function renderJS(string $name): string {
        return "<script>
        (function(){
            function lv95ToPixel(e, n) {
                var eMin=2485000, eMax=2834000, nMin=1075000, nMax=1296000;
                return {
                    x: (e - eMin) / (eMax - eMin) * 280,
                    y: (1 - (n - nMin) / (nMax - nMin)) * 180
                };
            }
            function updatePreview_{$name}() {
                var eEl  = document.getElementById('{$name}_e');
                var nEl  = document.getElementById('{$name}_n');
                var dot  = document.getElementById('{$name}_dot');
                if (!eEl || !nEl || !dot) return;
                var e = parseFloat(eEl.value.replace(/'/g,''))||0;
                var n = parseFloat(nEl.value.replace(/'/g,''))||0;
                if (e < 2485000 || e > 2834000 || n < 1075000 || n > 1296000) {
                    dot.setAttribute('cx', -100);
                    dot.setAttribute('cy', -100);
                    return;
                }
                var p = lv95ToPixel(e, n);
                dot.setAttribute('cx', p.x);
                dot.setAttribute('cy', p.y);
            }
            // Attach to inputs
            document.addEventListener('DOMContentLoaded', function(){
                var eEl = document.getElementById('{$name}_e');
                var nEl = document.getElementById('{$name}_n');
                if (eEl) { eEl.addEventListener('input', updatePreview_{$name}); updatePreview_{$name}(); }
                if (nEl) nEl.addEventListener('input', updatePreview_{$name});
            });
        })();
        </script>";
    }

    /**
     * Simplified SVG path of Switzerland outline for the mini preview.
     * Coordinates pre-mapped to viewBox 0 0 280 180.
     */
    private function getSwitzerlandPath(): string {
        // Simplified outline path of Switzerland (LV95-derived, scaled to 280x180 viewBox)
        return "M 52,112 L 58,105 L 67,100 L 75,95 L 82,88 L 90,82 L 100,78 L 110,72 L 120,68
                L 130,65 L 140,62 L 150,60 L 162,58 L 172,55 L 182,54 L 192,52 L 202,50
                L 212,48 L 220,47 L 228,48 L 232,52 L 235,58 L 238,65 L 240,72 L 238,80
                L 235,88 L 230,95 L 224,102 L 218,108 L 210,114 L 200,120 L 190,126
                L 178,130 L 166,133 L 154,135 L 142,136 L 130,135 L 118,133 L 106,130
                L 96,126 L 86,122 L 76,118 L 66,115 L 58,113 Z";
    }
}
