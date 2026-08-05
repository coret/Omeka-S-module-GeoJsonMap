<?php

namespace GeoJsonMap\Service\BlockLayout;

use GeoJsonMap\Site\BlockLayout\GeoJsonMap;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GeoJsonMapFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $config = $services->get('Config');
        return new GeoJsonMap(
            $config['geojsonmap'] ?? [],
            $services->get('Omeka\ModuleManager')
        );
    }
}
