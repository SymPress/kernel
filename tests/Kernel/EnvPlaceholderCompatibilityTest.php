<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class EnvPlaceholderCompatibilityTest extends KernelTestCase
{
    public function testEnvironmentDiscardedByOneExtensionResolvesInTheNextAndAfterCacheReload(): void
    {
        $projectDir = $this->tmpPath('reused-extension-env');
        $bundleDir = $this->tmpPath('reused-extension-bundle');
        mkdir($bundleDir, 0700, true);
        file_put_contents($bundleDir . '/composer.json', '{}');
        $registry = $this->registry($bundleDir);
        $kernel = $this->kernel($projectDir);
        $container = $kernel->createContainer();
        $container->builder()->registerExtension(new DiscardedEnvExtension());
        $container->builder()->registerExtension(new class extends Extension {
            public function getAlias(): string
            {
                return 'reused';
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
                $container->setParameter('reused.value', $configs[0]['value']);
            }
        });
        $container->builder()->prependExtensionConfig('discarded', ['value' => 'overridden']);
        $container->builder()->prependExtensionConfig('discarded', ['value' => '%env(APP_RUNTIME_MODE)%']);
        $container->builder()->prependExtensionConfig('reused', ['value' => '%env(APP_RUNTIME_MODE)%']);
        $_ENV['APP_RUNTIME_MODE'] = 'fixture-value';

        $kernel->createRuntimeContainer($container, $registry, []);
        self::assertSame('fixture-value', $container->getParameter('reused.value'));

        $cachedKernel = $this->kernel($projectDir);
        $cached = $cachedKernel->createContainer();
        self::assertTrue($cachedKernel->tryUseRuntimeContainer($cached, $registry));
        self::assertSame('fixture-value', $cached->getParameter('reused.value'));
    }
}
