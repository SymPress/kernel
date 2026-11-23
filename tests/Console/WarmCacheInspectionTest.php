<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Console;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Console\ContainerDumpCommand;
use SymPress\Kernel\Console\DebugContainerCommand;
use SymPress\Kernel\Console\LintContainerCommand;
use SymPress\Kernel\Tests\Kernel\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServicesResetter;

final class WarmCacheInspectionTest extends KernelTestCase
{
    public function testInspectionCommandsSeeTheConfiguredContainerAfterAWarmHit(): void
    {
        $project = $this->tmpPath('warm-inspection');
        $cold = $this->kernel($project);
        $container = $cold->createContainer();
        $cold->createRuntimeContainer($container, new BundleRegistry(), $cold->configureContainer($container->builder(), $container, new BundleRegistry()));
        $kernel = $this->kernel($project);
        $warm = $kernel->createContainer();
        self::assertTrue($kernel->tryUseRuntimeContainer($warm, new BundleRegistry()));
        self::assertFalse($warm->builder()->hasParameter('kernel.project_dir'));

        $parameters = $this->runCommand(new DebugContainerCommand($warm), ['--parameters' => true]);
        self::assertStringContainsString('kernel.project_dir', $parameters);
        $types = $this->runCommand(new DebugContainerCommand($warm), ['--types' => true]);
        self::assertStringContainsString('services_resetter class=' . ServicesResetter::class, $types);
        self::assertStringContainsString(LintContainerCommand::class, $this->runCommand(new ContainerDumpCommand($warm), []));
        self::assertSame(0, (new CommandTester(new LintContainerCommand($warm)))->execute([]));
        self::assertFalse($warm->builder()->hasParameter('kernel.project_dir'));
    }

    public function testLintCompilesTheContainerEvenWithARuntimeContainer(): void
    {
        $project = $this->tmpPath('warm-lint');
        $kernel = $this->kernel($project);
        $container = $kernel->createContainer();
        $kernel->createRuntimeContainer($container, new BundleRegistry(), $kernel->configureContainer($container->builder(), $container, new BundleRegistry()));
        self::assertNotNull($container->runtimeContainer());
        $container->builder()->register('broken.consumer', \stdClass::class)
            ->addArgument(new Reference('missing.service'))
            ->setPublic(true);

        $tester = new CommandTester(new LintContainerCommand($container));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('missing.service', $tester->getDisplay());
    }

    /** @param array<string, mixed> $input */
    private function runCommand(DebugContainerCommand|ContainerDumpCommand $command, array $input): string
    {
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute($input));

        return $tester->getDisplay();
    }
}
