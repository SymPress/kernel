<?php

declare(strict_types=1);

namespace SymPress\Kernel\Console;

use SymPress\Kernel\Container;
use SymPress\Kernel\Kernel\KernelInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @internal */
final class ConfiguredBuilder
{
    /**
     * A warm cache hit skips configuration, leaving only the bootstrap builder. Inspection
     * commands then configure a fresh builder on a clone, so the live container stays as is.
     * shortcut: listeners of kernel.before_container_build/container_configured do not run
     * for it; dispatch them here if a bundle starts registering definitions through them.
     */
    public static function of(Container $container): ContainerBuilder
    {
        $builder = $container->builder();
        $kernel = $container->has(Container::KERNEL_ID) ? $container->get(Container::KERNEL_ID) : null;
        if ($builder->hasParameter('kernel.project_dir') || !$kernel instanceof KernelInterface) {
            return $builder;
        }

        $fresh = clone $container;
        $kernel->configureContainer($fresh->builder(), $fresh, $kernel->discoverBundles());

        return $fresh->builder();
    }
}
