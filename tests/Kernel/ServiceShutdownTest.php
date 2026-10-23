<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use SymPress\Kernel\Bundle\AbstractBundle;
use SymPress\Kernel\Bundle\BundleRegistry;
use Symfony\Component\DependencyInjection\ServicesResetter;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Process\Process;
use Symfony\Contracts\Service\ShutdownInterface;

final class ServiceShutdownTest extends KernelTestCase
{
    public function testNativeResetAndFinalShutdownOfPrivateAndLazyServices(): void
    {
        if (!is_a(ServicesResetter::class, ShutdownInterface::class, true)) {
            self::assertSame(0, new \ReflectionMethod(ServicesResetter::class, 'reset')->getNumberOfParameters());
            return;
        }
        require_once __DIR__ . '/NativeShutdownService.php';

        $kernel = $this->kernel($this->tmpPath('shutdown'));
        $container = $kernel->createContainer();
        $registry = new BundleRegistry();
        $files = $kernel->configureContainer($container->builder(), $container, $registry);
        $container->builder()->register('shutdown.private', NativeShutdownService::class)->setAutoconfigured(true);
        $container->builder()->register('shutdown.entry', ShutdownHolder::class)
            ->setArguments([new Reference('shutdown.private')])->setPublic(true);
        $container->builder()->register('shutdown.lazy', NativeShutdownService::class)
            ->setAutoconfigured(true)->setLazy(true)->setPublic(true);
        $container->builder()->register('shutdown.initialized', NativeShutdownService::class)
            ->setAutoconfigured(true)->setLazy(true)->setPublic(true);
        $container->builder()->register('shutdown.nonshared', NativeShutdownService::class)
            ->setAutoconfigured(true)->setShared(false)->setPublic(true);
        $container->builder()->register('shutdown.flag', NativeShutdownService::class)
            ->setAutoconfigured(true)->setPublic(true)
            ->addTag('kernel.reset', ['method' => 'reset', 'on_shutdown' => 'flag']);
        $container->builder()->register('shutdown.explicit', NativeShutdownService::class)
            ->setAutoconfigured(true)->setPublic(true)
            ->addTag('kernel.reset', ['method' => 'reset', 'on_shutdown' => 'only']);
        $container->builder()->register('shutdown.only', ShutdownResource::class)
            ->setPublic(true)->addTag('kernel.reset', ['method' => 'close', 'on_shutdown' => 'only']);
        $kernel->createRuntimeContainer($container, $registry, $files);
        $service = $container->get('shutdown.entry')->service;
        $initialized = $container->get('shutdown.initialized');
        $initialized->calls = [];
        $nonshared = [$container->get('shutdown.nonshared'), $container->get('shutdown.nonshared')];
        $lazy = $container->get('shutdown.lazy');
        $resource = $container->get('shutdown.only');
        $explicit = $container->get('shutdown.explicit');
        $flag = $container->get('shutdown.flag');
        $resetter = $container->get('services_resetter');
        $kernel->boot($container, $registry);

        $resource->onClose = static fn () => $kernel->shutdown();
        $resetter->reset();
        self::assertSame([false], $service->calls);
        self::assertSame(0, $resource->closed);
        self::assertSame([], $explicit->calls);
        self::assertTrue(new \ReflectionClass($lazy)->isUninitializedLazyObject($lazy));
        $kernel->shutdown();
        $kernel->shutdown();
        $resetter->reset(true);

        self::assertSame([false, true], $service->calls);
        self::assertSame([false, true], $flag->calls);
        self::assertSame(1, $resource->closed);
        self::assertSame([false], $explicit->calls);
        self::assertSame([false, true], $initialized->calls);
        foreach ($nonshared as $instance) {
            self::assertSame([false, true], $instance->calls);
        }
        self::assertTrue(new \ReflectionClass($lazy)->isUninitializedLazyObject($lazy));
    }

    public function testShutdownContinuesAfterBundleAndServiceErrorsAndCannotRepeat(): void
    {
        $kernel = $this->kernel($this->tmpPath('shutdown-errors'));
        $bundleDir = $this->tmpPath('shutdown-bundle');
        mkdir($bundleDir, 0700, true);
        $bundle = new class ($bundleDir) extends AbstractBundle {
            public int $calls = 0;

            public function __construct(private readonly string $directory)
            {
            }

            public function path(): string
            {
                return $this->directory;
            }

            public function shutdown(): void
            {
                ++$this->calls;
                throw new RuntimeException('bundle failure');
            }
        };
        $registry = $this->registryWithBundle($bundleDir, $bundle);
        $container = $kernel->createContainer();
        $files = $kernel->configureContainer($container->builder(), $container, $registry);
        foreach (['first' => true, 'last' => false] as $id => $fails) {
            $container->builder()->register($id, ShutdownResource::class)
                ->setArguments([$fails])->setPublic(true)
                ->addTag('kernel.reset', ['method' => 'close', 'on_shutdown' => 'only']);
        }
        $kernel->createRuntimeContainer($container, $registry, $files);
        $first = $container->get('first');
        $last = $container->get('last');
        $kernel->boot($container, $registry);

        try {
            $kernel->shutdown();
            self::fail('The first shutdown error must be reported.');
        } catch (RuntimeException $error) {
            self::assertSame('bundle failure', $error->getMessage());
        }
        $kernel->shutdown();
        self::assertSame(1, $bundle->calls);
        self::assertSame([], $kernel->getBundles());
        $expected = is_a(ServicesResetter::class, ShutdownInterface::class, true) ? 1 : 0;
        self::assertSame($expected, $first->closed);
        self::assertSame($expected, $last->closed);
    }

    /** @return iterable<string, array{bool}> */
    public static function bootScenarios(): iterable
    {
        yield 'normal boot' => [false];
        yield 'booted hook failure followed by retry' => [true];
    }

    #[DataProvider('bootScenarios')]
    public function testAppInvokesNativeCleanupAtPhpShutdown(bool $retry): void
    {
        if (!is_a(ServicesResetter::class, ShutdownInterface::class, true)) {
            self::assertSame(0, new \ReflectionMethod(ServicesResetter::class, 'reset')->getNumberOfParameters());
            return;
        }
        $root = $this->tmpPath('app-shutdown');
        mkdir($root . '/config', 0700, true);
        file_put_contents($root . '/composer.json', '{}');
        file_put_contents($root . '/config/services.php', <<<'PHP'
<?php
use SymPress\Kernel\Tests\Kernel\ShutdownResource;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
return static function (ContainerConfigurator $config): void {
    $config->services()->set('resource', ShutdownResource::class)->public()
        ->tag('kernel.reset', ['method' => 'close', 'on_shutdown' => 'only']);
};
PHP);
        $script = <<<'PHP'
require $argv[1];
$GLOBALS['failBootedHook'] = $argv[3] === 'retry';
function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted = 1): bool { return true; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted = 1): bool { return true; }
function do_action(string $hook, mixed ...$arguments): void {
    if ($hook === SymPress\Kernel\App::ACTION_BOOTED && $GLOBALS['failBootedHook']) {
        $GLOBALS['failBootedHook'] = false;
        SymPress\Kernel\App::make('resource')->onClose = static function (): void { echo "rollback cleanup|"; };
        throw new RuntimeException('booted hook failure');
    }
}
register_shutdown_function(static function (): void { echo "wordpress shutdown|"; });
$kernel = new SymPress\Kernel\Kernel\SiteKernel($argv[2], 'test', false,
    new SymPress\Kernel\Tests\Support\TestSiteConfig('test'),
    SymPress\Kernel\WpContext::new()->force(SymPress\Kernel\WpContext::CORE));
$app = SymPress\Kernel\App::new($kernel);
try {
    $app->boot();
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'booted hook failure') { throw $error; }
    echo "caught|";
    $app->boot();
}
SymPress\Kernel\App::make('resource')->onClose = static function (): void { echo "native cleanup|"; };
echo "request|";
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $root, $retry ? 'retry' : 'normal']);
        $process->mustRun();
        $expected = ($retry ? 'rollback cleanup|caught|' : '') . 'request|wordpress shutdown|native cleanup|';
        self::assertSame($expected, $process->getOutput(), $process->getErrorOutput());
    }
}
