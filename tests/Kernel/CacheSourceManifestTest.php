<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

final class CacheSourceManifestTest extends KernelTestCase
{
    public function testRuntimeCacheStillReadsMetadataWithEmbeddedSourceResources(): void
    {
        $_SERVER['SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES'] = '1';
        $projectDir = $this->tmpPath('runtime-embedded-sources-project');
        $bundleDir = $this->tmpPath('runtime-embedded-sources-bundle');
        mkdir("{$bundleDir}/src", 0777, true);
        file_put_contents("{$bundleDir}/composer.json", '{}');
        file_put_contents("{$bundleDir}/src/DemoService.php", '<?php final class DemoService {}');
        $registry = $this->registry($bundleDir);
        $kernel = $this->kernel($projectDir);
        $container = $kernel->createContainer();
        $kernel->createRuntimeContainer($container, $registry, $kernel->configureContainer($container->builder(), $container, $registry));
        $cacheDir = "{$projectDir}/var/cache/test/kernel";
        $metadata = json_decode((string) file_get_contents("{$cacheDir}/meta.json"), true, flags: JSON_THROW_ON_ERROR);
        $sources = json_decode((string) file_get_contents("{$cacheDir}/sources.json"), true, flags: JSON_THROW_ON_ERROR);
        unset($metadata['sources']);
        $metadata['source_resources'] = $sources['source_resources'];
        file_put_contents("{$cacheDir}/meta.json", json_encode($metadata, JSON_THROW_ON_ERROR));
        unlink("{$cacheDir}/sources.json");

        self::assertTrue($this->kernel($projectDir)->tryUseRuntimeContainer($this->kernel($projectDir)->createContainer(), $registry));

        unset($metadata['source_resources']);
        file_put_contents("{$cacheDir}/meta.json", json_encode($metadata, JSON_THROW_ON_ERROR));

        self::assertFalse($this->kernel($projectDir)->tryUseRuntimeContainer($this->kernel($projectDir)->createContainer(), $registry));
    }
}
