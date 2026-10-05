<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\Discovery\KernelPackageManifestCache;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

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

    public function testUnchangedManifestReadersDoNotInvalidateOpcache(): void
    {
        $project = $this->tmpPath('discovery-reader-cost');
        $code = <<<'PHP'
namespace SymPress\Kernel\Discovery {
    function opcache_invalidate(string $filename, bool $force = false): bool {
        $GLOBALS['reader_invalidations']++;
        return \opcache_invalidate($filename, $force);
    }
}
namespace {
    require $argv[1];
    $cache = new SymPress\Kernel\Discovery\KernelPackageManifestCache($argv[2], 'production', ['sympress/']);
    $GLOBALS['reader_invalidations'] = 0;
    $cache->write(['sympress/kernel']);
    $GLOBALS['reader_invalidations'] = 0;
    $first = $cache->read();
    $second = $cache->read();
    echo json_encode([$first, $second, $GLOBALS['reader_invalidations']], JSON_THROW_ON_ERROR);
}
PHP;
        $process = new Process([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $project]);
        $process->mustRun();
        self::assertSame([['sympress/kernel'], ['sympress/kernel'], 0], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }

    private function tmpPath(string $prefix): string
    {
        $path = sprintf('%s/%s-%s', sys_get_temp_dir(), $prefix, uniqid('', true));
        mkdir($path, 0777, true);
        $this->paths[] = $path;

        return $path;
    }
}
