<?php

namespace GeoJsonMap;

use Omeka\Module\AbstractModule;

/**
 * Draws GeoJSON collections on a Leaflet map through a configurable page block.
 *
 * The data comes from the GeoJson module's API output format, so this module
 * only concerns itself with presentation.
 *
 * @copyright Bob Coret, 2026
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 */
class Module extends AbstractModule
{
    public function getConfig()
    {
        return include sprintf('%s/config/module.config.php', __DIR__);
    }
}
