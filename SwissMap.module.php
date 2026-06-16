<?php namespace ProcessWire;

/**
 * SwissMap
 *
 * Renders a map of Switzerland with LV95 location markers.
 * Two drivers available:
 *   - 'svg'     — self-contained SVG, zero external requests, GDPR-safe (default)
 *   - 'leaflet' — interactive Leaflet map with official swisstopo tiles (CH servers, CC BY 4.0)
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org)
 * @link    https://github.com/mxmsmnv/SwissMap
 * @license MIT
 *
 * Usage in template:
 *
 *   $map = $modules->get('SwissMap');
 *
 *   // SVG (default, no external requests)
 *   echo $map->render($pages->find('template=location, lv95.e>0'));
 *
 *   // Interactive swisstopo map
 *   echo $map->render($pages->find('template=location, lv95.e>0'), [
 *       'driver' => 'leaflet',
 *       'height' => 500,
 *       'zoom'   => 9,
 *   ]);
 *
 */
class SwissMap extends WireData implements Module {

    public static function getModuleInfo(): array {
        return [
            'title'    => 'SwissMap — Swiss SVG Map',
            'version'  => 100,
            'summary'  => 'Renders a map of Switzerland with LV95 coordinate markers. SVG driver (zero external requests) or interactive Leaflet driver with official swisstopo tiles.',
            'requires' => ['FieldtypeLV95'],
            'icon'     => 'map-marker',
            'autoload' => false,
        ];
    }

    /** Default render options */
    protected array $defaults = [
        // --- driver ---
        'driver'       => 'svg',      // 'svg' | 'leaflet'

        // --- shared ---
        'coordField'   => 'lv95',     // Field name of type FieldtypeLV95
        'labelField'   => 'title',    // Page field to use as marker label
        'linkField'    => 'url',      // Page field/property for href, or false
        'colorField'   => '',         // Optional field name for per-marker hex color
        'markerColor'  => '#e74c3c',  // Default marker fill
        'cssClass'     => 'swissmap', // CSS class on the root element

        // --- SVG driver ---
        'width'        => 700,        // SVG width in px (height auto 700:453 ratio)
        'markerRadius' => 7,          // Marker circle radius
        'tooltip'      => true,       // Show tooltip on hover
        'mapFill'      => '#dde9f4',  // Canton fill color
        'mapStroke'    => '#8aafc4',  // Canton border color
        'background'   => 'none',     // SVG background

        // --- Leaflet driver ---
        'height'       => 500,        // Map container height in px
        'zoom'         => 9,          // Initial zoom level (1–18)
        'tileLayer'    => 'ch.swisstopo.pixelkarte-farbe', // swisstopo XYZ (free, no registration needed)
        'attribution'  => true,       // Show swisstopo attribution (required by CC BY 4.0)
        'clustering'   => false,      // Group nearby markers (requires leaflet.markercluster)
        'popupField'   => '',         // Page field for popup body text (empty = label only)
    ];

    // LV95 bounding box for Switzerland
    const E_MIN = 2485000;
    const E_MAX = 2834000;
    const N_MIN = 1075000;
    const N_MAX = 1296000;

    // Internal SVG canvas size (scale down with CSS/width attr)
    const VB_W = 700;
    const VB_H = 453;

    // ---------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------

    /**
     * Render SVG map with markers.
     *
     * @param PageArray|array $locations  Pages with LV95 field, or plain array of ['e'=>…,'n'=>…,'label'=>…,'url'=>…]
     * @param array           $options    Override defaults
     */
    public function render($locations = [], array $options = []): string {
        $opt = array_merge($this->defaults, $options);

        $markers = $this->buildMarkers($locations, $opt);

        if ($opt['driver'] === 'leaflet') {
            return $this->renderLeaflet($markers, $opt);
        }

        // Default: SVG driver
        $svg = $this->renderSVG($markers, $opt);
        if ($opt['tooltip']) {
            $svg .= $this->renderTooltipJS($opt['cssClass']);
        }
        return $svg;
    }

    /**
     * Convert LV95 coordinates to SVG pixel position.
     */
    public function lv95ToSvg(float $e, float $n): array {
        $x = ($e - self::E_MIN) / (self::E_MAX - self::E_MIN) * self::VB_W;
        $y = (1 - ($n - self::N_MIN) / (self::N_MAX - self::N_MIN)) * self::VB_H;
        return [round($x, 2), round($y, 2)];
    }

    // ---------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------

    private function buildMarkers($locations, array $opt): array {
        $markers = [];

        // Accept plain arrays (for non-PW use)
        if (is_array($locations) && isset($locations[0]) && is_array($locations[0])) {
            foreach ($locations as $loc) {
                $e = (float) ($loc['e'] ?? 0);
                $n = (float) ($loc['n'] ?? 0);
                if (!$this->validCoords($e, $n)) continue;
                [$x, $y] = $this->lv95ToSvg($e, $n);
                $markers[] = [
                    'x'     => $x,
                    'y'     => $y,
                    'e'     => $e,
                    'n'     => $n,
                    'label' => $loc['label'] ?? '',
                    'url'   => $loc['url']   ?? '',
                    'color' => $loc['color'] ?? $opt['markerColor'],
                    'popup' => $loc['popup'] ?? '',
                ];
            }
            return $markers;
        }

        // PageArray / iterable
        foreach ($locations as $page) {
            /** @var Page $page */
            $coordField = $opt['coordField'];
            $coords     = $page->get($coordField);

            if (!is_array($coords)) continue;

            $e = (float) ($coords['e'] ?? 0);
            $n = (float) ($coords['n'] ?? 0);
            if (!$this->validCoords($e, $n)) continue;

            [$x, $y] = $this->lv95ToSvg($e, $n);

            // Label
            $label = '';
            if ($opt['labelField']) {
                $label = (string) $page->get($opt['labelField']);
            }

            // URL
            $url = '';
            if ($opt['linkField'] === 'url') {
                $url = $page->url;
            } elseif ($opt['linkField']) {
                $url = (string) $page->get($opt['linkField']);
            }

            // Per-marker color
            $color = $opt['markerColor'];
            if ($opt['colorField'] && $page->get($opt['colorField'])) {
                $color = (string) $page->get($opt['colorField']);
            }

            // Popup body text
            $popup = '';
            if ($opt['popupField'] && $page->get($opt['popupField'])) {
                $popup = (string) $page->get($opt['popupField']);
            }

            $markers[] = compact('x', 'y', 'e', 'n', 'label', 'url', 'color', 'popup');
        }

        return $markers;
    }

    private function validCoords(float $e, float $n): bool {
        return $e >= self::E_MIN && $e <= self::E_MAX
            && $n >= self::N_MIN && $n <= self::N_MAX;
    }

    // ---------------------------------------------------------------
    // Leaflet driver
    // ---------------------------------------------------------------

    /**
     * Render an interactive Leaflet map.
     * tileLayer 'osm'            → OpenStreetMap (global)
     * tileLayer 'ch.swisstopo.*' → swisstopo WMTS EPSG:3857 (CH servers, CC BY 4.0)
     */
    private function renderLeaflet(array $markers, array $opt): string {
        $id      = $this->wire('sanitizer')->entities($opt['cssClass']) . '_' . mt_rand(1000, 9999);
        $h       = (int) $opt['height'];
        $zoom    = (int) $opt['zoom'];
        $cluster = $opt['clustering'] ? 'true' : 'false';

        // Build JS markers array
        $jsMarkers = [];
        foreach ($markers as $m) {
            // Convert LV95 → WGS84 (approximate, good to ~1 m)
            [$lat, $lng] = $this->lv95ToWgs84($m['e'], $m['n']);
            $label = $this->wire('sanitizer')->entities($m['label']);
            $url   = $this->wire('sanitizer')->entities($m['url']);
            $color = $this->wire('sanitizer')->entities($m['color']);
            $popup = $this->wire('sanitizer')->entities($m['popup'] ?? '');
            $jsMarkers[] = json_encode(compact('lat', 'lng', 'label', 'url', 'color', 'popup'));
        }

        // Compute initial center: centroid of markers or Switzerland center
        if (count($markers) > 0) {
            [$cLat, $cLng] = $this->lv95ToWgs84(
                array_sum(array_column($markers, 'e')) / count($markers),
                array_sum(array_column($markers, 'n')) / count($markers)
            );
        } else {
            $cLat = 46.8182; $cLng = 8.2275; // Geographic center of Switzerland
        }

        $markersJson  = '[' . implode(',', $jsMarkers) . ']';

        // Resolve tile URL and attribution based on tileLayer value
        $tileLayerVal = $opt['tileLayer'];
        if ($tileLayerVal === 'osm') {
            $tileUrl      = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
            $tileOptions  = json_encode([
                'attribution' => $opt['attribution'] ? '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors' : '',
                'maxZoom'     => 19,
                'subdomains'  => 'abc',
                'crossOrigin' => false,
            ]);
        } else {
            // swisstopo WMTS correct GetTile URL format (EPSG:3857):
            // https://docs.geo.admin.ch/visualize-data/wmts.html
            // Pattern: /1.0.0/{Layer}/default/current/{TileMatrixSet}/{z}/{x}/{y}.jpeg
            $tileUrl      = "https://wmts.geo.admin.ch/1.0.0/{$tileLayerVal}/default/current/3857/{z}/{x}/{y}.jpeg";
            $tileOptions  = json_encode([
                'attribution' => $opt['attribution'] ? '&copy; <a href="https://www.swisstopo.admin.ch" target="_blank">swisstopo</a> CC BY 4.0' : '',
                'maxZoom'     => 18,
                'minZoom'     => 2,
                'crossOrigin' => false,
            ]);
        }

        $out = <<<HTML
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<div id="{$id}" style="width:100%;height:{$h}px;"></div>
<script>
(function(){
    var markers = {$markersJson};
    var map = L.map('{$id}').setView([{$cLat}, {$cLng}], {$zoom});

    L.tileLayer('{$tileUrl}', {$tileOptions}).addTo(map);

    // Add markers
    markers.forEach(function(m) {
        var icon = L.divIcon({
            className: '',
            html: '<div style="width:14px;height:14px;border-radius:50%;background:' + m.color + ';border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.4);"></div>',
            iconSize: [14, 14],
            iconAnchor: [7, 7],
            popupAnchor: [0, -10]
        });

        var marker = L.marker([m.lat, m.lng], {icon: icon});

        if (m.label || m.popup) {
            var content = m.label
                ? (m.url ? '<a href="' + m.url + '">' + m.label + '</a>' : m.label)
                : '';
            if (m.popup) content += (content ? '<br>' : '') + m.popup;
            marker.bindPopup(content);
        }

        marker.addTo(map);
    });

    // Fit bounds to markers if more than one
    if (markers.length > 1) {
        var latlngs = markers.map(function(m){ return [m.lat, m.lng]; });
        map.fitBounds(latlngs, {padding: [30, 30]});
    }
})();
</script>
HTML;

        return $out;
    }

    /**
     * Convert LV95 (CH1903+) to WGS84 (lat/lng).
     * Approximate Helmert transformation, accuracy ~1 m.
     * Reference: swisstopo "Approximate formulas for the transformation
     * between Swiss projection coordinates and WGS84"
     * https://www.swisstopo.admin.ch/content/swisstopo-internet/en/topics/survey/reference-systems/switzerland/_jcr_content/contentPar/tabs/items/dokumente_und_publi/tabPar/downloadlist/downloadItems/517_1459343190376.download/ch1903wgs84_e.pdf
     */
    public function lv95ToWgs84(float $e, float $n): array {
        // Auxiliary values (units: 1000 km)
        $y = ($e - 2600000) / 1000000;
        $x = ($n - 1200000) / 1000000;

        // Longitude (degrees)
        $lngDeg = 2.6779094
            + 4.728982  * $y
            + 0.791484  * $y * $x
            + 0.1306    * $y * $x * $x
            - 0.0436    * $y * $y * $y;
        $lng = $lngDeg * 100 / 36;

        // Latitude (degrees)
        $latDeg = 16.9023892
            + 3.238272  * $x
            - 0.270978  * $y * $y
            - 0.002528  * $x * $x
            - 0.0447    * $y * $y * $x
            - 0.0140    * $x * $x * $x;
        $lat = $latDeg * 100 / 36;

        return [round($lat, 6), round($lng, 6)];
    }

    // ---------------------------------------------------------------
    // SVG driver
    // ---------------------------------------------------------------

    private function renderSVG(array $markers, array $opt): string {
        $w       = (int) $opt['width'];
        $class   = $this->wire('sanitizer')->entities($opt['cssClass']);
        $fill    = $this->wire('sanitizer')->entities($opt['mapFill']);
        $stroke  = $this->wire('sanitizer')->entities($opt['mapStroke']);
        $bg      = $this->wire('sanitizer')->entities($opt['background']);
        $r       = (int) $opt['markerRadius'];

        $out  = "<svg class='{$class}' width='{$w}' viewBox='0 0 " . self::VB_W . " " . self::VB_H . "' ";
        $out .= "xmlns='http://www.w3.org/2000/svg' style='background:{$bg};max-width:100%;height:auto;'>";

        // Inline CSS
        $out .= "<style>
            .{$class} { display:block; }
            .{$class} .ch-region { transition: fill .15s; }
            .{$class} .ch-region:hover { fill: #b8d0e8; }
            .{$class} .ch-marker { cursor:pointer; transition: r .15s; }
            .{$class} .ch-marker:hover circle { r:" . ($r + 3) . "; }
            .{$class} .ch-tooltip {
                font-family: -apple-system, sans-serif;
                font-size: 12px;
                pointer-events: none;
            }
            .{$class} .ch-tooltip-bg {
                fill: rgba(20,20,20,.85);
                rx: 4;
            }
            .{$class} .ch-tooltip-text { fill:#fff; }
        </style>";

        // Switzerland cantons / outline paths
        $out .= $this->renderCantonPaths($fill, $stroke);

        // Markers
        foreach ($markers as $m) {
            $color     = $this->wire('sanitizer')->entities($m['color']);
            $labelAttr = $this->wire('sanitizer')->entities($m['label']);
            $cx        = $m['x'];
            $cy        = $m['y'];

            $inner  = "<circle cx='{$cx}' cy='{$cy}' r='{$r}' fill='{$color}' stroke='#fff' stroke-width='1.5'/>";

            if ($m['url']) {
                $href  = $this->wire('sanitizer')->entities($m['url']);
                $out  .= "<a href='{$href}' class='ch-marker' data-label='{$labelAttr}'>{$inner}</a>";
            } else {
                $out  .= "<g class='ch-marker' data-label='{$labelAttr}'>{$inner}</g>";
            }
        }

        // Tooltip layer (populated by JS)
        if ($opt['tooltip']) {
            $out .= "<g class='ch-tooltip' id='{$class}-tip' style='display:none;'>
                <rect class='ch-tooltip-bg' id='{$class}-tip-bg' x='0' y='0' width='0' height='20'/>
                <text class='ch-tooltip-text' id='{$class}-tip-text' x='0' y='0' dominant-baseline='middle'></text>
            </g>";
        }

        $out .= "</svg>";
        return $out;
    }

    private function renderTooltipJS(string $cssClass): string {
        $id = $this->wire('sanitizer')->entities($cssClass);
        return "<script>
        (function(){
            var svg  = document.querySelector('svg.{$id}');
            if (!svg) return;
            var tip  = document.getElementById('{$id}-tip');
            var tipBg= document.getElementById('{$id}-tip-bg');
            var tipTx= document.getElementById('{$id}-tip-text');
            if (!tip) return;

            svg.addEventListener('mouseover', function(e) {
                var marker = e.target.closest('.ch-marker');
                if (!marker) { tip.style.display='none'; return; }
                var label = marker.dataset.label;
                if (!label) { tip.style.display='none'; return; }

                // Position tooltip near the circle
                var circle = marker.querySelector('circle');
                var cx = parseFloat(circle.getAttribute('cx'));
                var cy = parseFloat(circle.getAttribute('cy'));

                tipTx.textContent = label;
                tip.style.display = '';

                // Measure text width via getBBox
                var bb = tipTx.getBBox();
                var padX = 8, padY = 4;
                var bw = bb.width + padX*2;
                var bh = bb.height + padY*2;

                // Try to keep tooltip inside viewBox
                var tx = Math.min(cx + 10, " . self::VB_W . " - bw - 4);
                var ty = cy - bh - 4;
                if (ty < 0) ty = cy + 10;

                tipBg.setAttribute('x', tx);
                tipBg.setAttribute('y', ty);
                tipBg.setAttribute('width', bw);
                tipBg.setAttribute('height', bh);
                tipBg.setAttribute('rx', 4);

                tipTx.setAttribute('x', tx + padX);
                tipTx.setAttribute('y', ty + bh/2);
            });

            svg.addEventListener('mouseout', function(e) {
                if (!e.target.closest('.ch-marker')) return;
                tip.style.display = 'none';
            });
        })();
        </script>";
    }

    /**
     * Switzerland canton SVG paths.
     * Paths are simplified outlines mapped to viewBox 700×453.
     * Source: simplified from Swiss Federal Geodata swisstopo public domain data.
     */
    private function renderCantonPaths(string $fill, string $stroke): string {
        // Each canton as a simplified path (LV95-derived, scaled to 700x453 viewBox)
        $cantons = $this->getCantonPaths();
        $out = "<g class='ch-regions'>";
        foreach ($cantons as $abbr => $d) {
            $out .= "<path class='ch-region' data-canton='{$abbr}' d='{$d}' fill='{$fill}' stroke='{$stroke}' stroke-width='0.8'/>";
        }
        $out .= "</g>";
        return $out;
    }

    /**
     * Canton paths derived from real swisstopo boundaries (cantons.geojson TopoJSON).
     * Converted from WGS84 to viewBox 700×453.
     * lon: 5.956–10.492 → x: 0–700 / lat: 45.818–47.808 → y: 453–0
     */
    private function getCantonPaths(): array {
        return [
            'ZH' => "M440.1,133.6 L438.2,129.6 L439.3,127.7 L446.9,126.8 L448.3,128.6 L450.1,126.6 L459.4,125.0 L461.7,121.1 L459.5,118.2 L461.8,117.5 L463.1,114.5 L465.8,114.1 L467.4,111.4 L465.4,103.8 L463.5,103.4 L460.4,97.5 L460.8,95.9 L454.9,92.4 L456.6,88.6 L455.5,85.9 L459.4,84.7 L455.4,84.0 L454.8,82.9 L455.7,82.0 L451.3,75.8 L453.9,73.5 L454.1,64.6 L445.2,62.6 L443.1,57.4 L446.7,55.6 L443.6,53.3 L439.9,53.7 L439.0,51.7 L439.8,51.2 L430.8,49.2 L429.4,45.5 L430.8,42.1 L439.3,47.2 L440.8,41.2 L443.1,38.3 L442.9,36.7 L439.6,35.5 L439.6,32.6 L437.1,32.6 L436.1,35.5 L430.7,38.5 L424.8,37.8 L420.5,34.4 L418.8,28.0 L414.8,25.7 L411.9,26.4 L412.1,29.6 L410.2,29.6 L408.9,31.7 L412.5,36.2 L410.0,39.0 L408.4,38.5 L410.2,37.4 L409.1,35.3 L407.2,37.6 L408.8,44.4 L407.1,48.3 L404.6,49.9 L404.9,52.8 L403.7,53.1 L401.8,58.1 L397.8,51.2 L404.0,44.6 L401.4,41.7 L395.0,39.6 L393.8,43.3 L389.3,43.9 L385.7,47.4 L387.5,51.0 L390.8,50.1 L391.8,51.7 L381.1,54.6 L379.3,57.9 L379.9,59.7 L377.3,61.0 L377.6,62.6 L375.9,64.4 L374.7,63.7 L373.6,67.4 L371.1,67.9 L370.6,70.8 L373.3,76.7 L371.9,77.9 L375.1,81.7 L374.2,82.2 L374.7,84.9 L373.3,86.1 L375.9,87.9 L374.3,89.2 L374.8,91.5 L373.9,92.4 L371.2,92.4 L376.6,94.3 L376.2,97.2 L378.4,100.4 L377.8,105.0 L379.6,107.2 L379.6,110.2 L385.6,107.9 L381.6,115.4 L375.4,117.9 L380.5,133.2 L383.4,132.7 L390.1,136.6 L398.5,133.2 L405.3,134.3 L407.9,138.0 L410.2,138.2 L412.3,143.0 L411.7,144.6 L417.4,145.0 L417.6,147.3 L419.6,147.7 L422.2,146.6 L420.5,142.1 L424.0,138.0 L440.1,133.6 Z",
            'BE' => "M288.4,123.4 L286.3,124.3 L284.7,123.0 L282.2,125.7 L280.5,123.2 L277.9,125.2 L274.2,125.0 L266.8,117.0 L261.2,119.7 L250.6,121.1 L252.7,123.4 L253.2,128.2 L257.7,132.5 L259.9,131.6 L261.5,136.8 L260.9,138.0 L264.8,139.6 L264.6,141.8 L265.7,142.3 L264.9,145.7 L262.9,145.7 L260.6,150.0 L256.2,148.5 L251.5,150.3 L250.8,147.5 L248.2,145.7 L241.3,147.5 L242.7,150.0 L239.6,155.5 L232.2,159.4 L234.4,164.1 L231.4,166.9 L228.5,166.2 L227.9,161.2 L220.0,162.1 L218.1,156.4 L229.0,155.7 L226.2,153.0 L230.5,148.5 L235.0,148.9 L237.6,144.8 L235.3,144.8 L233.8,142.8 L234.4,140.5 L230.4,140.7 L230.5,142.1 L227.7,142.1 L227.7,143.9 L224.5,147.1 L220.2,147.5 L221.6,146.2 L219.9,142.1 L217.6,139.8 L215.7,140.3 L213.5,134.3 L225.1,129.3 L226.1,128.6 L225.7,125.9 L233.3,123.6 L237.5,118.4 L243.2,117.0 L247.2,110.6 L236.7,115.2 L219.4,112.3 L213.1,114.5 L210.0,117.9 L204.3,119.3 L193.5,118.2 L192.9,116.6 L186.4,117.0 L187.1,121.4 L183.1,124.8 L185.2,127.5 L175.9,130.0 L173.3,127.5 L170.0,128.8 L170.2,131.4 L168.3,132.3 L165.1,138.9 L160.5,140.0 L158.0,144.3 L152.0,141.8 L150.7,145.0 L143.2,149.8 L141.3,144.3 L139.6,146.2 L143.9,154.1 L140.6,164.6 L150.3,158.2 L151.8,159.2 L165.7,155.0 L167.2,155.7 L164.7,158.5 L165.6,160.3 L167.4,159.2 L174.5,164.4 L173.0,166.7 L174.5,171.0 L166.2,176.9 L166.3,180.1 L164.9,182.6 L167.2,188.5 L175.6,189.4 L184.8,187.2 L194.5,182.4 L197.5,187.4 L194.1,191.5 L191.2,192.1 L193.6,193.7 L192.4,198.3 L193.9,200.3 L192.7,201.7 L193.1,204.7 L190.1,206.7 L196.6,205.8 L204.0,209.0 L211.7,208.1 L215.7,209.9 L216.5,215.4 L215.4,217.2 L211.4,217.6 L212.2,216.3 L209.8,215.2 L207.7,217.6 L212.2,222.2 L210.4,223.6 L210.6,226.1 L207.7,230.8 L208.0,233.4 L205.8,235.9 L207.9,238.6 L207.1,247.0 L214.8,249.3 L214.3,252.5 L219.8,254.5 L217.9,262.5 L219.1,262.3 L216.2,266.1 L214.3,262.5 L210.6,262.5 L212.0,265.4 L209.4,266.6 L210.6,276.8 L207.2,279.8 L204.6,278.4 L201.2,283.4 L197.6,285.5 L199.5,293.7 L198.7,297.8 L197.0,298.9 L197.5,300.5 L195.7,300.7 L196.9,308.3 L195.5,311.0 L191.2,311.2 L190.8,312.8 L193.5,316.9 L191.0,325.3 L193.1,325.3 L194.4,329.2 L196.6,329.2 L194.9,330.3 L195.2,336.7 L200.2,336.3 L201.5,334.7 L201.5,330.1 L208.9,326.2 L208.6,331.4 L211.1,333.3 L228.6,323.7 L232.4,326.0 L235.0,324.0 L236.7,327.4 L244.1,325.8 L246.7,323.0 L242.7,320.5 L243.5,318.3 L250.1,316.7 L252.7,318.0 L255.2,312.1 L257.7,310.3 L271.6,317.1 L291.2,302.8 L299.2,301.9 L306.4,296.0 L310.5,294.8 L311.3,292.3 L309.7,288.9 L317.8,283.4 L333.0,287.1 L334.8,289.4 L342.3,290.7 L343.3,293.0 L355.2,291.2 L371.1,280.0 L372.4,268.4 L377.0,262.7 L378.7,262.9 L378.4,259.8 L376.8,258.1 L377.1,253.8 L385.2,254.7 L385.5,249.8 L384.4,248.8 L384.8,245.4 L383.4,243.8 L383.1,240.2 L384.4,236.3 L379.3,235.4 L376.5,237.4 L375.9,234.5 L372.4,232.2 L366.8,233.8 L359.1,240.2 L352.3,236.3 L349.7,238.3 L344.7,236.3 L337.9,239.7 L331.1,235.4 L329.3,232.2 L320.2,231.8 L311.4,234.5 L307.2,228.3 L296.4,221.0 L294.9,217.9 L295.4,214.9 L293.3,213.3 L293.6,210.1 L297.3,205.8 L294.9,203.1 L295.9,200.8 L301.8,199.2 L304.5,195.8 L304.9,192.6 L308.0,188.3 L307.4,185.3 L308.6,183.3 L303.5,181.4 L300.3,182.6 L299.1,177.6 L295.0,173.2 L297.2,163.7 L295.0,156.6 L297.8,154.8 L298.4,151.9 L296.4,149.1 L296.9,143.4 L293.6,140.0 L290.2,133.0 L288.4,123.4 Z M265.1,148.7 L267.4,145.9 L269.1,147.3 L267.6,149.1 L265.1,148.7 Z M197.3,200.1 L194.5,199.2 L197.3,198.1 L197.3,200.1 Z M173.9,207.6 L175.1,208.5 L177.1,206.7 L175.1,206.0 L173.9,207.6 Z M182.1,202.8 L180.3,202.4 L178.5,204.2 L180.1,206.7 L182.5,204.9 L182.1,202.8 Z M242.6,110.4 L249.5,109.0 L246.3,105.4 L242.6,110.4 Z",
            'LU' => "M322.9,232.2 L325.3,227.7 L321.9,221.0 L325.6,215.8 L326.2,207.6 L330.1,204.0 L332.2,204.9 L332.5,208.3 L336.2,201.7 L340.9,197.8 L338.6,193.5 L341.0,191.7 L350.7,190.8 L349.1,188.3 L350.7,185.8 L353.0,187.2 L356.3,184.4 L364.6,183.9 L367.1,186.5 L372.4,179.6 L374.7,179.9 L374.8,184.4 L381.1,184.4 L381.5,180.1 L387.8,181.9 L387.6,184.6 L388.5,184.8 L393.9,183.0 L394.7,179.0 L388.0,173.2 L383.4,169.6 L377.1,170.3 L375.4,168.5 L380.5,160.5 L384.8,158.0 L388.5,158.5 L391.5,161.2 L391.6,158.0 L389.7,155.3 L385.2,158.0 L384.5,154.4 L379.9,156.2 L378.9,151.9 L373.4,152.3 L371.2,150.7 L367.5,143.2 L365.7,135.0 L364.6,134.8 L364.0,129.3 L361.1,125.0 L361.7,122.3 L355.4,118.6 L352.8,120.5 L352.8,122.1 L350.6,121.8 L349.3,126.4 L346.9,128.6 L347.0,132.5 L344.1,133.2 L342.5,133.4 L339.5,129.8 L342.4,129.3 L343.3,127.7 L342.4,126.4 L334.7,129.1 L329.0,124.1 L324.1,125.7 L325.1,127.7 L317.7,128.4 L317.8,124.8 L314.6,119.7 L307.8,121.4 L309.4,126.1 L305.1,130.3 L301.5,128.4 L292.8,131.2 L290.7,130.3 L290.2,133.0 L297.3,144.6 L296.3,148.2 L298.4,151.9 L297.8,154.8 L295.0,156.6 L297.2,163.7 L295.0,173.2 L299.1,177.6 L300.3,182.6 L303.5,181.4 L308.6,183.3 L307.4,185.3 L308.0,188.3 L304.9,192.6 L304.5,195.8 L301.8,199.2 L299.6,198.8 L295.6,201.2 L294.9,203.6 L296.9,204.2 L297.2,206.7 L293.6,210.1 L293.3,213.3 L295.4,214.9 L294.9,217.9 L296.4,221.0 L307.2,228.3 L311.4,234.5 L322.9,232.2 Z",
            'UR' => "M459.7,202.1 L448.4,207.6 L447.1,210.1 L446.3,203.6 L444.4,204.9 L443.0,198.5 L440.6,197.4 L437.0,199.9 L434.4,197.4 L424.7,202.8 L422.4,196.0 L408.6,194.9 L408.4,186.9 L403.2,186.2 L404.0,188.1 L401.1,192.4 L401.6,197.6 L399.3,197.8 L399.3,200.3 L393.0,203.6 L390.4,202.6 L386.2,207.0 L389.3,210.6 L387.5,212.4 L388.3,217.0 L392.2,217.2 L393.6,219.2 L391.0,221.3 L389.2,226.3 L389.8,228.8 L387.0,229.5 L389.7,229.7 L389.2,232.0 L391.2,235.6 L384.7,237.4 L383.1,240.2 L383.4,243.8 L384.8,245.4 L384.4,248.8 L385.5,249.8 L385.2,254.7 L377.1,253.8 L376.6,255.9 L378.7,262.9 L380.5,264.1 L380.2,271.4 L378.0,278.2 L381.6,288.0 L389.2,291.4 L395.6,288.9 L394.6,284.3 L395.8,280.3 L398.5,278.0 L403.8,278.7 L406.5,281.2 L411.1,280.0 L413.4,283.2 L420.2,279.8 L420.2,271.4 L415.8,266.8 L417.4,262.3 L420.2,260.5 L419.4,254.1 L422.6,251.6 L424.8,252.5 L430.8,248.1 L430.8,244.1 L433.6,242.0 L436.1,245.9 L440.8,243.8 L441.6,238.1 L444.1,237.0 L443.1,231.3 L446.3,230.8 L450.7,226.5 L451.5,222.9 L449.8,219.9 L463.0,212.4 L462.5,206.5 L459.7,202.1 Z",
            'SZ' => "M403.2,186.2 L408.4,186.9 L408.6,194.9 L422.4,196.0 L424.7,202.8 L434.4,197.4 L437.0,199.9 L440.6,197.4 L443.0,198.5 L444.4,204.9 L446.3,203.6 L447.1,210.1 L448.4,207.6 L464.0,200.6 L462.7,197.8 L465.3,193.3 L461.4,191.5 L459.2,188.1 L460.6,186.7 L453.2,178.5 L454.9,177.8 L454.6,175.3 L461.7,172.8 L462.6,168.9 L465.8,164.4 L462.6,163.2 L465.3,160.3 L464.6,152.8 L470.5,144.6 L466.9,142.3 L463.9,143.0 L463.2,138.6 L464.3,138.9 L465.7,135.5 L465.1,134.3 L445.2,136.6 L437.8,133.2 L422.5,139.1 L420.3,143.2 L423.5,149.6 L421.0,151.9 L420.7,155.5 L417.2,157.8 L414.9,162.1 L409.4,162.3 L402.9,165.5 L402.3,162.6 L395.8,163.5 L388.5,158.5 L384.8,158.0 L380.5,160.5 L378.7,162.6 L378.7,165.0 L375.4,167.8 L375.7,170.1 L383.4,169.6 L394.7,179.0 L393.9,183.0 L387.6,184.6 L387.1,187.6 L392.1,189.4 L403.2,186.2 Z",
            'OW' => "M372.4,232.2 L365.1,224.9 L367.1,223.1 L366.0,211.7 L368.3,203.3 L367.5,200.1 L363.3,201.2 L359.8,199.0 L363.3,194.9 L363.3,191.0 L361.9,189.0 L354.4,188.5 L346.7,191.5 L344.2,190.3 L338.6,193.5 L340.9,197.8 L336.2,201.7 L332.5,208.3 L332.2,204.9 L330.1,204.0 L326.2,207.6 L325.6,215.8 L321.9,221.0 L325.3,227.7 L322.9,232.2 L329.3,232.2 L331.1,235.4 L337.9,239.7 L344.7,236.3 L349.7,238.3 L352.3,236.3 L359.1,240.2 L366.8,233.8 L372.4,232.2 Z M384.7,237.4 L391.2,235.6 L389.2,232.0 L389.7,229.7 L388.0,230.4 L387.0,229.5 L389.8,228.8 L389.2,226.3 L391.0,221.3 L393.6,219.2 L389.7,216.5 L380.2,217.6 L379.2,213.6 L373.3,218.5 L374.8,214.5 L372.9,212.4 L371.1,215.4 L372.6,216.5 L373.1,225.8 L375.3,226.5 L374.8,228.6 L381.0,233.6 L381.1,235.2 L379.7,234.5 L376.4,235.9 L376.5,237.4 L379.3,235.4 L384.7,237.4 Z",
            'NW' => "M350.7,190.1 L354.4,188.5 L362.8,189.4 L363.3,194.9 L359.8,199.0 L363.3,201.2 L367.5,200.1 L368.3,203.3 L366.0,211.7 L367.1,223.1 L365.1,224.9 L376.4,235.9 L381.1,235.2 L381.0,233.6 L374.8,228.6 L375.3,226.5 L373.1,225.8 L373.7,224.0 L371.6,214.2 L372.9,212.4 L374.3,213.1 L373.3,218.5 L379.2,213.6 L380.2,217.6 L388.3,217.0 L387.5,212.4 L389.3,210.6 L386.2,207.0 L390.4,202.6 L393.0,203.6 L399.3,200.3 L399.3,197.8 L401.6,197.6 L401.1,192.4 L404.0,187.6 L403.2,186.2 L392.1,189.4 L387.1,187.6 L387.8,181.9 L381.5,180.1 L381.1,184.4 L374.8,184.4 L374.7,179.9 L372.4,179.6 L367.1,186.5 L364.6,183.9 L356.3,184.4 L353.0,187.2 L350.7,185.8 L349.1,188.3 L350.7,190.1 Z",
            'GL' => "M508.1,203.1 L508.3,198.5 L506.2,196.5 L506.3,194.2 L507.7,193.5 L508.3,185.1 L506.2,178.5 L501.8,175.8 L495.0,177.8 L491.0,175.1 L496.7,171.9 L499.4,167.4 L499.0,155.7 L487.6,153.7 L480.4,154.4 L470.5,144.3 L464.6,152.8 L465.3,160.3 L462.6,163.2 L465.8,164.4 L462.6,168.9 L461.7,172.8 L454.6,175.3 L454.9,177.8 L453.2,178.5 L460.6,186.7 L459.2,188.1 L461.4,191.5 L465.3,193.3 L462.7,197.8 L464.0,200.6 L459.7,202.1 L462.5,206.5 L463.2,211.3 L449.8,219.9 L451.5,222.9 L450.6,226.1 L456.6,227.0 L456.6,229.7 L458.6,230.4 L471.1,227.0 L476.8,218.1 L476.8,214.2 L479.1,212.6 L484.4,213.3 L486.8,218.1 L494.0,212.6 L493.3,211.3 L500.6,211.0 L508.1,203.1 Z",
            'ZG' => "M391.5,161.2 L395.8,163.5 L402.3,162.6 L402.9,165.5 L409.4,162.3 L413.7,162.8 L417.2,157.8 L419.1,157.6 L421.2,154.1 L421.0,151.9 L423.5,149.6 L422.8,146.8 L418.2,147.5 L417.4,145.0 L411.7,144.6 L412.3,143.0 L410.2,138.2 L407.9,138.0 L405.3,134.3 L398.5,133.2 L388.4,136.4 L383.4,132.7 L380.5,133.2 L378.8,127.5 L376.4,133.2 L378.0,139.4 L377.1,142.5 L379.4,146.2 L379.4,155.7 L384.5,154.4 L385.7,158.0 L389.7,155.3 L391.6,158.0 L391.5,161.2 Z",
            'FR' => "M193.0,290.3 L201.2,283.4 L204.6,278.4 L207.2,279.8 L210.6,276.8 L209.4,266.6 L212.0,265.4 L210.6,262.5 L214.3,262.5 L216.2,266.1 L219.1,262.3 L217.9,262.5 L219.8,254.5 L214.3,252.5 L214.8,249.3 L207.1,247.0 L207.9,238.6 L205.8,235.9 L208.0,233.4 L207.7,230.8 L210.6,226.1 L210.4,223.6 L212.2,222.2 L207.7,217.6 L209.8,215.2 L212.2,216.3 L211.4,217.6 L215.4,217.2 L216.5,215.4 L215.7,209.9 L211.7,208.1 L204.0,209.0 L196.6,205.8 L190.1,206.7 L193.1,204.7 L192.7,201.7 L193.9,200.3 L192.4,198.3 L193.6,193.7 L191.2,192.1 L194.1,191.5 L197.5,187.4 L194.5,182.4 L182.4,187.8 L169.1,189.2 L170.8,190.3 L170.0,197.8 L175.1,206.0 L177.1,206.7 L175.1,208.5 L173.9,207.6 L172.6,207.6 L173.4,208.1 L170.7,210.3 L171.7,213.3 L167.7,218.8 L165.6,217.9 L167.2,215.6 L165.7,214.5 L166.2,212.2 L163.5,211.0 L162.1,212.9 L160.2,211.5 L162.6,209.2 L158.1,204.7 L158.9,202.1 L155.7,201.2 L150.1,194.7 L145.1,201.0 L150.3,206.7 L149.3,208.1 L151.6,209.0 L155.7,214.7 L156.5,214.2 L154.6,211.0 L157.1,209.7 L158.4,211.3 L159.4,212.9 L157.5,215.6 L157.5,218.1 L159.4,219.5 L158.4,220.4 L159.8,222.4 L158.8,224.5 L155.3,223.6 L154.4,226.1 L156.0,228.8 L154.4,232.5 L152.1,233.1 L151.1,236.1 L146.9,239.0 L152.0,242.0 L144.1,253.6 L139.9,256.6 L141.3,258.4 L140.1,260.2 L140.7,261.1 L138.5,262.3 L136.1,260.7 L133.0,264.1 L130.1,263.9 L130.8,273.0 L129.4,280.7 L132.2,280.0 L132.4,278.2 L135.6,280.7 L138.4,279.1 L143.3,283.7 L146.0,281.8 L146.0,283.9 L143.3,285.0 L139.6,290.7 L138.7,288.5 L135.2,289.4 L134.1,288.0 L131.9,292.1 L137.6,299.1 L139.9,299.1 L145.3,293.2 L144.8,295.5 L145.1,296.2 L147.6,294.8 L151.1,297.6 L153.9,297.3 L156.9,300.1 L158.0,307.1 L159.8,309.4 L158.6,311.6 L159.4,311.9 L163.9,309.8 L165.4,305.3 L170.5,300.7 L176.5,300.7 L180.2,298.0 L183.4,291.6 L185.6,291.6 L190.7,287.1 L193.0,290.3 Z M178.8,203.1 L181.9,202.4 L182.5,204.9 L179.6,206.5 L178.8,203.1 Z M132.8,244.7 L140.2,246.5 L142.9,240.9 L139.3,238.3 L137.8,235.2 L132.7,243.6 L129.3,244.1 L128.4,245.9 L132.8,244.7 Z M140.4,204.7 L121.3,223.4 L127.8,228.8 L126.6,232.0 L131.0,232.7 L134.1,235.6 L139.3,232.2 L142.5,235.2 L147.4,234.0 L150.4,228.6 L148.8,227.2 L146.4,228.1 L147.4,226.5 L146.1,223.1 L150.4,217.4 L147.6,214.5 L144.4,214.7 L148.9,213.3 L140.4,204.7 Z M128.0,241.3 L126.0,240.7 L122.4,245.0 L124.3,248.4 L127.4,245.0 L128.0,241.3 Z M197.3,199.2 L196.6,197.6 L194.4,199.7 L194.9,201.2 L197.3,199.2 Z",
            'SO' => "M249.5,109.0 L243.2,117.0 L237.5,118.4 L233.3,123.6 L225.7,125.9 L226.1,128.6 L225.1,129.3 L213.5,134.3 L215.7,140.3 L217.6,139.8 L219.9,142.1 L221.6,146.2 L220.2,147.5 L224.5,147.1 L227.7,143.9 L227.7,142.1 L230.5,142.1 L230.4,140.7 L234.4,140.5 L233.8,142.8 L237.7,145.2 L235.0,148.9 L230.5,148.5 L226.2,153.0 L229.0,155.7 L223.7,157.1 L220.8,155.3 L218.4,155.9 L220.0,162.1 L227.9,161.2 L228.5,166.2 L231.4,166.9 L234.4,164.1 L232.2,159.4 L239.6,155.5 L242.7,150.0 L241.3,147.5 L248.2,145.7 L250.8,147.5 L251.5,150.3 L256.2,148.5 L260.6,150.0 L262.9,145.7 L264.9,145.7 L265.7,142.3 L264.6,141.8 L264.8,139.6 L260.9,138.0 L261.5,136.8 L259.9,131.6 L257.7,132.5 L253.2,128.2 L252.7,123.4 L250.6,121.1 L261.2,119.7 L266.8,117.0 L274.2,125.0 L277.9,125.2 L279.1,123.2 L282.2,125.7 L284.7,123.0 L287.3,124.3 L290.9,121.6 L294.1,114.1 L299.8,111.1 L301.0,106.1 L302.9,107.9 L307.4,108.1 L307.4,111.6 L316.2,107.2 L316.9,102.7 L319.6,99.9 L320.2,96.5 L319.6,93.8 L316.9,94.1 L316.5,91.3 L309.7,87.9 L313.4,86.5 L310.5,78.6 L307.2,83.1 L309.5,87.9 L308.5,89.2 L301.5,93.4 L297.3,91.5 L295.1,94.1 L296.7,96.5 L284.9,101.8 L283.6,106.8 L276.9,105.7 L273.4,99.9 L260.4,100.4 L260.1,97.2 L258.7,96.8 L258.7,90.6 L266.0,89.0 L266.8,82.0 L270.6,77.2 L269.1,74.5 L264.5,73.5 L263.9,70.8 L261.7,71.0 L262.2,73.1 L260.0,74.0 L254.6,72.6 L254.4,76.7 L257.7,78.6 L256.2,85.4 L250.9,86.3 L248.9,84.7 L248.9,88.1 L250.6,89.5 L242.1,90.2 L243.0,92.0 L241.2,95.6 L238.5,96.3 L234.9,95.2 L234.7,92.7 L229.1,92.7 L229.6,95.4 L228.4,97.9 L233.5,99.7 L241.9,99.3 L241.7,100.8 L244.4,102.0 L244.4,105.0 L247.1,105.7 L249.5,109.0 Z M269.1,147.3 L266.8,146.2 L265.1,148.7 L267.6,149.1 L269.1,147.3 Z M219.1,89.7 L228.6,89.9 L231.3,86.3 L228.6,82.4 L226.1,82.4 L219.9,85.6 L219.1,89.7 Z M239.9,69.5 L236.4,74.3 L233.8,74.7 L228.2,70.6 L226.1,74.7 L231.4,76.3 L229.8,78.8 L231.3,81.5 L243.0,79.0 L242.4,76.1 L243.8,72.2 L239.9,69.5 Z",
            'BS' => "M256.4,56.7 L265.4,55.8 L266.2,54.0 L266.9,55.1 L267.6,54.0 L264.8,50.3 L268.2,47.2 L265.3,49.2 L260.8,48.1 L256.8,52.6 L252.0,49.7 L251.3,52.8 L247.1,53.5 L246.7,55.3 L248.0,56.9 L246.7,60.1 L251.7,60.6 L251.0,62.6 L252.6,65.6 L256.8,61.3 L256.4,56.7 Z",
            'BL' => "M228.5,97.5 L229.4,92.4 L234.7,92.7 L234.9,95.2 L237.0,96.3 L241.2,95.6 L243.0,92.0 L242.1,90.2 L250.6,89.5 L248.9,88.1 L248.9,84.7 L250.9,86.3 L256.2,85.4 L257.7,78.6 L254.4,76.7 L254.6,72.6 L260.0,74.0 L262.2,73.1 L261.7,71.0 L263.9,70.8 L264.5,73.5 L269.1,74.5 L270.6,77.2 L266.8,82.0 L266.0,89.0 L258.7,90.6 L258.7,96.8 L260.1,97.2 L260.4,100.4 L273.4,99.9 L276.9,105.7 L282.2,107.2 L283.6,106.8 L284.9,101.8 L296.7,96.5 L295.1,94.1 L297.3,91.5 L301.5,93.4 L308.5,89.2 L309.5,87.9 L307.2,83.1 L308.9,79.5 L306.1,78.8 L307.2,73.5 L300.9,73.5 L299.1,68.8 L296.3,67.2 L296.4,64.9 L294.4,65.8 L292.6,62.2 L289.6,62.4 L289.5,66.7 L282.8,71.7 L283.0,65.8 L274.8,64.0 L271.3,61.3 L265.4,62.6 L258.1,56.0 L256.4,56.7 L256.8,61.3 L252.0,65.6 L251.7,60.6 L246.7,60.1 L248.0,56.9 L246.7,55.3 L238.0,61.7 L238.5,63.7 L241.2,62.2 L243.0,63.5 L241.8,67.0 L238.0,65.4 L240.1,70.4 L243.8,72.2 L242.4,76.1 L243.0,79.0 L231.3,81.5 L229.8,78.8 L227.5,79.5 L226.1,82.4 L230.2,84.3 L231.3,87.0 L230.0,89.5 L219.1,89.7 L225.1,94.3 L224.7,97.2 L228.5,97.5 Z M219.1,89.7 L219.9,85.6 L211.4,83.8 L211.4,85.6 L216.2,89.5 L219.1,89.7 Z",
            'SH' => "M417.7,27.8 L417.0,26.6 L419.9,25.1 L417.9,21.7 L421.9,22.6 L423.8,21.2 L425.2,25.5 L427.7,26.2 L429.0,21.0 L425.2,17.8 L425.6,15.7 L429.8,12.8 L426.3,9.8 L422.0,11.6 L420.5,4.8 L416.8,3.7 L416.8,1.9 L414.9,4.8 L416.2,8.0 L412.3,11.2 L410.3,5.7 L410.9,2.3 L404.2,1.9 L403.0,0.0 L402.1,3.4 L404.2,4.1 L404.4,5.9 L402.3,6.8 L400.7,5.3 L395.6,8.7 L394.1,7.3 L390.4,8.2 L385.7,13.5 L384.8,15.9 L385.6,19.6 L379.7,22.3 L377.8,25.1 L380.3,28.3 L378.2,29.9 L378.9,32.3 L380.8,32.1 L384.1,35.1 L387.3,34.4 L387.3,37.6 L388.5,38.7 L388.5,36.0 L391.0,37.8 L397.5,36.9 L396.7,33.7 L398.9,34.4 L399.8,31.9 L404.9,33.3 L407.7,30.8 L409.1,31.7 L410.2,29.6 L412.1,29.6 L411.9,26.4 L414.8,25.7 L417.7,27.8 Z M450.4,34.8 L450.1,31.4 L446.6,28.9 L447.6,26.2 L446.7,25.1 L450.4,26.0 L450.1,23.7 L446.4,23.9 L443.9,21.2 L442.4,22.1 L442.7,20.3 L439.6,19.2 L440.7,18.0 L439.8,15.9 L438.2,16.6 L438.7,18.4 L434.3,20.3 L434.0,23.0 L437.6,23.5 L440.4,26.2 L438.5,26.9 L438.2,30.1 L443.9,30.5 L447.2,33.5 L445.8,35.3 L446.7,36.0 L450.4,34.8 Z M407.4,46.0 L405.2,48.3 L402.3,47.6 L398.0,50.3 L398.0,53.1 L399.5,53.1 L399.0,54.2 L401.8,58.1 L403.7,53.1 L404.9,52.8 L404.6,49.9 L407.1,48.3 L407.4,46.0 Z",
            'AR' => "M556.8,84.0 L567.1,83.4 L561.7,79.5 L552.0,77.4 L550.6,81.3 L539.5,85.9 L537.0,84.9 L536.3,90.4 L531.8,92.7 L526.9,91.1 L516.7,94.1 L505.5,93.4 L502.6,100.8 L499.4,101.5 L500.0,103.6 L501.7,102.9 L500.4,104.7 L501.5,106.8 L504.0,105.9 L505.7,108.4 L502.2,110.9 L504.5,117.2 L501.5,120.9 L509.2,124.1 L512.0,122.5 L515.5,125.4 L517.3,124.8 L518.8,127.5 L522.7,127.3 L518.1,117.5 L518.8,113.6 L517.6,110.4 L523.1,105.4 L522.8,103.8 L526.0,102.5 L523.7,96.8 L527.7,95.9 L533.3,99.9 L536.3,99.5 L537.3,102.9 L541.6,105.0 L547.3,105.0 L547.2,96.5 L548.6,92.4 L552.0,90.4 L551.0,90.6 L550.4,88.1 L552.9,85.6 L558.8,89.5 L556.9,91.7 L553.3,91.1 L555.9,93.6 L560.1,89.9 L559.7,88.3 L557.2,87.9 L558.4,87.0 L556.0,85.6 L556.8,84.0 Z",
            'AI' => "M547.3,105.0 L541.6,105.0 L537.3,102.9 L536.3,99.5 L533.3,99.9 L527.7,95.9 L523.7,96.8 L526.0,102.5 L522.8,103.8 L523.1,105.4 L520.5,108.6 L517.7,110.0 L518.8,113.6 L518.1,117.5 L522.8,127.5 L528.7,130.7 L531.8,129.8 L537.3,126.8 L544.6,119.3 L547.3,105.0 Z M555.4,93.6 L553.3,91.1 L556.9,91.7 L558.8,89.5 L552.9,85.6 L550.4,88.1 L551.0,90.6 L552.0,90.4 L548.1,93.8 L555.4,93.6 Z M559.7,90.6 L563.1,89.7 L560.5,87.2 L565.1,84.5 L556.8,84.0 L556.0,85.6 L558.4,87.0 L557.4,88.1 L559.7,88.3 L559.7,90.6 Z",
            'SG' => "M466.9,142.3 L480.4,154.4 L487.6,153.7 L499.0,155.7 L499.4,167.4 L496.7,171.9 L491.0,175.1 L495.0,177.8 L501.8,175.8 L506.2,178.5 L508.3,185.1 L507.7,193.5 L506.3,194.2 L506.2,196.5 L508.3,198.5 L508.1,203.1 L509.2,204.9 L528.7,207.2 L536.5,210.1 L539.0,212.9 L539.8,209.9 L541.8,209.9 L541.8,206.7 L545.0,204.0 L543.5,201.9 L544.9,198.5 L547.7,194.4 L549.6,193.9 L550.0,190.6 L554.0,189.2 L549.5,183.5 L542.6,170.1 L549.3,163.9 L550.0,161.2 L548.9,153.9 L544.7,143.4 L545.2,139.4 L551.5,122.3 L554.3,120.0 L555.6,116.1 L559.7,112.9 L562.6,104.5 L566.0,100.6 L571.3,99.9 L573.6,97.2 L573.7,95.0 L570.2,91.7 L569.1,84.9 L571.4,81.3 L565.7,79.7 L563.8,77.0 L561.5,78.6 L556.3,70.4 L554.7,61.7 L549.2,61.7 L547.3,59.4 L532.4,71.5 L531.0,75.8 L528.2,73.1 L526.4,73.5 L527.3,69.9 L521.7,69.9 L524.6,66.5 L521.9,64.6 L522.2,62.8 L518.6,63.1 L518.1,65.1 L516.5,64.2 L513.2,65.4 L512.9,67.9 L511.3,67.4 L513.1,68.8 L516.5,66.5 L518.6,67.9 L517.1,69.7 L518.8,70.1 L519.2,72.2 L515.9,75.4 L513.4,74.0 L510.3,75.8 L505.0,73.8 L502.6,74.9 L502.2,72.6 L498.0,72.2 L495.8,69.2 L493.5,73.1 L488.6,74.7 L483.6,74.3 L482.3,71.3 L478.4,73.1 L476.3,71.0 L471.6,72.6 L470.3,74.5 L471.1,76.3 L474.4,77.7 L473.9,79.7 L479.4,82.4 L467.9,86.1 L468.0,90.4 L466.2,90.4 L465.4,95.0 L461.1,98.4 L463.5,103.4 L465.4,103.8 L467.4,111.4 L465.8,114.1 L463.1,114.5 L461.8,117.5 L459.5,118.2 L461.7,119.7 L460.9,123.2 L459.4,125.0 L450.1,126.6 L449.2,128.4 L442.9,126.8 L439.3,127.7 L438.2,129.6 L440.3,134.1 L443.4,136.4 L465.1,134.3 L465.7,135.5 L464.3,138.9 L463.2,138.6 L463.9,143.0 L466.9,142.3 Z M539.0,69.9 L541.9,71.0 L543.2,72.6 L541.3,73.8 L538.8,71.5 L539.0,69.9 Z M522.7,127.3 L518.8,127.5 L517.3,124.8 L515.5,125.4 L512.0,122.5 L509.2,124.1 L501.7,121.1 L504.5,117.2 L502.2,110.9 L505.7,108.4 L504.0,105.9 L501.5,106.8 L500.4,104.7 L501.7,102.9 L500.0,103.6 L499.4,101.5 L502.6,100.8 L505.5,93.4 L516.7,94.1 L526.9,91.1 L530.0,92.7 L535.5,91.3 L537.0,84.9 L539.5,85.9 L550.6,81.3 L552.0,77.4 L559.8,78.8 L566.9,82.9 L560.5,87.2 L563.1,89.7 L555.1,94.3 L553.5,92.9 L548.3,94.1 L546.8,99.9 L547.3,107.2 L546.3,114.3 L544.6,119.3 L540.9,123.9 L528.7,130.7 L522.7,127.3 Z M524.5,68.1 L525.1,67.6 L524.2,67.6 L524.5,68.1 Z",
            'GR' => "M494.2,372.9 L497.8,372.9 L499.8,370.9 L500.9,364.2 L503.5,362.9 L503.8,359.4 L508.0,358.3 L508.3,351.0 L513.6,343.8 L513.2,341.2 L515.9,336.9 L514.8,335.1 L516.0,333.3 L515.7,331.2 L512.3,327.8 L513.6,324.2 L512.3,321.7 L512.9,317.1 L508.0,313.0 L507.8,309.8 L512.6,306.7 L512.0,301.4 L513.4,298.5 L525.6,295.5 L527.3,296.9 L526.9,300.3 L532.8,305.3 L535.1,303.2 L536.7,298.2 L541.2,295.7 L540.9,301.2 L539.0,301.4 L541.3,302.5 L541.6,304.8 L540.5,306.2 L539.8,315.8 L541.9,322.8 L540.9,326.0 L546.5,329.2 L549.6,337.2 L554.6,342.9 L556.1,341.9 L559.7,344.7 L567.9,346.5 L574.1,342.6 L576.0,344.9 L579.9,344.9 L581.8,340.3 L580.4,337.8 L582.0,335.8 L581.3,334.0 L583.4,331.7 L589.8,335.3 L598.1,329.4 L603.7,329.0 L609.8,324.9 L612.5,328.3 L616.8,325.3 L618.5,328.7 L623.4,331.7 L621.0,338.1 L624.0,340.3 L624.2,343.1 L622.8,344.2 L623.4,346.7 L632.4,350.8 L633.4,355.1 L630.8,359.2 L635.0,362.2 L637.8,359.7 L643.0,360.6 L646.6,359.2 L651.2,352.9 L647.8,344.9 L642.1,340.1 L640.2,334.9 L640.8,331.2 L644.1,329.4 L643.8,325.6 L649.0,323.5 L649.8,318.9 L648.8,316.9 L646.9,317.4 L646.1,314.1 L643.9,313.2 L636.6,315.8 L630.6,310.7 L632.4,305.8 L630.7,302.8 L631.6,297.8 L630.6,295.5 L632.4,292.5 L631.0,288.7 L636.7,283.4 L636.2,280.7 L638.9,280.3 L639.9,278.2 L639.0,276.4 L639.8,272.5 L643.8,273.9 L653.8,269.1 L657.2,271.1 L660.9,266.8 L664.0,272.7 L661.2,277.0 L662.0,280.7 L665.7,280.0 L668.4,281.8 L669.7,286.4 L674.2,285.9 L676.2,288.0 L678.4,285.0 L685.3,287.8 L688.5,286.1 L693.8,290.7 L696.8,287.8 L700.0,271.6 L692.9,265.7 L686.1,266.6 L683.0,255.4 L688.1,250.2 L688.5,247.9 L685.8,244.5 L692.2,240.4 L692.2,235.9 L689.3,232.0 L693.2,229.2 L694.7,225.6 L694.5,222.7 L696.8,218.3 L695.8,210.3 L699.0,203.6 L699.4,198.1 L689.8,193.7 L690.1,189.9 L683.6,183.7 L681.3,186.0 L677.6,186.2 L674.5,194.4 L671.7,195.4 L671.2,197.6 L672.8,200.8 L669.9,202.1 L661.2,199.4 L658.9,207.4 L660.2,210.1 L660.0,214.5 L654.8,214.2 L650.1,217.9 L640.2,220.1 L638.4,216.1 L632.1,214.9 L632.2,212.2 L626.8,206.3 L622.5,206.0 L619.8,203.1 L614.3,203.6 L604.9,198.8 L605.4,193.7 L604.2,192.4 L607.4,186.2 L606.9,183.7 L604.0,181.0 L605.2,179.2 L602.3,178.7 L598.7,181.0 L590.9,175.3 L580.7,173.9 L575.0,169.8 L569.2,170.3 L567.1,172.3 L562.8,169.8 L555.6,172.8 L553.1,169.2 L545.6,171.0 L544.7,172.6 L543.2,171.4 L554.0,189.2 L550.0,190.6 L549.6,193.9 L547.7,194.4 L544.9,198.5 L543.5,201.9 L545.0,204.0 L541.8,206.7 L541.8,209.9 L539.8,209.9 L539.0,212.9 L533.2,208.5 L517.6,205.4 L510.9,205.8 L508.1,203.1 L500.6,211.0 L493.3,211.3 L494.0,212.6 L486.8,218.1 L484.4,213.3 L479.8,212.4 L476.8,214.2 L476.8,218.1 L471.1,227.0 L458.6,230.4 L456.6,229.7 L456.6,227.0 L450.7,226.5 L446.3,230.8 L443.1,231.3 L444.1,237.0 L441.6,238.1 L440.8,243.8 L436.1,245.9 L433.6,242.0 L430.8,244.1 L430.8,248.1 L424.8,252.5 L423.4,251.3 L419.7,253.6 L420.2,260.5 L417.4,262.3 L415.8,266.8 L420.2,271.4 L420.2,279.8 L423.1,279.3 L426.2,281.6 L431.3,280.5 L439.0,283.4 L443.5,281.6 L443.8,283.2 L445.5,283.4 L455.5,278.0 L456.9,275.7 L455.4,271.4 L457.8,269.8 L460.8,270.7 L462.3,267.7 L464.0,270.9 L463.9,274.3 L469.1,272.3 L475.0,275.0 L476.5,278.9 L475.4,279.1 L472.6,286.9 L473.4,296.0 L476.7,302.1 L482.8,303.4 L484.4,306.9 L483.5,313.2 L485.4,318.0 L483.6,318.9 L484.5,320.5 L483.1,323.0 L484.1,330.1 L480.4,333.3 L479.6,337.2 L481.3,339.9 L478.1,345.1 L481.8,352.4 L480.7,357.9 L482.1,358.5 L483.3,363.1 L494.2,372.9 Z",
            'AG' => "M378.8,127.5 L375.4,117.9 L381.6,115.4 L385.6,107.9 L379.6,110.2 L379.6,107.2 L377.8,105.0 L378.4,100.4 L376.2,97.2 L376.6,94.3 L374.5,94.3 L371.2,92.4 L373.9,92.4 L374.8,91.5 L374.3,89.2 L375.9,87.9 L373.3,86.1 L374.7,84.9 L374.2,82.2 L375.1,81.7 L371.9,77.9 L373.3,76.7 L370.6,69.7 L371.1,67.9 L374.0,66.7 L374.7,63.7 L375.9,64.4 L377.6,62.6 L377.3,61.0 L379.9,59.7 L379.3,57.9 L381.1,54.6 L377.3,52.6 L374.7,55.3 L366.0,54.0 L361.2,49.7 L361.0,45.5 L354.4,43.9 L350.1,46.2 L349.3,43.3 L346.6,42.6 L341.0,48.7 L332.4,51.2 L330.9,55.8 L328.8,57.2 L326.0,55.3 L318.7,58.8 L308.6,56.9 L306.1,60.1 L301.7,58.5 L301.7,54.0 L298.7,50.1 L291.8,51.5 L287.8,50.1 L283.9,57.2 L271.3,61.0 L274.8,64.0 L283.0,65.8 L282.8,71.7 L289.5,66.7 L289.6,62.4 L293.3,62.6 L294.4,65.8 L296.4,64.9 L296.3,67.2 L299.1,68.8 L300.9,73.5 L307.4,73.8 L306.1,78.8 L307.5,78.3 L308.7,80.4 L311.4,79.2 L313.2,87.7 L312.8,86.5 L309.7,87.9 L316.5,91.3 L316.9,94.1 L319.6,93.8 L319.6,99.9 L316.9,102.7 L316.2,107.2 L307.4,111.6 L307.4,108.1 L302.9,107.9 L301.0,106.1 L299.8,111.1 L294.1,114.1 L290.9,121.6 L288.4,123.4 L288.6,127.9 L290.5,130.7 L301.5,128.4 L305.1,130.3 L309.4,126.1 L307.8,121.4 L314.6,119.7 L317.8,124.8 L317.7,128.4 L325.1,127.7 L324.1,125.7 L329.0,124.1 L334.7,129.1 L342.4,126.4 L343.3,127.7 L342.4,129.3 L339.5,129.8 L342.5,133.4 L344.1,133.2 L347.0,132.5 L346.9,128.6 L349.3,126.4 L350.6,121.8 L352.8,122.1 L352.8,120.5 L355.4,118.6 L361.7,122.3 L361.1,125.0 L364.0,129.3 L364.6,134.8 L365.7,135.0 L367.5,143.2 L371.2,150.7 L377.9,152.5 L379.3,151.6 L379.4,146.2 L377.1,142.5 L378.0,139.4 L376.4,132.5 L378.8,127.5 Z",
            'TG' => "M461.1,98.4 L465.4,95.0 L466.2,90.4 L468.0,90.4 L467.9,86.1 L479.4,82.4 L473.9,79.7 L474.4,77.7 L471.1,76.3 L470.3,74.5 L471.6,72.6 L476.3,71.0 L478.4,73.1 L482.3,71.3 L483.6,74.3 L488.6,74.7 L493.5,73.1 L495.8,69.2 L498.0,72.2 L502.2,72.6 L502.6,74.9 L505.0,73.8 L510.3,75.8 L513.4,74.0 L515.9,75.4 L519.2,72.2 L518.8,70.1 L517.1,69.7 L518.6,67.9 L516.5,66.5 L513.1,68.8 L511.3,67.4 L512.9,67.9 L513.2,65.4 L516.5,64.2 L518.1,65.1 L518.6,63.1 L522.2,62.8 L521.9,64.6 L524.6,66.5 L521.9,68.5 L521.7,69.9 L527.3,69.9 L526.4,73.5 L528.2,73.1 L531.0,75.8 L532.4,71.5 L547.3,59.4 L538.4,48.5 L509.2,33.9 L496.9,35.1 L493.0,31.9 L491.0,32.8 L484.5,29.4 L472.8,27.5 L460.6,34.6 L454.4,36.4 L448.9,34.6 L447.8,36.2 L445.8,35.3 L447.2,33.5 L443.9,30.5 L437.1,30.3 L432.2,26.9 L421.6,25.7 L418.8,28.0 L420.5,34.4 L424.8,37.8 L430.7,38.5 L436.1,35.5 L437.1,32.6 L439.6,32.6 L439.6,35.5 L442.9,36.7 L442.7,39.6 L440.8,41.2 L439.3,47.2 L430.8,42.1 L429.4,45.5 L430.8,49.2 L439.8,51.2 L439.0,51.7 L439.9,53.7 L443.6,53.3 L446.7,55.6 L443.1,57.4 L445.2,62.6 L454.1,64.6 L453.9,73.5 L451.3,75.8 L455.7,82.0 L454.8,82.9 L455.4,84.0 L459.4,84.7 L455.5,85.9 L456.6,88.6 L454.9,92.4 L460.8,95.9 L461.1,98.4 Z M525.1,67.6 L524.5,68.1 L524.2,67.6 L525.1,67.6 Z M543.2,72.6 L541.9,71.0 L539.0,69.9 L538.8,71.5 L541.3,73.8 L543.2,72.6 Z",
            'TI' => "M374.8,308.7 L384.2,306.0 L387.3,310.7 L385.9,314.9 L388.0,321.4 L386.5,323.5 L387.9,329.2 L386.7,331.0 L387.1,335.6 L381.5,343.8 L385.7,351.5 L383.9,354.9 L387.0,355.8 L387.6,358.5 L397.6,362.0 L398.1,365.2 L402.4,369.7 L403.8,374.0 L408.4,376.1 L406.9,379.0 L409.5,381.3 L409.8,383.8 L415.4,383.6 L421.2,388.4 L425.7,389.5 L429.9,383.8 L432.9,388.6 L436.2,390.2 L439.8,388.6 L446.9,394.3 L447.3,397.5 L445.7,400.7 L443.1,400.9 L444.3,401.6 L437.8,409.8 L436.7,414.1 L443.8,414.3 L449.5,420.5 L453.4,420.9 L453.6,428.2 L456.4,430.3 L459.5,440.9 L461.2,441.9 L456.4,447.6 L456.2,450.1 L461.8,447.1 L465.1,449.8 L467.7,448.2 L469.3,449.1 L468.8,452.1 L475.1,451.9 L475.3,447.6 L477.6,445.3 L478.2,440.3 L479.9,440.0 L483.5,434.1 L481.6,434.6 L481.6,431.8 L476.3,428.0 L472.6,428.0 L471.8,420.5 L468.0,418.4 L469.0,416.2 L474.0,413.0 L471.1,403.2 L473.1,399.5 L481.8,396.8 L483.5,391.1 L480.8,384.7 L488.4,380.9 L494.2,372.9 L483.3,363.1 L482.1,358.5 L480.7,357.9 L481.8,352.4 L478.1,345.1 L481.3,339.9 L479.6,337.2 L480.4,333.3 L484.1,330.1 L483.1,323.0 L484.5,320.5 L483.6,318.9 L485.4,318.0 L483.5,313.2 L484.4,306.9 L482.8,303.4 L476.7,302.1 L473.4,296.0 L472.6,286.9 L475.4,279.1 L476.5,278.9 L475.0,275.0 L469.1,272.3 L463.9,274.3 L464.0,270.9 L462.3,267.7 L460.8,270.7 L457.8,269.8 L455.4,271.4 L456.9,275.7 L455.5,278.0 L445.5,283.4 L443.8,283.2 L443.5,281.6 L439.0,283.4 L431.3,280.5 L426.2,281.6 L423.1,279.3 L413.4,283.2 L411.1,280.0 L406.5,281.2 L403.8,278.7 L398.5,278.0 L395.8,280.3 L394.6,284.3 L395.6,288.9 L389.9,290.5 L383.8,298.9 L375.9,298.9 L374.8,308.7 Z M466.0,420.2 L463.2,419.6 L464.6,415.2 L466.3,415.4 L466.0,420.2 Z",
            'VD' => "M44.0,333.3 L45.8,329.6 L58.5,319.6 L72.5,316.9 L86.9,307.8 L112.0,308.3 L133.4,314.4 L143.0,325.6 L142.9,331.0 L144.8,334.0 L148.3,334.2 L150.3,336.5 L151.2,343.5 L154.9,346.9 L155.5,350.3 L159.2,354.5 L163.1,364.9 L166.2,369.0 L177.7,363.3 L180.1,360.6 L180.3,357.6 L184.3,356.3 L185.0,354.0 L190.2,349.9 L189.8,347.2 L191.5,345.8 L190.2,342.4 L195.2,336.7 L194.9,330.3 L196.6,329.2 L194.4,329.2 L193.1,325.3 L191.0,325.3 L193.5,316.9 L190.8,312.8 L191.2,311.2 L195.5,311.0 L196.9,308.3 L195.7,300.7 L197.5,300.5 L197.0,298.9 L198.7,297.8 L199.5,292.8 L197.6,285.5 L193.0,290.3 L190.7,287.1 L185.6,291.6 L183.4,291.6 L180.2,298.0 L176.5,300.7 L170.5,300.7 L165.4,305.3 L163.9,309.8 L159.4,311.9 L158.6,311.6 L159.8,309.4 L158.0,307.1 L156.9,300.1 L153.9,297.3 L151.1,297.6 L147.6,294.8 L145.1,296.2 L145.3,293.2 L139.9,299.1 L136.0,297.8 L131.9,292.1 L133.4,288.9 L138.7,288.5 L139.6,290.7 L143.3,285.0 L146.0,283.9 L146.0,281.8 L143.3,283.7 L138.4,279.1 L135.6,280.7 L132.4,278.2 L132.2,280.0 L129.4,280.7 L130.8,273.0 L130.1,263.9 L133.0,264.1 L136.1,260.7 L140.2,261.8 L141.3,258.4 L140.2,256.1 L144.1,253.6 L146.2,249.0 L151.2,244.7 L151.4,241.1 L146.9,239.0 L151.1,236.1 L152.1,233.1 L154.4,232.5 L156.0,228.8 L154.4,226.1 L155.3,223.6 L158.8,224.5 L159.8,222.4 L158.4,220.4 L159.4,219.5 L157.5,218.1 L157.5,215.6 L159.4,212.9 L156.5,209.7 L154.6,211.0 L156.5,214.2 L155.7,214.7 L151.6,209.0 L149.3,208.1 L150.3,206.7 L145.1,201.0 L140.4,204.7 L148.9,213.3 L144.4,214.7 L147.6,214.5 L150.4,217.4 L146.1,223.1 L147.4,226.5 L146.4,228.1 L148.8,227.2 L150.4,228.6 L147.4,234.0 L142.5,235.2 L139.3,232.2 L134.1,235.6 L131.0,232.7 L126.6,232.0 L127.8,228.8 L121.3,223.4 L127.1,217.4 L123.1,213.3 L119.9,214.0 L121.0,207.9 L117.4,205.4 L118.0,198.5 L106.6,204.2 L103.5,209.2 L97.3,210.3 L81.1,218.8 L77.7,217.6 L75.1,222.2 L74.8,225.8 L73.3,226.7 L73.9,229.2 L77.4,232.2 L74.5,237.9 L67.7,241.3 L66.0,245.2 L63.6,245.6 L64.2,246.8 L48.2,256.1 L41.9,264.1 L23.9,280.3 L30.8,287.5 L18.0,305.5 L20.0,310.7 L16.7,316.9 L21.9,318.5 L29.9,325.8 L31.3,325.1 L33.0,328.1 L26.0,339.0 L30.4,343.5 L37.5,346.9 L42.6,336.3 L36.5,333.3 L36.8,332.1 L33.5,330.8 L33.6,329.2 L44.0,333.3 Z M128.0,241.3 L127.4,245.0 L124.3,248.4 L122.4,245.0 L126.0,240.7 L128.0,241.3 Z M128.4,245.6 L132.7,243.6 L137.8,235.2 L139.3,238.3 L142.9,240.9 L140.2,246.5 L135.3,244.3 L129.2,246.3 L128.4,245.6 Z M33.5,334.0 L33.9,331.7 L35.1,332.8 L33.5,334.0 Z M175.1,206.0 L170.0,197.8 L170.8,190.3 L169.3,189.2 L161.4,186.9 L150.1,194.7 L155.7,201.2 L159.2,202.6 L158.1,204.7 L162.6,209.2 L160.2,211.5 L161.1,212.6 L163.5,211.0 L166.2,212.2 L165.7,214.5 L167.2,215.6 L165.6,217.9 L166.8,219.0 L171.7,213.3 L170.7,210.3 L175.1,206.0 Z",
            'VS' => "M133.4,314.4 L130.7,322.3 L130.8,325.8 L125.6,330.8 L127.8,335.8 L129.6,335.8 L130.4,338.7 L134.1,340.6 L137.1,345.4 L139.3,345.4 L140.2,347.8 L138.7,353.6 L133.4,358.8 L133.6,360.9 L130.8,365.4 L132.0,370.4 L128.7,376.5 L129.9,380.4 L132.5,382.2 L136.2,381.3 L145.5,383.4 L143.0,390.0 L144.3,394.7 L141.5,399.8 L144.3,402.0 L149.3,396.8 L151.4,396.8 L151.1,398.9 L153.4,399.8 L155.3,404.5 L158.1,407.1 L158.9,410.7 L162.8,412.3 L164.7,416.6 L162.5,418.7 L167.0,422.1 L166.6,425.7 L168.0,429.4 L173.0,435.5 L176.6,443.4 L179.3,443.4 L185.4,439.1 L190.6,443.7 L194.7,436.9 L200.7,437.1 L206.6,429.4 L221.4,434.6 L229.9,427.1 L233.9,426.6 L233.9,423.4 L238.1,420.0 L244.9,421.4 L244.7,417.1 L245.9,414.8 L249.8,414.5 L251.7,418.2 L263.6,417.1 L266.3,421.4 L270.2,422.7 L270.4,426.4 L272.2,428.9 L274.5,428.9 L276.5,425.0 L279.7,426.0 L284.5,430.5 L287.4,428.2 L295.5,429.8 L296.4,428.2 L295.0,426.0 L296.4,423.2 L296.5,417.8 L299.1,416.4 L300.9,412.7 L313.7,412.5 L315.5,408.9 L317.3,408.9 L318.2,405.9 L317.3,404.5 L320.8,401.6 L318.8,395.6 L320.6,388.6 L331.9,386.3 L333.0,382.0 L337.6,380.4 L339.3,377.9 L338.7,374.0 L340.9,370.2 L339.0,368.1 L336.9,360.1 L333.3,357.9 L332.4,354.7 L328.1,352.9 L328.7,350.8 L336.7,342.9 L340.2,344.2 L346.1,342.9 L350.1,337.4 L350.0,335.6 L356.0,332.8 L356.1,328.7 L363.7,325.8 L364.2,320.8 L360.0,318.5 L362.3,315.3 L365.7,314.9 L372.0,308.7 L374.8,308.7 L375.9,298.9 L383.8,298.9 L389.2,291.4 L381.6,288.0 L378.0,278.2 L380.2,271.4 L380.5,264.1 L377.0,262.7 L372.4,268.4 L371.1,280.0 L355.2,291.2 L343.3,293.0 L342.3,290.7 L334.8,289.4 L333.0,287.1 L317.8,283.4 L309.7,288.9 L311.3,292.3 L310.5,294.8 L306.4,296.0 L299.2,301.9 L291.2,302.8 L271.6,317.1 L257.7,310.3 L255.2,312.1 L252.7,318.0 L250.1,316.7 L243.5,318.3 L242.7,320.5 L246.7,323.0 L242.2,326.5 L236.7,327.4 L235.0,324.0 L232.4,326.0 L228.6,323.7 L222.8,325.8 L215.7,331.9 L209.7,333.3 L208.6,331.4 L208.9,326.2 L201.5,330.1 L200.6,335.8 L195.2,336.7 L190.2,342.4 L191.5,345.8 L189.8,347.2 L190.2,349.9 L185.0,354.0 L184.3,356.3 L180.3,357.6 L180.1,360.6 L177.7,363.3 L166.2,369.0 L163.1,364.9 L159.2,354.5 L155.5,350.3 L154.9,346.9 L151.2,343.5 L150.3,336.5 L148.3,334.2 L144.8,334.0 L142.9,331.0 L143.0,325.6 L133.4,314.4 Z",
            'NE' => "M167.2,188.5 L164.9,182.6 L166.3,180.1 L166.2,176.9 L174.5,171.0 L173.0,166.7 L174.5,164.4 L167.4,159.2 L165.6,160.3 L164.7,158.5 L167.2,155.7 L165.7,155.0 L151.8,159.2 L150.3,158.2 L140.6,164.6 L143.9,154.1 L139.6,146.2 L131.0,154.4 L121.0,159.2 L121.9,162.1 L120.8,163.5 L115.4,165.3 L115.7,167.1 L113.6,168.9 L117.9,172.1 L108.3,178.0 L104.6,184.4 L84.8,191.7 L83.4,189.9 L73.6,200.3 L78.3,209.0 L77.7,217.6 L78.7,218.5 L86.2,217.0 L94.7,211.5 L103.5,209.2 L106.6,204.2 L118.0,198.5 L117.4,205.4 L121.0,207.9 L119.9,214.0 L123.1,213.3 L127.1,217.4 L150.1,194.7 L161.4,186.9 L167.2,188.5 Z",
            'GE' => "M26.2,339.4 L25.3,344.5 L22.5,346.7 L25.9,354.0 L23.7,357.0 L16.5,355.6 L13.9,359.0 L12.0,357.4 L5.4,361.1 L5.7,362.7 L2.7,362.7 L2.6,365.4 L1.2,366.7 L6.0,370.0 L0.1,382.5 L5.5,378.8 L12.3,380.9 L14.4,379.5 L14.8,377.2 L20.9,377.0 L22.1,378.8 L27.7,379.5 L35.9,373.8 L35.9,370.4 L41.0,365.8 L51.8,360.9 L54.6,356.0 L54.6,353.3 L52.3,353.3 L52.3,351.2 L49.8,354.5 L46.9,354.2 L43.5,348.8 L45.8,345.6 L44.4,342.4 L40.7,340.6 L37.5,346.9 L30.4,343.5 L26.2,339.4 Z M35.1,332.8 L33.9,331.7 L32.6,333.5 L33.5,334.0 L35.1,332.8 Z M44.0,333.3 L34.5,328.5 L33.1,330.1 L42.6,336.3 L44.0,333.3 Z",
            'JU' => "M231.7,99.5 L224.9,97.5 L225.1,94.3 L222.6,93.6 L220.3,90.2 L216.2,89.5 L211.4,85.6 L211.4,83.8 L204.8,84.9 L199.0,88.3 L196.9,84.0 L191.3,84.9 L187.3,83.1 L188.5,77.4 L192.1,71.5 L185.8,72.4 L180.8,69.2 L172.6,72.8 L164.8,69.2 L158.4,71.5 L161.4,80.6 L156.5,82.2 L154.6,85.2 L151.6,85.2 L151.6,91.5 L147.8,91.7 L147.5,96.1 L143.2,99.0 L142.5,103.8 L145.1,102.3 L148.9,103.2 L160.5,101.3 L163.9,99.0 L168.6,101.5 L168.8,105.0 L170.7,105.7 L168.2,109.5 L162.6,110.2 L163.5,112.5 L162.3,115.4 L152.9,117.7 L152.0,118.8 L153.7,122.7 L152.9,126.4 L154.3,128.4 L142.7,138.4 L141.8,141.8 L136.5,145.0 L139.3,146.4 L141.3,144.3 L143.2,149.8 L150.7,145.0 L152.0,141.8 L158.0,144.3 L160.5,140.0 L165.1,138.9 L168.3,132.3 L170.2,131.4 L170.0,128.8 L173.3,127.5 L175.9,130.0 L185.2,127.5 L183.1,124.8 L187.1,121.4 L186.4,117.0 L192.9,116.6 L193.5,118.2 L204.3,119.3 L210.0,117.9 L213.1,114.5 L219.4,112.3 L236.7,115.2 L247.2,110.6 L242.6,110.4 L246.3,105.4 L244.4,105.0 L244.4,102.0 L241.7,100.8 L241.9,99.3 L231.7,99.5 Z",
        ];
    }
}
