<?php

declare(strict_types=1);

use SymPress\Kernel\Admin\PackageManagerPage;
use SymPress\Kernel\Console\ContainerDumpCommand;
use SymPress\Kernel\Console\DebugContainerCommand;
use SymPress\Kernel\Console\LintContainerCommand;
use SymPress\Kernel\Package\PackageDiscovery;
use SymPress\Kernel\Resolver\ActivePackageResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $parameters = $container->parameters();
    $parameters->set('kernel.package_prefixes', []);

    $services = $container->services();
    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure();

    $services->set(ActivePackageResolver::class);
    $services->set(ContainerDumpCommand::class);
    $services->set(DebugContainerCommand::class);
    $services->set(LintContainerCommand::class);
    $services->set(PackageDiscovery::class)
        ->arg('$packagePrefixes', '%kernel.package_prefixes%')
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
