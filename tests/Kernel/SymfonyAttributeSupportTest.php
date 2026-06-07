<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\AbstractBundle;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Container;
use SymPress\Kernel\Kernel\AbstractKernel;
use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service\FeatureReport;
use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service\LazyProbe;
use SymPress\Kernel\Tests\Support\TestSiteConfig;
use SymPress\Kernel\WpContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class SymfonyAttributeSupportTest extends TestCase
{
    public function testSymfonyAttributesWorkForResourceLoadedBundleServices(): void
    {
        LazyProbe::reset();

        $container = $this->compileFixture('development');
        $snapshot = $container->get(FeatureReport::class)->snapshot();

        self::assertSame('attribute-driven', $snapshot['parameter']);
        self::assertSame('<strong>kernel</strong>', $snapshot['target']['admin']);
        self::assertSame('{"value":"kernel"}', $snapshot['target']['report']);
        self::assertSame('<strong>kernel</strong>', $snapshot['locator']['html']);
        self::assertSame('{"value":"kernel"}', $snapshot['locator']['json']);
        self::assertSame(
            [
                'primary' => 'Primary',
                'secondary' => 'Secondary',
            ],
            $snapshot['panels'],
        );
        self::assertTrue($snapshot['required']);
        self::assertSame(0, $snapshot['lazy_before']);
        self::assertSame('lazy', $snapshot['lazy_value']);
        self::assertSame(1, $snapshot['lazy_after']);
        self::assertSame(['autoconfigure'], $snapshot['notes']);
        self::assertSame(['development'], array_values($snapshot['statuses']));

        $application = $container->get(Application::class);

        self::assertInstanceOf(Application::class, $application);
        self::assertTrue($application->has('fixture:report'));
        self::assertTrue($application->has('fixture:status'));

        $tester = new CommandTester($application->find('fixture:report'));
        $tester->execute([]);

        self::assertStringContainsString('fixture console command', $tester->getDisplay());
    }

    public function testWhenAndWhenNotFollowKernelEnvironment(): void
    {
        LazyProbe::reset();

        $container = $this->compileFixture('production');
        $snapshot = $container->get(FeatureReport::class)->snapshot();

        self::assertSame(['not-development'], array_values($snapshot['statuses']));
    }

    private function compileFixture(string $environment): Container
    {
        $fixturePath = dirname(__DIR__) . '/Fixtures/AttributeBundle';
        $registry = (new BundleRegistry())->add(
            new BundleMetadata(
                'sympress/attribute-bundle',
                'wordpress-plugin',
                'attribute-bundle/attribute-bundle.php',
                $fixturePath,
                sprintf('%s/composer.json', $fixturePath),
                new class($fixturePath) extends AbstractBundle {
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
        $kernel = new class(
            dirname(__DIR__, 2),
            $environment,
            false,
            new TestSiteConfig($environment),
            WpContext::new()->force(WpContext::CORE),
        ) extends AbstractKernel {
        };
        $container = $kernel->createContainer();
        $loaded = $kernel->configureContainer($container->builder(), $container, $registry);
        $kernel->createRuntimeContainer($container, $registry, $loaded);

        return $container;
    }
}
