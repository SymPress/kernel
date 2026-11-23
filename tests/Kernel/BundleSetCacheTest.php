<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;

final class BundleSetCacheTest extends KernelTestCase
{
    public function testAlternatingBundleSetsKeepTheirOwnCachedContainers(): void
    {
        $projectDir = $this->tmpPath('multisite-cache-project');
        $sites = [new BundleRegistry()];
        foreach (['first', 'second'] as $name) {
            $bundleDir = $this->tmpPath("multisite-cache-{$name}-bundle");
            mkdir($bundleDir, 0700);
            file_put_contents("{$bundleDir}/composer.json", '{}');
            $sites[] = $this->registry($bundleDir);
        }
        $newSite = array_pop($sites);
        $kernel = $this->kernel($projectDir);
        foreach ($sites as $registry) {
            $container = $kernel->createContainer();
            self::assertFalse($kernel->tryUseRuntimeContainer($container, $registry));
            $kernel->createRuntimeContainer($container, $registry, $kernel->configureContainer($container->builder(), $container, $registry));
        }
        // Requests alternating between sites with different bundles must not recompile.
        foreach ([...$sites, ...$sites] as $registry) {
            self::assertTrue($this->kernel($projectDir)->tryUseRuntimeContainer($this->kernel($projectDir)->createContainer(), $registry));
        }
        $cacheDir = "{$projectDir}/var/cache/test/kernel";
        self::assertCount(2, glob("{$cacheDir}/container_*.php") ?: []);

        // Metadata of retired (long idle) bundle sets is bounded; generations it pins go with it.
        $idle = time() - 31 * 86400;
        for ($index = 0; $index < 40; ++$index) {
            $key = sprintf('%016x', $index);
            file_put_contents("{$cacheDir}/meta_{$key}.json", json_encode(['file' => "container_{$key}.php"], JSON_THROW_ON_ERROR));
            file_put_contents("{$cacheDir}/container_{$key}.php", '<?php');
            touch("{$cacheDir}/meta_{$key}.json", $idle - $index);
            touch("{$cacheDir}/container_{$key}.php", $idle - $index);
        }
        touch($cacheDir . '/meta.json');
        $container = $kernel->createContainer();
        $kernel->createRuntimeContainer($container, $newSite, $kernel->configureContainer($container->builder(), $container, $newSite));
        self::assertCount(32, glob("{$cacheDir}/meta_*.json") ?: []);
        self::assertFileExists("{$cacheDir}/container_" . sprintf('%016x', 28) . '.php');
        self::assertFileDoesNotExist("{$cacheDir}/container_" . sprintf('%016x', 39) . '.php');
        self::assertFileDoesNotExist($cacheDir . '/meta.json');
        foreach ($sites as $registry) {
            self::assertTrue($this->kernel($projectDir)->tryUseRuntimeContainer($this->kernel($projectDir)->createContainer(), $registry));
        }
    }

    public function testMoreThanThirtyTwoLiveBundleSetsDoNotEvictEachOther(): void
    {
        $projectDir = $this->tmpPath('multisite-many-sets-project');
        $kernel = $this->kernel($projectDir);
        $sites = [];
        for ($index = 0; $index < 34; ++$index) {
            $bundleDir = $this->tmpPath("multisite-many-sets-{$index}-bundle");
            mkdir($bundleDir, 0700);
            file_put_contents("{$bundleDir}/composer.json", '{}');
            $registry = $this->registry($bundleDir);
            $sites[] = $registry;
            $container = $kernel->createContainer();
            $kernel->createRuntimeContainer($container, $registry, $kernel->configureContainer($container->builder(), $container, $registry));
        }
        $cacheDir = "{$projectDir}/var/cache/test/kernel";
        self::assertCount(34, glob("{$cacheDir}/meta_*.json") ?: []);
        foreach ($sites as $registry) {
            self::assertTrue($this->kernel($projectDir)->tryUseRuntimeContainer($this->kernel($projectDir)->createContainer(), $registry));
        }

        // A warm hit records use (at most hourly), so a live set never looks idle.
        $meta = glob("{$cacheDir}/meta_*.json") ?: [];
        touch($meta[0], time() - 32 * 86400);
        clearstatcache();
        foreach ($sites as $registry) {
            self::assertTrue($this->kernel($projectDir)->tryUseRuntimeContainer($this->kernel($projectDir)->createContainer(), $registry));
        }
        clearstatcache();
        self::assertGreaterThan(time() - 60, filemtime($meta[0]));
    }
}
