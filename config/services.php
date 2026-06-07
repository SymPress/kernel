<?php

declare(strict_types=1);

use SymPress\Kernel\Admin\PackageManagerPage;
use SymPress\Kernel\Package\PackageDiscovery;
use SymPress\Kernel\Resolver\ActivePackageResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure();

    $services->set(ActivePackageResolver::class);
    $services->set(PackageDiscovery::class)
        ->public();

    $services->set(PackageManagerPage::class)
        ->tag(
            'kernel.hook',
            [
                'hook' => 'admin_menu',
                'method' => 'register',
            ],
        );
};
