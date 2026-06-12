<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Hook;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\Bundle\AbstractBundle;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Container;
use SymPress\Kernel\Hook\HookLoader;
use SymPress\Kernel\Kernel\AbstractKernel;
use SymPress\Kernel\Tests\Support\TestSiteConfig;
use SymPress\Kernel\WpContext;

final class AsHookAttributeTest extends TestCase
{
    public function testClassAndMethodHookAttributesAreCompiledFromResourceServices(): void
    {
        $container = $this->compileFixture('HookBundle');
        $loader = $container->get(HookLoader::class);

        self::assertInstanceOf(HookLoader::class, $loader);

        $reflection = new \ReflectionObject($loader);
        $property = $reflection->getProperty('hooks');
        $hooks = array_values(
            array_filter(
                $property->getValue($loader),
                static fn (array $hook): bool => in_array(
                    $hook['hook'] ?? '',
                    ['plugins_loaded', 'admin_init'],
                    true,
                ),
            ),
        );

        self::assertCount(2, $hooks);
        self::assertSame('plugins_loaded', $hooks[0]['hook']);
        self::assertSame('__invoke', $hooks[0]['method']);
        self::assertSame(9, $hooks[0]['priority']);
        self::assertSame(0, $hooks[0]['accepted_args']);
        self::assertSame('admin_init', $hooks[1]['hook']);
        self::assertSame('register', $hooks[1]['method']);
        self::assertSame(20, $hooks[1]['priority']);
        self::assertSame(2, $hooks[1]['accepted_args']);
    }

    private function compileFixture(string $fixture): Container
    {
        $fixturePath = dirname(__DIR__) . sprintf('/Fixtures/%s', $fixture);
        $registry = (new BundleRegistry())->add(
            new BundleMetadata(
                'sympress/' . strtolower($fixture),
                'wordpress-plugin',
                strtolower($fixture) . '/' . strtolower($fixture) . '.php',
                $fixturePath,
                sprintf('%s/composer.json', $fixturePath),
                new class ($fixturePath) extends AbstractBundle {
                    public function __construct(
                        private readonly string $fixturePath,
                    ) {
                    }

                    public function path(): string
                    {
                        return $this->fixturePath;
                    }
                },
            ),
        );
        $kernel = new class (
            dirname(__DIR__, 2),
            'test',
            false,
            new TestSiteConfig('test'),
            WpContext::new()->force(WpContext::CORE),
        ) extends AbstractKernel {
        };
        $container = $kernel->createContainer();
        $loaded = $kernel->configureContainer($container->builder(), $container, $registry);
        $kernel->createRuntimeContainer($container, $registry, $loaded);

        return $container;
    }
}
