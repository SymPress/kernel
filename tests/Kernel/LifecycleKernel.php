<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\BundleInterface;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Container;
use SymPress\Kernel\Kernel\KernelInterface;
use SymPress\Kernel\Tests\Support\TestSiteConfig;
use SymPress\Kernel\WpContext;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class LifecycleKernel implements KernelInterface
{
    private ?Container $container = null;

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getEnvironment(): string
    {
        return 'test';
    }

    public function isDebug(): bool
    {
        return false;
    }

    public function getCharset(): string
    {
        return 'UTF-8';
    }

    public function getCacheDir(): string
    {
        return sprintf('%s/var/cache/test/kernel', $this->projectDir);
    }

    public function getBuildDir(): string
    {
        return $this->getCacheDir();
    }

    public function getShareDir(): ?string
    {
        return $this->getCacheDir();
    }

    public function getLogDir(): ?string
    {
        return sprintf('%s/var/log', $this->projectDir);
    }

    public function getStartTime(): float
    {
        return -\INF;
    }

    public function getContainer(): Container
    {
        if (!$this->container instanceof Container) {
            throw new \LogicException('Cannot retrieve the container from a non-booted kernel.');
        }

        return $this->container;
    }

    /** @return array<string, BundleInterface> */
    public function getBundles(): array
    {
        return [];
    }

    public function getBundle(string $name): BundleInterface
    {
        throw new \InvalidArgumentException(sprintf('Bundle "%s" does not exist or it is not enabled.', $name));
    }

    public function registerBundles(): iterable
    {
        return [];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
    }

    public function handle(
        Request $request,
        int $type = HttpKernelInterface::MAIN_REQUEST,
        bool $catch = true,
    ): Response {
        return new Response();
    }

    public function locateResource(string $name): string
    {
        throw new \InvalidArgumentException(sprintf('Unable to find file "%s".', $name));
    }

    public function createContainer(): Container
    {
        $container = new Container(new TestSiteConfig('test'), WpContext::new()->force(WpContext::CORE));
        $container->setKernel($this);
        $this->container = $container;

        return $container;
    }

    public function discoverBundles(): BundleRegistry
    {
        return new BundleRegistry();
    }

    public function tryUseRuntimeContainer(Container $container, BundleRegistry $bundles): bool
    {
        return false;
    }

    public function configureContainer(
        ContainerBuilder $builder,
        Container $container,
        BundleRegistry $bundles,
    ): array {

        return [];
    }

    public function build(ContainerBuilder $builder): void
    {
    }

    public function createRuntimeContainer(
        Container $container,
        BundleRegistry $bundles,
        array $configFiles,
    ): void {
    }

    public function boot(?Container $container = null, ?BundleRegistry $bundles = null): void
    {
    }

    public function shutdown(): void
    {
    }
}
