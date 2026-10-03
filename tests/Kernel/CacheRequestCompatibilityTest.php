<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\CachePath;
use SymPress\Kernel\Discovery\KernelPackageManifestCache;
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
        file_put_contents($directory . '/discovery-packages.php', '<?php throw new \\RuntimeException("Untrusted old discovery was executed");');
        file_put_contents($directory . '/container_0123456789abcdef.php', '<?php throw new \\RuntimeException("Untrusted old container was executed");');
        $old = hash_file('sha256', $directory . '/meta.php');
        $cache = CachePath::resolve($project, 'test');
        self::assertNotSame($directory, $cache);
        self::assertStringStartsWith($project . '/var/cache-private-', $cache);
        try {
            CachePath::resolve($project, 'test', $project . '/var/cache');
            self::fail('Explicit unsafe cache directory was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('APP_CACHE_DIR', $exception->getMessage());
            self::assertStringContainsString('PHP-FPM', $exception->getMessage());
        }
        $discovery = new KernelPackageManifestCache($project, 'test', ['sympress/']);
        self::assertNull($discovery->read());
        $discovery->write(['sympress/kernel']);
        self::assertSame(['sympress/kernel'], $discovery->read());
        $kernel = $this->kernel($project);
        $container = $kernel->createContainer();
        $files = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());
        $kernel->createRuntimeContainer($container, new BundleRegistry(), $files);
        self::assertTrue($kernel->tryUseRuntimeContainer($kernel->createContainer(), new BundleRegistry()));
        self::assertSame(0700, fileperms($cache) & 0777);
        self::assertSame(0775, fileperms($directory) & 0777);
        self::assertSame($old, hash_file('sha256', $directory . '/meta.php'));
        self::assertSame(0600, fileperms($cache . '/discovery-packages.php') & 0777);
        (new Filesystem())->remove($cache);
    }

    public function testSuccessiveRequestsUseMetadataWithoutHashesAndRefreshMutableOpcacheMetadata(): void
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
            self::assertSame([], $request['hashes']);
            self::assertSame(0, $request['bytes']);
            self::assertSame(1, $request['invalidations']);
        }
        $stamp = filemtime($file);
        $this->writeConfig($file . '.new', 'other');
        touch($file . '.new', $stamp);
        rename($file . '.new', $file);
        $request = $run();
        self::assertTrue($request['hit']);
        self::assertSame('first', $request['value']);
        touch($file, $stamp + 1);
        $request = $run();
        self::assertFalse($request['hit']);
        self::assertSame('other', $request['value']);
    }

    public function testExplicitContentModeDetectsSameSizeSameTimestampReplacement(): void
    {
        $project = $this->tmpPath('request-content-hashes');
        mkdir($project . '/config', 0700, true);
        $file = $project . '/config/services.php';
        $this->writeConfig($file, 'first');
        $run = static function () use ($project): array {
            $process = new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/cache-request.php', $project], env: ['SYMPRESS_KERNEL_CONTENT_HASHES' => '1']);
            $process->mustRun();

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        };
        self::assertFalse($run()['hit']);
        self::assertTrue($run()['hit']);
        $stamp = filemtime($file);
        $this->writeConfig($file, 'other');
        touch($file, $stamp);
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

    public function testMutableMetadataReplacementIsVisibleWithOpcacheTimestampChecksDisabled(): void
    {
        $project = $this->tmpPath('mutable-opcache');
        mkdir($project, 0700, true);
        $file = $project . '/meta.php';
        file_put_contents($file, '<?php return ["generation" => "old"];');
        $code = <<<'PHP'
require $argv[1];
$file = $argv[2] . '/meta.php';
$primed = opcache_compile_file($file);
$old = require $file;
file_put_contents($file . '.new', '<?php return ["generation" => "new"];');
rename($file . '.new', $file);
$stale = require $file;
$manager = new SymPress\Kernel\Kernel\ContainerCacheManager($argv[2], false, new SymPress\Kernel\Kernel\ContainerResourceFingerprinter($argv[2], 'test', false));
$fresh = (new ReflectionMethod($manager, 'readMetadata'))->invoke($manager, $file);
echo json_encode([$primed, $old, $stale, $fresh], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'opcache.validate_timestamps=0', '-d', 'opcache.file_update_protection=0', '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $project]);
        $process->mustRun();
        self::assertSame([true, ['generation' => 'old'], ['generation' => 'old'], ['generation' => 'new']], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    private function writeConfig(string $file, string $value): void
    {
        file_put_contents($file, '<?php return static function (\\Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\ContainerConfigurator $c): void { $c->parameters()->set("imported.value", ' . var_export($value, true) . '); };');
    }
}
