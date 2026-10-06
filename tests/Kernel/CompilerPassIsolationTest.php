<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\Resource\FileResource;

final class CompilerPassIsolationTest extends KernelTestCase
{
    public function testCompilerPassStateIsIsolatedAcrossResourceRecompilation(): void
    {
        $project = $this->tmpPath('compiler-state-project');
        $resource = $this->tmpPath('compiler-resource.php');
        file_put_contents($resource, '<?php // discovered while compiling');
        $kernel = $this->kernel($project);
        $container = $kernel->createContainer();
        $registry = new BundleRegistry();
        $files = $kernel->configureContainer($container->builder(), $container, $registry);
        $pass = new class ($resource) implements CompilerPassInterface {
            public int $runs = 0;

            public function __construct(private readonly string $resource)
            {
            }

            public function process(ContainerBuilder $container): void
            {
                ++$this->runs;
                $container->setParameter('fixture.compiler_runs', $this->runs);
                $container->addResource(new FileResource($this->resource));
            }
        };
        $container->builder()->addCompilerPass($pass);
        $kernel->createRuntimeContainer($container, $registry, $files);
        self::assertSame(1, $container->getParameter('fixture.compiler_runs'));
        self::assertSame(0, $pass->runs);
        $cached = $kernel->createContainer();
        self::assertTrue($kernel->tryUseRuntimeContainer($cached, $registry));
        self::assertSame(1, $cached->getParameter('fixture.compiler_runs'));
    }
}
