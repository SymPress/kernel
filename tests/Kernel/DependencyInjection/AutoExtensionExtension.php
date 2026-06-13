<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class AutoExtensionExtension extends Extension
{
    public function getAlias(): string
    {
        return 'auto_extension';
    }

    /** @param array<int, array<string, mixed>> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = array_replace(['message' => 'default'], ...$configs);
        $container->setParameter('auto_extension.message', $config['message']);
    }
}
