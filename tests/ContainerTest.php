<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use SymPress\Kernel\App;
use SymPress\Kernel\Container;
use SymPress\Kernel\Kernel\KernelInterface;
use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;

final class ContainerTest extends TestCase
{
    public function testBuilderIsHydratedWithSyntheticCoreServices(): void
    {
        $container = $this->container();

        self::assertTrue($container->has(Container::CONTAINER_ID));
        self::assertTrue($container->has(Container::CONFIG_ID));
        self::assertTrue($container->has(Container::CONTEXT_ID));
        self::assertSame($container, $container->get(Container::CONTAINER_ID));
        self::assertInstanceOf(SiteConfig::class, $container->get(Container::CONFIG_ID));
        self::assertInstanceOf(WpContext::class, $container->get(Container::CONTEXT_ID));
    }

    public function testRuntimeContainerGetsHydratedWhenAttached(): void
    {
        $container = $this->container();
        $runtime = new RuntimeContainer();

        $container->useRuntimeContainer($runtime);

        self::assertSame($container, $runtime->get(Container::CONTAINER_ID));
        self::assertSame($container->config(), $runtime->get(Container::CONFIG_ID));
        self::assertSame($container->context(), $runtime->get(Container::CONTEXT_ID));
    }

    public function testAppAndKernelAreHydratedAfterRegistration(): void
    {
        $container = $this->container();
        $kernel = $this->createMock(KernelInterface::class);
        $app = App::new($kernel);

        $container->setKernel($kernel);
        $container->setApp($app);

        self::assertSame($kernel, $container->get(Container::KERNEL_ID));
        self::assertSame($app, $container->get(Container::APP_ID));
    }

    public function testWithSiteConfigUsesIndependentBuilder(): void
    {
        $container = $this->container();
        $configured = $container->withSiteConfig($this->siteConfig('production'));

        $configured->setParameter('demo.value', 'configured');

        self::assertSame('test', $container->getParameter('kernel.environment'));
        self::assertSame('production', $configured->getParameter('kernel.environment'));
        self::assertFalse($container->hasParameter('demo.value'));
        self::assertSame('configured', $configured->getParameter('demo.value'));
    }

    public function testParametersCannotBeMutatedAfterRuntimeContainerIsAttached(): void
    {
        $container = $this->container();
        $container->useRuntimeContainer(new RuntimeContainer());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot set parameter');

        $container->setParameter('demo.value', 'late');
    }

    private function container(): Container
    {
        return new Container($this->siteConfig(), WpContext::new()->force(WpContext::CORE));
    }

    private function siteConfig(string $environment = 'test'): SiteConfig
    {
        $locations = $this->createMock(Locations::class);
        return new class ($locations, $environment) implements SiteConfig {
            public function __construct(
                private readonly Locations $locations,
                private readonly string $environment,
            ) {
            }

            public function locations(): Locations
            {
                return $this->locations;
            }

            public function hosting(): string
            {
                return self::HOSTING_OTHER;
            }

            public function hostingIs(string $hosting): bool
            {
                return $this->hosting() === $hosting;
            }

            public function env(): string
            {
                return $this->environment;
            }

            public function envIs(string $env): bool
            {
                return $this->env() === $env;
            }

            public function get(string $name, mixed $default = null): mixed
            {
                return $default;
            }

            public function jsonSerialize(): array
            {
                return [];
            }
        };
    }
}

final class RuntimeContainer implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $services = [];

    public function set(string $id, mixed $service): void
    {
        $this->services[$id] = $service;
    }

    public function get(string $id): mixed
    {
        return $this->services[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
