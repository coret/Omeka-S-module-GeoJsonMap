/**
 * Draws the maps described by [data-geojson-map] elements.
 *
 * Everything is read from the element's data attribute, so nothing here is tied
 * to a particular element id and any number of maps can share a page.
 */
(function () {
    'use strict';

    /** Resolve {property} placeholders against a feature's properties. */
    function fillTemplate(template, properties) {
        return template.replace(/\{([^}]+)\}/g, function (match, key) {
            var value = properties ? properties[key] : undefined;
            return (value === undefined || value === null) ? '' : String(value);
        });
    }

    /**
     * Read a property, optionally narrowing it with a regular expression.
     *
     * Collections often carry a category only inside a human-readable title —
     * "Gouda, wijk 3" — with no property of its own. A pattern recovers it:
     * the first capture group wins, or the whole match when there is no group.
     * No match yields null, which callers treat as "nothing to show".
     */
    function extract(properties, key, pattern) {
        var value = properties ? properties[key] : null;
        if (value === undefined || value === null || value === '') {
            return null;
        }
        if (!pattern) {
            return value;
        }
        try {
            var found = String(value).match(new RegExp(pattern));
            return found ? (found[1] !== undefined ? found[1] : found[0]) : null;
        } catch (e) {
            console.error('[GeoJsonMap] bad pattern: ' + pattern, e);
            return null;
        }
    }

    /** A named global function, if the site actually defined it. */
    function namedFunction(name) {
        if (!name) {
            return null;
        }
        var fn = window[name];
        if (typeof fn !== 'function') {
            console.error('[GeoJsonMap] no such function: ' + name);
            return null;
        }
        return fn;
    }

    function buildTileLayer(entry) {
        var options = entry.options || {};
        return entry.type === 'wms'
            ? L.tileLayer.wms(entry.url, options)
            : L.tileLayer(entry.url, options);
    }

    /** The style for a shape layer: custom function, property lookup, or flat. */
    function shapeStyle(layerConfig, custom) {
        if (custom) {
            return custom;
        }
        return function (feature) {
            var color = layerConfig.color || '#3388ff';
            if (layerConfig.color_property) {
                var key = extract(feature.properties, layerConfig.color_property, layerConfig.color_pattern);
                var map = layerConfig.color_map || {};
                if (key !== null && map[key]) {
                    color = map[key];
                }
            }
            return {
                color: color,
                weight: layerConfig.weight || 2,
                opacity: layerConfig.opacity !== undefined ? layerConfig.opacity : 1,
                fillColor: layerConfig.fill_color || color,
                fillOpacity: layerConfig.fill_opacity !== undefined ? layerConfig.fill_opacity : 0.2
            };
        };
    }

    /**
     * A point on the feature's surface, for text labels.
     *
     * polylabel wants the polygon rings; for anything else the bounds centre is
     * close enough and always defined.
     */
    function labelPoint(feature, layer) {
        var geometry = feature.geometry || {};
        try {
            if (typeof polylabel === 'function') {
                if (geometry.type === 'Polygon') {
                    var p = polylabel(geometry.coordinates, 1.0);
                    return L.latLng(p[1], p[0]);
                }
                if (geometry.type === 'MultiPolygon') {
                    // Label the largest part, by ring length as a cheap proxy.
                    var biggest = geometry.coordinates.reduce(function (a, b) {
                        return (b[0] || []).length > (a[0] || []).length ? b : a;
                    });
                    var q = polylabel(biggest, 1.0);
                    return L.latLng(q[1], q[0]);
                }
            }
        } catch (e) {
            console.error('[GeoJsonMap] label placement failed', e);
        }
        return layer.getBounds ? layer.getBounds().getCenter() : null;
    }

    function drawMap(element) {
        var config;
        try {
            config = JSON.parse(element.getAttribute('data-geojson-map'));
        } catch (e) {
            console.error('[GeoJsonMap] unreadable configuration', e);
            return;
        }

        var baseLayers = {};
        var firstBase = null;
        Object.keys(config.baseLayers || {}).forEach(function (id) {
            var entry = config.baseLayers[id];
            var layer = buildTileLayer(entry);
            baseLayers[entry.label || id] = layer;
            if (id === config.activeBase || !firstBase) {
                firstBase = (id === config.activeBase) ? layer : firstBase;
            }
            if (!firstBase) {
                firstBase = layer;
            }
        });

        var overlays = {};
        var startingOverlay = null;
        Object.keys(config.overlays || {}).forEach(function (id) {
            var entry = config.overlays[id];
            var layer = buildTileLayer(entry);
            overlays[entry.label || id] = layer;
            if (id === config.activeOverlay) {
                startingOverlay = layer;
            }
        });

        var startLayers = [];
        if (firstBase) {
            startLayers.push(firstBase);
        }
        if (startingOverlay) {
            startLayers.push(startingOverlay);
        }

        var map = L.map(element, {
            zoomSnap: 0,
            attributionControl: false,
            fullscreenControl: true,
            center: config.center,
            zoom: config.zoom,
            maxZoom: config.maxZoom,
            layers: startLayers
        });
        L.control.attribution({prefix: ''}).addTo(map);

        var customStyle = namedFunction(config.styleFunction);
        var customPopup = namedFunction(config.popupFunction);
        var customEach = namedFunction(config.onEachFeature);

        var dataLayers = {};
        var allBounds = null;
        // Kept for the stacked-popup lookup, which needs the real GeoJSON layers.
        var shapeLayers = [];

        (config.layers || []).forEach(function (layerConfig, index) {
            var isMarker = layerConfig.render === 'marker';
            var icon = null;
            if (isMarker && layerConfig.icon_url) {
                icon = L.icon({
                    iconUrl: layerConfig.icon_url,
                    iconSize: layerConfig.icon_size || [25, 41],
                    iconAnchor: layerConfig.icon_anchor || [12, 41],
                    popupAnchor: layerConfig.popup_anchor || [1, -24]
                });
            }

            // A cluster group needs the plugin; fall back rather than break.
            var useCluster = layerConfig.cluster && typeof L.markerClusterGroup === 'function';
            if (layerConfig.cluster && !useCluster) {
                console.error('[GeoJsonMap] clustering requested but Leaflet.markercluster is not loaded');
            }
            var container = useCluster
                ? L.markerClusterGroup({disableClusteringAtZoom: layerConfig.cluster_disable_at_zoom || 17})
                : L.layerGroup();

            var labelLayer = layerConfig.label_property ? L.layerGroup() : null;

            var geoJsonLayer = L.geoJSON(null, {
                style: isMarker ? undefined : shapeStyle(layerConfig, customStyle),
                pointToLayer: function (feature, latlng) {
                    return icon ? L.marker(latlng, {icon: icon}) : L.marker(latlng);
                },
                onEachFeature: function (feature, layer) {
                    var html = customPopup
                        ? customPopup(feature)
                        : fillTemplate(config.popupTemplate || '', feature.properties || {});
                    if (html) {
                        layer.bindPopup(html);
                    }

                    if (labelLayer) {
                        var text = extract(feature.properties, layerConfig.label_property, layerConfig.label_pattern);
                        var point = (text !== null && text !== '')
                            ? labelPoint(feature, layer)
                            : null;
                        if (point) {
                            L.marker(point, {
                                icon: L.divIcon({
                                    className: 'geojson-map-label',
                                    html: String(text),
                                    iconSize: layerConfig.label_size || [40, 30]
                                }),
                                interactive: false
                            }).addTo(labelLayer);
                        }
                    }

                    // The map is passed too: a custom handler usually needs it
                    // (to open a popup, say) and it is not a global here, since
                    // a page may hold several maps.
                    if (customEach) {
                        customEach(feature, layer, map);
                    }
                }
            });

            // No Accept header: "application/geo+json" is not CORS-safelisted,
            // so sending it turns every cross-origin request into a preflight
            // that most static hosts do not answer. It buys nothing either —
            // the API picks the format from the query string, and a static
            // file ignores the header.
            fetch(layerConfig.url)
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    geoJsonLayer.addData(data);
                    container.addLayer(geoJsonLayer);
                    shapeLayers.push(geoJsonLayer);

                    if (layerConfig.visible === false) {
                        // Registered in the control, but not switched on.
                    } else {
                        container.addTo(map);
                        if (labelLayer) {
                            labelLayer.addTo(map);
                        }
                    }

                    if (config.fitBounds) {
                        var bounds = geoJsonLayer.getBounds();
                        if (bounds.isValid()) {
                            allBounds = allBounds ? allBounds.extend(bounds) : L.latLngBounds(bounds);
                            map.fitBounds(allBounds, {maxZoom: config.maxZoom});
                        }
                    }
                })
                .catch(function (error) {
                    console.error('[GeoJsonMap] could not load ' + layerConfig.url, error);
                });

            dataLayers[layerConfig.label || ('Layer ' + (index + 1))] = container;
            if (labelLayer) {
                dataLayers[(layerConfig.label || ('Layer ' + (index + 1))) + ' — labels'] = labelLayer;
            }
        });

        // One control for base maps, historical overlays and the data layers.
        var overlayControl = Object.assign({}, overlays, dataLayers);
        if (Object.keys(baseLayers).length || Object.keys(overlayControl).length) {
            L.control.layers(baseLayers, overlayControl).addTo(map);
        }

        if (config.stackedPopups) {
            if (typeof leafletPip === 'undefined') {
                console.error('[GeoJsonMap] stacked popups requested but leaflet-pip is not loaded');
            } else {
                map.on('click', function (e) {
                    var html = '';
                    shapeLayers.forEach(function (layer) {
                        leafletPip.pointInLayer([e.latlng.lng, e.latlng.lat], layer, false)
                            .forEach(function (match) {
                                html += fillTemplate(config.popupTemplate || '', match.feature.properties || {}) + '<br>';
                            });
                    });
                    if (html) {
                        map.openPopup(html, e.latlng);
                    }
                });
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-geojson-map]').forEach(drawMap);
    });
})();
