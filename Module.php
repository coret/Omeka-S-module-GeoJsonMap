<?php

namespace GeoJsonMap;

use Omeka\Module\AbstractModule;

/**
 * Draws GeoJSON collections on a Leaflet map through a configurable page block.
 *
 * The data comes from the GeoJson module's API output format, so this module
 * only concerns itself with presentation.
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include sprintf('%s/config/module.config.php', __DIR__);
    }
}
