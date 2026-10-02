<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\CachePath;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class CacheRequestCompatibilityTest extends KernelTestCase
{
    public function testExistingGroupWritableCacheUsesFreshPrivateStorage(): void
    {
        $project = $this->tmpPath('legacy-group-cache');
        $directory = $project . '/var/cache/test/kernel';
        mkdir($directory, 0775, true);
        chmod($directory, 0775);
        file_put_contents($directory . '/meta.php', '<?php throw new \\RuntimeException("Untrusted old cache was executed");');
        $cache = CachePath::resolve($project, 'test');
        self::assertNotSame($directory, $cache);
        self::assertSame($cache, CachePath::resolve($project, 'test', $project . '/var/cache'));
        $kernel = $this->kernel($project);
        $container = $kernel->createContainer();
        $files = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());
        $kernel->createRuntimeContainer($container, new BundleRegistry(), $files);
        self::assertTrue($kernel->tryUseRuntimeContainer($kernel->createContainer(), new BundleRegistry()));
        self::assertSame(0700, fileperms($cache) & 0777);
        self::assertSame(0775, fileperms($directory) & 0777);
        (new Filesystem())->remove($cache);
    }

    public function testSuccessiveRequestsHashConfigOnceAndDetectSameTimestampReplacement(): void
    {
        $project = $this->tmpPath('request-hashes');
        mkdir($project . '/config', 0700, true);
        $file = $project . '/config/services.php';
        $this->writeConfig($file, 'first');
        $run = static function () use ($project): array {
            $process = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/cache-request.php', $project]);
            $process->mustRun();
            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        };
        self::assertFalse($run()['hit']);
        foreach (range(1, 2) as $unused) {
            $request = $run();
            self::assertTrue($request['hit']);
            self::assertSame(1, $request['hashes'][$file]);
        }
        $stamp = filemtime($file);
        $this->writeConfig($file . '.new', 'other');
        touch($file . '.new', $stamp);
        rename($file . '.new', $file);
        $request = $run();
        self::assertFalse($request['hit']);
        self::assertSame('other', $request['value']);
    }

    public function testMissingRecordedConfigResourceForcesRebuild(): void
    {
        $project = $this->tmpPath('missing-config-digest');
        mkdir($project . '/config', 0700, true);
        $file = $project . '/config/services.php';
        $this->writeConfig($file, 'first');
        $kernel = $this->kernel($project);
        $container = $kernel->createContainer();
        $files = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());
        $kernel->createRuntimeContainer($container, new BundleRegistry(), $files);
        $metaFile = $project . '/var/cache/test/kernel/meta.php';
        $metadata = require $metaFile;
        unset($metadata['config_resources'][$file]);
        file_put_contents($metaFile, '<?php return ' . var_export($metadata, true) . ';');
        self::assertFalse($kernel->tryUseRuntimeContainer($kernel->createContainer(), new BundleRegistry()));
    }

    private function writeConfig(string $file, string $value): void
    {
        file_put_contents($file, '<?php return static function (\\Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\ContainerConfigurator $c): void { $c->parameters()->set("imported.value", ' . var_export($value, true) . '); };');
    }
}
