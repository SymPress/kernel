<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ResourceTagCollectingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $resources = [];

        foreach ($container->findTaggedResourceIds('kernel_fixture.resource') as $id => $tags) {
            $resources[$id] = $tags;
        }

        $container->setParameter('kernel_fixture.resource_tags', $resources);
    }
}
