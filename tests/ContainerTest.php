<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests;

use SymPress\Kernel\App;
use SymPress\Kernel\Container;
use SymPress\Kernel\Kernel\KernelInterface;
use SymPress\Kernel\Location\Locations;
use SymPress\Kernel\SiteConfig;
use SymPress\Kernel\WpContext;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;

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

    private function container(): Container
    {
        $locations = $this->createMock(Locations::class);
        $config = new class($locations) implements SiteConfig {
            public function __construct(
                private readonly Locations $locations,
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
                return 'test';
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

        return new Container($config, WpContext::new()->force(WpContext::CORE));
    }
}

final class RuntimeContainer implements ContainerInterface
{
    /**
     * @var array<string, mixed>
     */
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
