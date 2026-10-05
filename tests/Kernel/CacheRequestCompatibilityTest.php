<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\CachePath;
use SymPress\Kernel\Discovery\KernelPackageManifestCache;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ExecutableFinder;

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
        self::assertSame(0600, fileperms($cache . '/discovery-packages.json') & 0777);
        (new Filesystem())->remove($cache);
    }

    public function testSuccessiveRequestsUseMetadataWithoutHashesOrOpcacheInvalidation(): void
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
            self::assertSame(0, $request['invalidations']);
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
        $metaFile = $project . '/var/cache/test/kernel/meta.json';
        $metadata = json_decode(file_get_contents($metaFile), true, flags: JSON_THROW_ON_ERROR);
        unset($metadata['config_resources'][$file]);
        file_put_contents($metaFile, json_encode($metadata, JSON_THROW_ON_ERROR));
        self::assertFalse($kernel->tryUseRuntimeContainer($kernel->createContainer(), new BundleRegistry()));
    }

    public function testLegacyPhpMetadataIsNotExecutedDuringMigration(): void
    {
        $project = $this->tmpPath('legacy-php-metadata');
        $directory = $project . '/var/cache/test/kernel';
        mkdir($directory, 0700, true);
        foreach (['meta.php', 'discovery-packages.php'] as $filename) {
            file_put_contents($directory . '/' . $filename, '<?php throw new \\RuntimeException("Legacy metadata executed");');
        }
        $discovery = new KernelPackageManifestCache($project, 'test', ['sympress/']);
        self::assertNull($discovery->read());
        $discovery->write(['sympress/kernel']);
        self::assertSame(['sympress/kernel'], $discovery->read());
        $kernel = $this->kernel($project);
        $container = $kernel->createContainer();
        $bundles = new BundleRegistry();
        self::assertFalse($kernel->tryUseRuntimeContainer($container, $bundles));
        $files = $kernel->configureContainer($container->builder(), $container, $bundles);
        $kernel->createRuntimeContainer($container, $bundles, $files);
        self::assertTrue($kernel->tryUseRuntimeContainer($kernel->createContainer(), $bundles));
        self::assertFileExists($directory . '/meta.json');
    }

    public function testReadOnlyColdProjectBootsWithPrivateFallback(): void
    {
        $project = $this->tmpPath('readonly-cold-project');
        mkdir($project, 0500);
        $cache = CachePath::resolve($project, 'test');
        try {
            self::assertFalse(str_starts_with(CachePath::canonical($cache), CachePath::canonical($project) . '/'));
            $kernel = $this->kernel($project);
            $container = $kernel->createContainer();
            $bundles = new BundleRegistry();
            $files = $kernel->configureContainer($container->builder(), $container, $bundles);
            $kernel->createRuntimeContainer($container, $bundles, $files);
            self::assertTrue($kernel->tryUseRuntimeContainer($kernel->createContainer(), $bundles));
            $discovery = new KernelPackageManifestCache($project, 'test', ['sympress/']);
            $discovery->write(['sympress/kernel']);
            self::assertSame(['sympress/kernel'], $discovery->read());
            self::assertSame(0700, fileperms(dirname($cache, 2)) & 0777);
            self::assertSame(0600, fileperms($cache . '/meta.json') & 0777);
        } finally {
            chmod($project, 0700);
            (new Filesystem())->remove(dirname($cache, 2));
        }
    }

    public function testTemporaryFallbackRejectsPrecreatedSymlink(): void
    {
        $project = $this->tmpPath('public-symlink-cache');
        $outside = $this->tmpPath('untrusted-fallback');
        mkdir($outside, 0700);
        $_SERVER['DOCUMENT_ROOT'] = $project;
        $root = null;
        try {
            $root = dirname(CachePath::resolve($project, 'test'), 2);
            rmdir($root);
            symlink($outside, $root);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('symlinked');
            CachePath::resolve($project, 'test');
        } finally {
            unset($_SERVER['DOCUMENT_ROOT']);
            if ($root !== null && is_link($root)) {
                unlink($root);
            }
        }
    }

    public function testPrivateFallbackVerifiesProcessOwnerWithoutPosixExtension(): void
    {
        $project = $this->tmpPath('fallback-no-posix');
        $code = <<<'PHP'
require $argv[1];
$_SERVER['DOCUMENT_ROOT'] = $argv[2];
$path = SymPress\Kernel\Kernel\CachePath::resolve($argv[2], 'test');
$root = dirname($path, 2);
$probe = tmpfile();
$owner = fstat($probe)['uid'];
fclose($probe);
echo json_encode([$root, fileowner($root), $owner, fileperms($root) & 0777], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-d', 'disable_functions=posix_geteuid', '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $project]);
        $process->mustRun();
        [$root, $actual, $expected, $permissions] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        try {
            self::assertSame($expected, $actual);
            self::assertSame(0700, $permissions);
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    public function testCliWriterRefreshesPersistentFpmReadersWithoutMetadataInvalidation(): void
    {
        $finder = new ExecutableFinder();
        $fpm = $finder->find('php-fpm8.5') ?? $finder->find('php-fpm');
        $fcgi = $finder->find('cgi-fcgi');
        if ($fpm === null || $fcgi === null || !function_exists('posix_geteuid')) {
            self::markTestSkipped('Native CLI/FPM fixture requires PHP-FPM, cgi-fcgi and POSIX identity.');
        }
        $project = $this->tmpPath('cli-fpm-metadata');
        mkdir($project . '/config', 0700, true);
        $file = $project . '/config/services.php';
        $this->writeConfig($file, 'first');
        $write = static function (string $package) use ($project): void {
            (new Process([PHP_BINARY, dirname(__DIR__) . '/Fixtures/cache-request.php', $project]))->mustRun();
            $code = 'require $argv[1]; (new SymPress\\Kernel\\Discovery\\KernelPackageManifestCache($argv[2], "test", ["sympress/"]))->write([$argv[3]]);';
            (new Process([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $project, $package]))->mustRun();
        };
        $write('sympress/first');
        $socket = $project . '/fpm.sock';
        $config = $project . '/fpm.conf';
        $user = posix_getpwuid(posix_geteuid())['name'];
        $group = posix_getgrgid(posix_getegid())['name'];
        file_put_contents($config, "[global]\nerror_log={$project}/fpm.log\ndaemonize=no\n[fixture]\nuser={$user}\ngroup={$group}\nlisten={$socket}\nlisten.mode=0600\npm=static\npm.max_children=1\nclear_env=yes\nphp_admin_flag[opcache.enable]=on\nphp_admin_value[opcache.validate_timestamps]=0\nphp_admin_value[opcache.file_update_protection]=0\n");
        $pool = new Process([$fpm, '-F', '-y', $config]);
        $pool->start();
        try {
            $deadline = microtime(true) + 10;
            while (!is_file($socket) && !file_exists($socket)) {
                self::assertTrue($pool->isRunning(), $pool->getErrorOutput());
                self::assertLessThan($deadline, microtime(true), 'Fixture FPM socket was not created.');
                usleep(50000);
            }
            $request = static function () use ($fcgi, $socket, $project): array {
                $process = new Process([$fcgi, '-bind', '-connect', $socket], env: [
                    'SCRIPT_FILENAME' => dirname(__DIR__) . '/Fixtures/cache-fpm-request.php',
                    'SCRIPT_NAME' => '/cache-fpm-request.php',
                    'REQUEST_METHOD' => 'GET',
                    'SERVER_PROTOCOL' => 'HTTP/1.1',
                    'REDIRECT_STATUS' => '200',
                    'SYMPRESS_TEST_PROJECT' => $project,
                ]);
                $process->mustRun();
                $parts = preg_split('/\r?\n\r?\n/', $process->getOutput(), 2);
                return json_decode($parts[1] ?? '', true, flags: JSON_THROW_ON_ERROR);
            };
            $first = $request();
            self::assertTrue($first['hit']);
            self::assertSame('first', $first['value']);
            self::assertSame(['sympress/first'], $first['packages']);
            self::assertFalse($first['validate_timestamps']);
            self::assertSame(0, $first['invalidations']);
            self::assertSame($first, $request());
            $this->writeConfig($file, 'other');
            touch($file, time() + 2);
            $write('sympress/other');
            $next = $request();
            self::assertSame($first['worker'], $next['worker']);
            self::assertTrue($next['hit']);
            self::assertSame('other', $next['value']);
            self::assertSame(['sympress/other'], $next['packages']);
            self::assertSame(0, $next['invalidations']);
            self::assertSame($next, $request());
        } finally {
            $pool->stop();
        }
    }

    private function writeConfig(string $file, string $value): void
    {
        file_put_contents($file, '<?php return static function (\\Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\ContainerConfigurator $c): void { $c->parameters()->set("imported.value", ' . var_export($value, true) . '); };');
    }
}
