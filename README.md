# SwissMap — Swiss SVG Map for ProcessWire

Privacy-friendly map of Switzerland with LV95 coordinate markers.  
**No Google Maps. No tracking. No GDPR issues.**

GitHub: https://github.com/mxmsmnv/SwissMap

**Author:** Maxim Semenov  
**Website:** [smnv.org](https://smnv.org)  
**Email:** [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).  

---

## Modules included

| Module | Type | Purpose |
|---|---|---|
| `FieldtypeLV95` | Fieldtype | Stores E/N coordinate pair in DB |
| `InputfieldLV95` | Inputfield | Admin input with live mini-map preview |
| `SwissMap` | Module | Renders map on frontend (SVG or Leaflet) |

---

## Drivers

SwissMap supports two rendering backends, selectable via the `driver` option.

### `svg` (default)

Self-contained SVG. Zero external requests, zero cookies, fully GDPR/nFADP-safe.

### `leaflet`

Interactive map using [Leaflet.js](https://leafletjs.com) with official **swisstopo WMTS tiles**, served from `geo.admin.ch` (Switzerland, CC BY 4.0). No Google, no US servers.

Leaflet JS/CSS are loaded from unpkg.com CDN. For full self-hosting, download Leaflet and adjust the `<link>` / `<script>` tags in `renderLeaflet()`.

---

## Installation

1. Copy the `SwissMap/` folder to `/site/modules/SwissMap/`
2. Go to **Modules → Refresh** in PW admin
3. Install **FieldtypeLV95** (InputfieldLV95 installs automatically)
4. Install **SwissMap**

---

## Setup

### 1. Add an LV95 field to a template

- Go to **Fields → Add New**
- Type: **LV95 Swiss Coordinates**
- Name: `swiss` (or anything — pass it as `coordField`)
- Add the field to your location template

### 2. Enter coordinates in the page editor

The admin shows two inputs (Easting E / Northing N) with a live mini-map preview dot.

**Finding LV95 coordinates for an address:**  
Use the official Swiss map service: https://map.geo.admin.ch  
Right-click any point → "What's here?" → copy LV95 coordinates.

**Valid range:**
- E: 2'485'000 – 2'834'000
- N: 1'075'000 – 1'296'000

Example: Bern = `E 2'600'000 / N 1'199'700`

---

## Template usage

### Single page

```php
$map = $modules->get('SwissMap');
echo $map->render([$page], [
    'coordField' => 'swiss',
    'labelField' => 'title',
]);
```

### Multiple pages

```php
$map = $modules->get('SwissMap');
echo $map->render($pages->find('template=location, swiss.e>0'), [
    'coordField' => 'swiss',
    'labelField' => 'title',
]);
```

### SVG with all options

```php
$map = $modules->get('SwissMap');
echo $map->render(
    $pages->find('template=location, swiss.e>0, sort=title'),
    [
        'coordField'   => 'swiss',
        'labelField'   => 'title',
        'linkField'    => 'url',      // false to disable links
        'markerColor'  => '#e74c3c',
        'markerRadius' => 7,
        'tooltip'      => true,
        'mapFill'      => '#dde9f4',
        'mapStroke'    => '#8aafc4',
        'width'        => 800,
    ]
);
```

### Leaflet / interactive swisstopo map

```php
$map = $modules->get('SwissMap');
echo $map->render($pages->find('template=location, swiss.e>0'), [
    'driver'     => 'leaflet',
    'coordField' => 'swiss',
    'labelField' => 'title',
    'height'     => 500,
    'zoom'       => 10,
]);
```

### Leaflet — single page

```php
$map = $modules->get('SwissMap');
echo $map->render([$page], [
    'driver'     => 'leaflet',
    'coordField' => 'swiss',
    'labelField' => 'title',
    'height'     => 400,
    'zoom'       => 13,
]);
```

### Leaflet — aerial imagery

```php
echo $map->render($pages->find('template=location, swiss.e>0'), [
    'driver'     => 'leaflet',
    'coordField' => 'swiss',
    'tileLayer'  => 'ch.swisstopo.swissimage',
    'height'     => 500,
]);
```

### Leaflet — OpenStreetMap

```php
echo $map->render($pages->find('template=location, swiss.e>0'), [
    'driver'     => 'leaflet',
    'coordField' => 'swiss',
    'tileLayer'  => 'osm',
    'height'     => 500,
]);
```

### Per-marker color (e.g. by category)

Add a text field `marker_color` to your template and enter hex values like `#27ae60`.

```php
echo $map->render($pages->find('template=location, swiss.e>0'), [
    'coordField' => 'swiss',
    'colorField' => 'marker_color',
]);
```

### Plain array (no PW pages)

```php
echo $map->render([
    ['e' => 2600000, 'n' => 1199700, 'label' => 'Bern',   'url' => '/bern'],
    ['e' => 2683000, 'n' => 1247000, 'label' => 'Zürich', 'url' => '/zurich', 'color' => '#2980b9'],
    ['e' => 2500000, 'n' => 1118000, 'label' => 'Geneva', 'url' => '/geneva'],
]);
```

---

## Coordinate system

Switzerland uses the **LV95** (CH1903+) coordinate system — meters east and north from the observatory at the University of Bern.

Reference: https://en.wikipedia.org/wiki/Swiss_coordinate_system

The SVG driver maps LV95 to pixels using linear interpolation:

```
x = (E - E_min) / (E_max - E_min) * viewBox_width
y = (1 - (N - N_min) / (N_max - N_min)) * viewBox_height
```

The Leaflet driver converts LV95 to WGS84 using the official swisstopo approximate formula (accuracy ~1 m).

---

## Canton paths

Canton paths are derived from real swisstopo boundary data (CC BY 4.0), converted from TopoJSON/WGS84 to the SVG viewBox coordinate space.  
Source: [swisstopo swissBOUNDARIES3D](https://www.swisstopo.admin.ch/en/landscape-model-swissboundaries3d)

---

## Languages

The admin input (`InputfieldLV95`) ships with translations for the five official Swiss languages:

| File | Language |
|---|---|
| `languages/en.json` | English |
| `languages/de.json` | German / Deutsch |
| `languages/fr.json` | French / Français |
| `languages/it.json` | Italian / Italiano |
| `languages/rm.json` | Romansh / Rumantsch |

The active language is detected automatically from `$user->language->name`. The language name in ProcessWire must match one of the codes above (`de`, `fr`, `it`, `rm`); the default language always maps to English.

To add a new language, copy `languages/en.json`, translate the values, and name the file after the PW language name (e.g. `languages/es.json` for a language named `es`).

---

## Options reference

### Shared options

| Option | Default | Description |
|---|---|---|
| `driver` | `'svg'` | Rendering backend: `'svg'` or `'leaflet'` |
| `coordField` | `'lv95'` | Field name storing LV95 coordinates |
| `labelField` | `'title'` | Page field used as marker label |
| `linkField` | `'url'` | Page field/property for href, `false` = no link |
| `colorField` | `''` | Optional field for per-marker hex color |
| `markerColor` | `'#e74c3c'` | Default marker fill color |
| `cssClass` | `'swissmap'` | CSS class on the root element |

### SVG driver options

| Option | Default | Description |
|---|---|---|
| `width` | `700` | SVG width in px (height scales automatically) |
| `markerRadius` | `7` | Marker circle radius in SVG units |
| `tooltip` | `true` | Show label tooltip on hover |
| `mapFill` | `'#dde9f4'` | Canton fill color |
| `mapStroke` | `'#8aafc4'` | Canton border color |
| `background` | `'none'` | SVG background |

### Leaflet driver options

| Option | Default | Description |
|---|---|---|
| `height` | `500` | Map container height in px |
| `zoom` | `9` | Initial zoom level (1–18) |
| `tileLayer` | `'ch.swisstopo.pixelkarte-farbe'` | swisstopo WMTS layer ID |
| `attribution` | `true` | Show swisstopo attribution (required by CC BY 4.0) |
| `clustering` | `false` | Group nearby markers (requires leaflet.markercluster) |
| `popupField` | `''` | Page field for popup body text |

**Available `tileLayer` values:**

| Value | Description |
|---|---|
| `ch.swisstopo.pixelkarte-farbe` | Color topo map (default) |
| `ch.swisstopo.pixelkarte-grau` | Greyscale topo map |
| `ch.swisstopo.swissimage` | Aerial / satellite imagery |
| `ch.swisstopo.landeskarte-farbe` | National map color |
| `ch.swisstopo.landeskarte-grau` | National map greyscale |
| `osm` | OpenStreetMap |

swisstopo tiles are free of charge per [geo.admin.ch Terms of Use](https://www.geo.admin.ch/en/general-terms-of-use-fsdi). Tiles are served from `wmts.geo.admin.ch` (Switzerland, CC BY 4.0).

### CSP / Content Security Policy

If your site uses a `Content-Security-Policy` header, add the tile servers to `img-src`:

```
# swisstopo tiles
Content-Security-Policy: img-src 'self' https://wmts.geo.admin.ch;

# OSM tiles
Content-Security-Policy: img-src 'self' https://*.tile.openstreetmap.org;

# Both
Content-Security-Policy: img-src 'self' https://wmts.geo.admin.ch https://*.tile.openstreetmap.org;
```

If you use **Cloudflare** and tiles show `NS_BINDING_ABORTED` in the browser console, check your Cloudflare WAF / Security Rules — add the tile domains to the allowlist, or set a Page Rule to bypass security checks for tile requests.

---

## License

MIT — Maxim Semenov / [smnv.org](https://smnv.org)  
maxim@smnv.org
