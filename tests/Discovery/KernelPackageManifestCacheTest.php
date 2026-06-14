<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\Discovery\KernelPackageManifestCache;
use Symfony\Component\Filesystem\Filesystem;

final class KernelPackageManifestCacheTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        if ($this->paths === []) {
            return;
        }

        (new Filesystem())->remove($this->paths);
        $this->paths = [];
    }

    public function testManifestRoundTripsUntilComposerMetadataChanges(): void
    {
        $projectDir = $this->tmpPath('discovery-cache-project');
        file_put_contents("{$projectDir}/composer.json", '{}');
        file_put_contents("{$projectDir}/composer.lock", '{}');

        $cache = new KernelPackageManifestCache($projectDir, 'production', ['sympress/']);
        $cache->write(['sympress/kernel', 'sympress/twig-bundle']);

        self::assertSame(['sympress/kernel', 'sympress/twig-bundle'], $cache->read());

        file_put_contents("{$projectDir}/composer.lock", '{"changed":true}');
        touch("{$projectDir}/composer.lock", time() + 5);
        clearstatcache(true, "{$projectDir}/composer.lock");

        self::assertNull($cache->read());
    }

    private function tmpPath(string $prefix): string
    {
        $path = sprintf('%s/%s-%s', sys_get_temp_dir(), $prefix, uniqid('', true));
        mkdir($path, 0777, true);
        $this->paths[] = $path;

        return $path;
    }
}
