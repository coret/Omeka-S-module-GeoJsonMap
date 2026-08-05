<?php

namespace GeoJsonMap;

return [
    'block_layouts' => [
        'factories' => [
            'geoJsonMap' => Service\BlockLayout\GeoJsonMapFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],

    'geojsonmap' => [
        // Defaults for a new block. Every one of these is overridable per block.
        'defaults' => [
            'height' => 500,
            'zoom' => 16,
            'center' => [52.011, 4.71],
            'max_zoom' => 21,
            'popup_template' => '<a href="{pid}">{title}</a>',
        ],

        // Some tile servers refuse cross-origin requests. When a layer sets
        // 'proxy' => true its URL is prefixed with this. Null disables it, and
        // any layer asking for a proxy is then skipped rather than breaking —
        // so on an installation without a proxy the overlays below simply do
        // not appear, instead of failing as unexplained blank tiles.
        'proxy' => '/omeka/map-proxy/?url=',

        // Base layers: exactly one is active at a time. OpenStreetMap is the
        // default so that the module draws something on a stock installation.
        //
        // Each entry: label, type (tile|wms), url, and options passed straight
        // to Leaflet. Add your own here; nothing below is required.
        'base_layers' => [
            'osm' => [
                'label' => 'OpenStreetMap',
                'type' => 'tile',
                'url' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                'options' => [
                    'maxZoom' => 19,
                    'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                ],
            ],
        ],

        // Overlays: any number may be switched on, and a block may name one to
        // start with. The entries below are the historical maps Gouda
        // Tijdmachine uses; they are a worked example of both types and of the
        // proxy flag. Replace them with your own.
        'overlays' => [
            'hisgis' => [
                'label' => 'HISGIS minuutplannen',
                'type' => 'tile',
                'url' => 'https://tileserver.huc.knaw.nl/{z}/{x}/{y}',
                'options' => ['minZoom' => 10, 'maxZoom' => 21, 'attribution' => 'KNAW/HUC'],
            ],
            'kadastraal_1832' => [
                'label' => 'Kadastrale kaart 1832',
                'type' => 'wms',
                'url' => 'https://gis.gouda.nl/geoserver/CHRASTER/wms?',
                'proxy' => true,
                'options' => [
                    'layers' => 'Kadastrale_kaart_1832',
                    'version' => '1.1.1',
                    'transparent' => true,
                    'format' => 'image/png',
                    'attribution' => "<a href='https://gis.gouda.nl'>Gemeente Gouda</a>",
                ],
            ],
            'deventer_1572' => [
                'label' => 'Jacob van Deventer 1572',
                'type' => 'wms',
                'url' => 'https://gis.gouda.nl/geoserver/CHRASTER/wms?',
                'proxy' => true,
                'options' => [
                    'layers' => 'Jacob_van_Deventer',
                    'version' => '1.1.1',
                    'transparent' => true,
                    'format' => 'image/png',
                    'attribution' => "<a href='https://gis.gouda.nl'>Gemeente Gouda</a>",
                ],
            ],
            'percelen_brk' => [
                'label' => 'Kadastrale percelen (BRK)',
                'type' => 'wms',
                'url' => 'https://service.pdok.nl/kadaster/cp/wms/v1_0?',
                'options' => [
                    'layers' => 'CP.CadastralParcel',
                    'version' => '1.3.0',
                    'transparent' => true,
                    'format' => 'image/png',
                    'attribution' => "<a href='https://www.pdok.nl/'>PDOK</a>",
                ],
            ],
        ],
    ],
];
