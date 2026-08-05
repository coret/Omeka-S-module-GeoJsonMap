<?php

namespace GeoJsonMap\Site\BlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Entity\SitePageBlock;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Omeka\Stdlib\ErrorStore;

/**
 * A page block that draws one or more GeoJSON collections on a Leaflet map.
 *
 * The block owns its map, so several may appear on one page. Everything the
 * hand-written map scripts varied — sources, colours, icons, popups, labels,
 * clustering — is configuration here; anything genuinely site-specific is
 * reachable through the named-callback escape hatches.
 */
class GeoJsonMap extends AbstractBlockLayout
{
    /**
     * @var array The 'geojsonmap' configuration array
     */
    protected $settings;

    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function getLabel()
    {
        return 'GeoJSON map'; // @translate
    }

    public function prepareRender(PhpRenderer $view)
    {
        // Base assets every map needs. The optional libraries are added per
        // block in render(), so a plain map does not pay for clustering,
        // point-in-polygon or centroid maths it never uses.
        $view->headLink()->appendStylesheet($view->assetUrl('vendor/leaflet/leaflet.css', 'GeoJsonMap'));
        $view->headLink()->appendStylesheet($view->assetUrl('vendor/leaflet-fullscreen/Control.FullScreen.css', 'GeoJsonMap'));
        $view->headLink()->appendStylesheet($view->assetUrl('css/geo-json-map.css', 'GeoJsonMap'));

        $view->headScript()->appendFile($view->assetUrl('vendor/leaflet/leaflet.js', 'GeoJsonMap'));
        $view->headScript()->appendFile($view->assetUrl('vendor/leaflet-fullscreen/Control.FullScreen.js', 'GeoJsonMap'));
        $view->headScript()->appendFile($view->assetUrl('js/geo-json-map.js', 'GeoJsonMap'));
    }

    public function onHydrate(SitePageBlock $block, ErrorStore $errorStore)
    {
        $data = $block->getData();

        // The layers are edited as JSON, so a typo must be reported rather
        // than silently stored and then failing in the browser.
        $layers = trim((string) ($data['layers'] ?? ''));
        if ('' === $layers) {
            $errorStore->addError('layers', 'Define at least one layer.'); // @translate
        } else {
            $decoded = json_decode($layers, true);
            if (!is_array($decoded)) {
                $errorStore->addError('layers', sprintf(
                    'The layers are not valid JSON: %s', // @translate
                    json_last_error_msg()
                ));
            } else {
                foreach ($decoded as $index => $layer) {
                    if (!is_array($layer) || (!isset($layer['item_set_id']) && !isset($layer['query']))) {
                        $errorStore->addError('layers', sprintf(
                            'Layer %d needs an "item_set_id" or a "query".', // @translate
                            $index + 1
                        ));
                    }
                }
                // Store it normalised, so the view never re-parses free text.
                $data['layers'] = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        $block->setData($data);
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        ?SitePageRepresentation $page = null,
        ?SitePageBlockRepresentation $block = null
    ) {
        $defaults = $this->settings['defaults'] ?? [];
        $data = $block ? $block->data() : [];

        return $view->partial('common/block-layout/geo-json-map-form', [
            'data' => $data,
            'defaults' => $defaults,
            'baseLayers' => $this->settings['base_layers'] ?? [],
            'overlays' => $this->settings['overlays'] ?? [],
        ]);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block)
    {
        $data = $block->data();
        $defaults = $this->settings['defaults'] ?? [];

        $layers = json_decode((string) ($data['layers'] ?? '[]'), true);
        if (!is_array($layers)) {
            $layers = [];
        }

        // Resolve each layer's source to a URL now, so the browser does not
        // need to know how the GeoJson module's API is addressed.
        foreach ($layers as $index => $layer) {
            $layers[$index]['url'] = $this->buildUrl($view, $layer);
        }

        // Only pull in the libraries this particular map actually needs.
        $needsCluster = (bool) array_filter($layers, fn ($l) => !empty($l['cluster']));
        $needsLabels = (bool) array_filter($layers, fn ($l) => !empty($l['label_property']));
        $needsPip = !empty($data['stacked_popups']);

        if ($needsCluster) {
            $view->headLink()->appendStylesheet($view->assetUrl('vendor/markercluster/MarkerCluster.css', 'GeoJsonMap'));
            $view->headLink()->appendStylesheet($view->assetUrl('vendor/markercluster/MarkerCluster.Default.css', 'GeoJsonMap'));
            $view->headScript()->appendFile($view->assetUrl('vendor/markercluster/leaflet.markercluster.js', 'GeoJsonMap'));
        }
        if ($needsPip) {
            $view->headScript()->appendFile($view->assetUrl('vendor/leaflet-pip/leaflet-pip.min.js', 'GeoJsonMap'));
        }
        if ($needsLabels) {
            $view->headScript()->appendFile($view->assetUrl('vendor/polylabel/polylabel.js', 'GeoJsonMap'));
        }

        $center = $data['center'] ?? '';
        $center = $center
            ? array_map('floatval', array_map('trim', explode(',', $center)))
            : ($defaults['center'] ?? [0, 0]);

        $config = [
            'height' => (int) ($data['height'] ?? $defaults['height'] ?? 500),
            'zoom' => (float) ($data['zoom'] ?? $defaults['zoom'] ?? 16),
            'maxZoom' => (int) ($defaults['max_zoom'] ?? 21),
            'center' => $center,
            'baseLayers' => $this->resolveCatalogue($this->settings['base_layers'] ?? []),
            'overlays' => $this->resolveCatalogue($this->settings['overlays'] ?? []),
            'activeBase' => $data['base_layer'] ?? array_key_first($this->settings['base_layers'] ?? []) ?: null,
            'activeOverlay' => $data['overlay'] ?? null,
            'fitBounds' => !empty($data['fit_bounds']),
            'stackedPopups' => $needsPip,
            'popupTemplate' => $data['popup_template'] ?? $defaults['popup_template'] ?? '',
            'styleFunction' => $data['style_function'] ?? '',
            'popupFunction' => $data['popup_function'] ?? '',
            'onEachFeature' => $data['on_each_feature'] ?? '',
            'layers' => array_values($layers),
        ];

        return $view->partial('common/block-layout/geo-json-map', [
            'block' => $block,
            'config' => $config,
        ]);
    }

    /**
     * Turn a layer's source into the GeoJson module's API URL.
     *
     * A layer names either an item set or a full API query; either way the
     * format argument is what makes the GeoJson module answer.
     *
     * @return string
     */
    protected function buildUrl(PhpRenderer $view, array $layer)
    {
        if (isset($layer['url'])) {
            return $layer['url'];
        }
        $query = [];
        if (isset($layer['query'])) {
            parse_str(ltrim((string) $layer['query'], '?'), $query);
        }
        if (isset($layer['item_set_id'])) {
            $query['item_set_id'] = $layer['item_set_id'];
        }
        $query['format'] = 'geojson';

        return $view->url('api/default', ['resource' => 'items'], ['query' => $query]);
    }

    /**
     * Apply the proxy prefix to catalogue entries that ask for one.
     *
     * A layer that needs a proxy when none is configured is dropped rather
     * than left to fail as an opaque tile error in the browser.
     *
     * @return array
     */
    protected function resolveCatalogue(array $catalogue)
    {
        $proxy = $this->settings['proxy'] ?? null;
        $resolved = [];
        foreach ($catalogue as $id => $entry) {
            if (!empty($entry['proxy'])) {
                if (!$proxy) {
                    continue;
                }
                $entry['url'] = $proxy . $entry['url'];
            }
            unset($entry['proxy']);
            $resolved[$id] = $entry;
        }
        return $resolved;
    }
}
