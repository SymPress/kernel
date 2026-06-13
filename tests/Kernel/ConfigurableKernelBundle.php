<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class ConfigurableKernelBundle extends AbstractBundle
{
    public function __construct(
        private readonly string $bundlePath,
    ) {
    }

    public function path(): string
    {
        return $this->bundlePath;
    }

    public function loadExtension(
        array $config,
        ContainerConfigurator $configurator,
        ContainerBuilder $container,
    ): void {
        $container->setParameter('configurable_bundle.message', $config['message'] ?? 'implicit');
    }
}
