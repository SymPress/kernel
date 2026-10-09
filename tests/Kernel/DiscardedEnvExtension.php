<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class DiscardedEnvExtension extends Extension
{
    public function getAlias(): string
    {
        return 'discarded';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new class implements ConfigurationInterface {
            public function getConfigTreeBuilder(): TreeBuilder
            {
                $tree = new TreeBuilder('discarded');
                $tree->getRootNode()->children()->scalarNode('value')->end()->end();
                return $tree;
            }
        };
        $this->processConfiguration($configuration, $configs);
    }
}
