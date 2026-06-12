<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;

interface KernelInterface
{
    public function getProjectDir(): string;

    public function getEnvironment(): string;

    public function isDebug(): bool;

    public function getCacheDir(): string;

    public function createContainer(): Container;

    public function discoverBundles(): BundleRegistry;

    public function tryUseRuntimeContainer(Container $container, BundleRegistry $bundles): bool;

    /** @return array<int, string> */
    public function configureContainer(
        ContainerBuilder $builder,
        Container $container,
        BundleRegistry $bundles,
    ): array;

    public function build(ContainerBuilder $builder): void;

    /** @param array<int, string> $configFiles */
    public function createRuntimeContainer(
        Container $container,
        BundleRegistry $bundles,
        array $configFiles,
    ): void;

    public function boot(Container $container, BundleRegistry $bundles): void;

    public function shutdown(): void;
}
