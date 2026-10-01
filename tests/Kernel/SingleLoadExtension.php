<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SingleLoadExtension extends Extension
{
    private bool $loaded = false;

    public function getAlias(): string
    {
        return 'stateful';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        if ($this->loaded) {
            throw new \RuntimeException('Extension reused between builders');
        }
        $this->loaded = true;
        $container->setParameter('extension.loaded', true);
    }
}
