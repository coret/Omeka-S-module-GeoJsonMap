# GeoJSON Map

Omeka S module adding a page block that draws one or more GeoJSON collections on a
[Leaflet](https://leafletjs.com/) map — configured rather than hand-written.

A layer names **any URL** returning an RFC 7946 `FeatureCollection` — a file on your own site,
or a collection published anywhere else. Nothing else is required.

It can also name an Omeka **item set** instead, which the companion
[GeoJson module](https://github.com/coret/Omeka-S-module-GeoJson) renders as GeoJSON. That
module is optional: install it if you want to map your own collections rather than files.

## Why

This module exists because 41 pages on [Gouda Tijdmachine](https://www.goudatijdmachine.nl/)
each hand-wrote the same map into an HTML block: include Leaflet, drop a `<div id="map">`, then
20–70 lines of bespoke JavaScript. Changing anything meant editing 41 copies, and no page could
show two maps because every script assumed a single element called `map`.

Everything those scripts varied is a setting here.

## The block

Add **GeoJSON map** to a page. The only required field is **Layers**, a JSON array with one
object per layer:

```json
[
  {
    "geojson_url": "/files/windmills.geojson",
    "label": "Molens",
    "render": "marker",
    "icon_url": "/files/geo/molen.png",
    "icon_size": [20, 20],
    "icon_anchor": [10, 8],
    "cluster": true
  }
]
```

| Key | Meaning |
|---|---|
| `geojson_url` | What to draw: any URL returning an RFC 7946 `FeatureCollection` — a file on this site, or a collection published anywhere else. Needs nothing but this module. |
| `item_set_id` *or* `query` | What to draw, as Omeka resources instead. Requires the [GeoJson](https://github.com/coret/Omeka-S-module-GeoJson) module, which renders the query as GeoJSON. `query` takes any Omeka API query string, so `resource_class_id=42&property[0][property]=1` works as well as an item set. |
| `label` | Name in the layer control. A layer without one is drawn on the map but is not listed in the control, so visitors cannot switch it off |
| `render` | `shape` (default), `marker`, or `label` — the last draws only the text label, with nothing beneath it |
| `color` | Shape colour |
| `color_property` + `color_map` | Colour looked up from a property value, e.g. `"color_property": "status"` with `{"restant": "#e81123"}` |
| `weight`, `opacity`, `fill_color`, `fill_opacity` | Shape styling |
| `icon_url`, `icon_size`, `icon_anchor`, `popup_anchor` | Marker styling |
| `cluster`, `cluster_disable_at_zoom`, `cluster_options` | Group nearby markers. Without `cluster_disable_at_zoom`, Leaflet.markercluster’s own default applies; `cluster_options` is passed through to it verbatim |
| `label_property` | Draw a text label on each shape from this property |
| `label_class`, `label_anchor`, `label_size` | Styling hooks for the label element |
| `label_pattern` | Regular expression narrowing that label; the first capture group wins. `"wijk (\\d+)"` turns *Gouda, wijk 3* into *3* |
| `visible` | `false` registers the layer in the control without switching it on. Needs a `label`, since that is what puts it in the control |

Because layers are a list, a map with thirteen sources is thirteen entries rather than thirteen
copies of a script.

### Popups

A template with `{property}` placeholders, resolved against each feature. The default is:

```html
<a href="{pid}">{title}</a>
```

A placeholder with no value renders empty rather than `undefined`, so
`<a href="{pid}">{title}<br><img src="{afbeelding}"></a>` degrades quietly on features with no
image.

### The rest

**Height**, **zoom**, **centre**, **base layer** and an **overlay** shown initially; **zoom to
fit the data**; and **list every feature under a click** — for overlapping shapes, one popup
naming all of them instead of only the topmost.

### When configuration is not enough

Three optional fields name global JavaScript functions your site provides:
`style_function(feature)`, `popup_function(feature)` and
`on_each_feature(feature, layer, map)`. A name that does not resolve is reported to the console
and ignored rather than breaking the map.

The map is passed as an argument because it is deliberately **not** a global — a page may hold
several maps, and each keeps its own.

## Example

[Gouda Tijdmachine](https://www.goudatijdmachine.nl/) runs this module in production: some
forty of its pages draw their maps with this block. Five of them, live, from the plainest to
the most involved:

- **Bruggen** (bridges) &raquo; 109 points with a custom marker icon —
  <https://www.goudatijdmachine.nl/omeka/s/data/page/bruggen>
- **Hofjes** (almshouse courtyards) &raquo; polygons in a single colour —
  <https://www.goudatijdmachine.nl/omeka/s/data/page/hofjes>
- **Gebouwen en kunstwerken** (buildings and structures) &raquo; **thirteen** layers, each its
  own source, colour and entry in the layer control —
  <https://www.goudatijdmachine.nl/omeka/s/data/page/gebouwen>
- **Referentie locatiepunten** (location points) &raquo; clustered markers, and a click that
  looks up which resources refer to the point —
  <https://www.goudatijdmachine.nl/omeka/s/data/page/referentie-locatiepunten>
- **Verpondingen** (property-tax numbers) &raquo; 3570 polygons, each labelled with its number,
  and a search box that opens one by number —
  <https://www.goudatijdmachine.nl/omeka/s/data/page/verpondingen>

The first three are configuration only. The last two add one function each, in a plain HTML
block on the same page, named by the map block's **each-feature function** setting.

### Looking something up when a feature is clicked

*Referentie locatiepunten* sets `on_each_feature` to `locatiepuntClick`. The feature's `id` is
its API URL, so the handler fetches it and lists the resources that point at this location
through `geo:hasGeometry`:

```js
function locatiepuntClick(feature, layer, map) {
	layer.on('click', function (e) {
		var url = e.target.feature.id;
		var title = e.target.feature.properties.title;
		var lat = e.latlng.lat;
		var lng = e.latlng.lng;
		fetch(url)
			.then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP error! Status: ' + response.status);
				}
				return response.json();
			})
			.then(function (data) {
				if (data['@reverse'] && data['@reverse']['geo:hasGeometry']) {
					var geometries = data['@reverse']['geo:hasGeometry'];
					var info = "<h4 style='margin:0'>Locatiepunt <a href='https://www.goudatijdmachine.nl/omeka/s/data/item/" + data['o:id'] + "'>" + title + "</a></h4><ul>";
					geometries.forEach(function (item) {
						var href = item['@id'].replace('/api/resources/', '/s/data/item/');
						info += '<li><a href="' + href + '">' + item['o:title'] + '</a></li>';
					});
					map.openPopup(info + '</ul>', [lat, lng]);
				}
			})
			.catch(function (error) {
				console.error('Error fetching or processing data:', error);
			});
	});
}
```

### Reaching a feature from outside the map

*Verpondingen* has a search box above the map: type a number, and that polygon's popup opens.
The block cannot express that, but it does not need to — it hands every feature to
`registerVerponding`, which keeps a registry the input reads. Because the block already binds
the popup from the popup template, the function only has to remember the layer:

```js
var verpondingen = [];

function registerVerponding(feature, layer, map) {
	if (feature.properties && feature.properties.title) {
		verpondingen[feature.properties.title.substring(11)] = layer;
	}
}

function popupverponding() {
	var sp = document.getElementById("showverponding").value;
	if (verpondingen[sp]) {
		verpondingen[sp].openPopup();
	}
}
```

The number in the label comes from configuration rather than code — the titles read
*Verponding 3773*, so the layer sets `"label_property": "title"` with
`"label_pattern": "^Verponding (.+)$"`. The *Waterlopen* page uses the same two-part pattern,
keyed on the name instead of a number.

## Base layers and overlays

`config/module.config.php` holds the catalogue. OpenStreetMap ships as the default base layer,
so the module draws something on a stock installation. Each entry is a Leaflet tile or WMS
layer:

```php
'overlays' => [
    'hisgis' => [
        'label' => 'HISGIS minuutplannen',
        'type'  => 'tile',                       // or 'wms'
        'url'   => 'https://tileserver.huc.knaw.nl/{z}/{x}/{y}',
        'options' => ['minZoom' => 10, 'maxZoom' => 21, 'attribution' => 'KNAW/HUC'],
    ],
],
```

The shipped overlays are Gouda Tijdmachine's historical maps — treat them as a worked example
of both types and replace them with your own.

Some tile servers refuse cross-origin requests. An entry may set `'proxy' => true`, which
prefixes its URL with the configured `proxy`. When no proxy is configured such a layer is
skipped, so it is absent from the control rather than failing as unexplained blank tiles.

## Requirements

| | |
|---|---|
| Omeka S | `^4.0.0` |
| PHP | 8.1 or later |
| Modules | none required. [GeoJson](https://github.com/coret/Omeka-S-module-GeoJson) is needed **only** for layers that name an `item_set_id` or a `query`; a layer with a `geojson_url` needs nothing. |

A layer's URL is fetched by the browser, so a **cross-origin** one must send
`Access-Control-Allow-Origin`. Serving the file from your own site — `/files/…` — sidesteps
that entirely, and Omeka's own API already sends the header.

No Composer dependencies. The JavaScript libraries are bundled; nothing is fetched from a CDN
at runtime, so the module works on an isolated network and adds no third-party requests to your
visitors' browsers.

Only what a block uses is loaded: clustering, point-in-polygon and label placement are each
requested only by the blocks that need them.

| Bundled | Licence | Used for |
|---|---|---|
| [Leaflet](https://leafletjs.com/) | BSD-2-Clause | the map |
| [Leaflet.fullscreen](https://github.com/brunob/leaflet.fullscreen) | MIT | the fullscreen control |
| [Leaflet.markercluster](https://github.com/Leaflet/Leaflet.markercluster) | MIT | `cluster` |
| [leaflet-pip](https://github.com/mapbox/leaflet-pip) | BSD-2-Clause | listing every feature under a click |
| [polylabel](https://github.com/mapbox/polylabel) + [tinyqueue](https://github.com/mourner/tinyqueue) | ISC | placing a label inside a shape |

Licence texts are in each directory under `asset/vendor/`.

## Installation

1. Unzip this module into `modules/`, so it lives at `modules/GeoJsonMap/`, or:

```bash
cd /path/to/omeka-s/modules
git clone https://github.com/coret/Omeka-S-module-GeoJsonMap.git GeoJsonMap
```

2. In the Omeka S admin, go to **Modules**, find **GeoJSON Map** and click **Install**.

The directory name **must** be `GeoJsonMap` — Omeka S resolves modules by directory name, and it
has to match the namespace. Note this differs from the repository name, so keep the trailing
argument on the clone.

## Code style

Follows Omeka S core's own rules; `.php-cs-fixer.dist.php` is core's rule set with a finder
suited to a module. See the [contributing guide](https://omeka.org/s/docs/developer/contributing/).

```bash
php /path/to/omeka-s/vendor/bin/php-cs-fixer fix
```

## License

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either **version 3 of the License, or (at your option) any later
version**. The full text is in [`LICENSE`](LICENSE).

GPL-3.0 matches Omeka S itself, which this module is a part of at runtime.

The bundled libraries keep their own licences, listed above — BSD-2-Clause, MIT
and ISC, all compatible with the above. Each ships its own licence file beside
it in `asset/vendor/`.
