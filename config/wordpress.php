<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $parameters = $container->parameters();
    $parameters->set('kernel.cache_namespace', 'kernel');
};
