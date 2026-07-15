<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;

final class RuntimeCacheCompatibilityTest extends KernelTestCase
{
    public function testProductionBundleFingerprintChangesWhenSourceFileChanges(): void
    {
        $bundleDir = $this->tmpPath('fingerprint-bundle');
        $sourceDir = "{$bundleDir}/src";
        $sourceFile = "{$sourceDir}/DemoService.php";
        mkdir($sourceDir, 0777, true);
        file_put_contents("{$bundleDir}/composer.json", '{}');
        file_put_contents($sourceFile, '<?php final class DemoService {}');

        $metadata = $this->registry($bundleDir)->all()[0];
        $first = $metadata->fingerprintParts(false);

        file_put_contents($sourceFile, '<?php final class DemoService { public function touch(): void {} }');
        touch($sourceFile, time() + 5);
        clearstatcache(true, $sourceFile);

        self::assertNotSame($first, $metadata->fingerprintParts(false));
    }

    public function testProductionRuntimeCacheDoesNotStatBundleSourceFilesByDefault(): void
    {
        $projectDir = $this->tmpPath('runtime-cache-project');
        $bundleDir = $this->tmpPath('runtime-cache-bundle');
        $sourceDir = "{$bundleDir}/src";
        $sourceFile = "{$sourceDir}/DemoService.php";
        mkdir($sourceDir, 0777, true);
        file_put_contents("{$bundleDir}/composer.json", '{}');
        file_put_contents($sourceFile, '<?php final class DemoService {}');

        $registry = $this->registry($bundleDir);
        $kernel = $this->kernel($projectDir);
        $container = $kernel->createContainer();
        $loaded = $kernel->configureContainer($container->builder(), $container, $registry);
        $kernel->createRuntimeContainer($container, $registry, $loaded);

        $cachedKernel = $this->kernel($projectDir);
        $cachedContainer = $cachedKernel->createContainer();
        self::assertTrue($cachedKernel->tryUseRuntimeContainer($cachedContainer, $registry));

        file_put_contents($sourceFile, '<?php final class DemoService { public function touch(): void {} }');
        touch($sourceFile, time() + 5);
        clearstatcache(true, $sourceFile);

        $staleKernel = $this->kernel($projectDir);
        $staleContainer = $staleKernel->createContainer();
        self::assertTrue($staleKernel->tryUseRuntimeContainer($staleContainer, $registry));
    }

    public function testRuntimeCacheCanValidateBundleSourceFilesWhenEnabled(): void
    {
        $_SERVER['SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES'] = '1';
        $projectDir = $this->tmpPath('runtime-source-validation-project');
        $bundleDir = $this->tmpPath('runtime-source-validation-bundle');
        $sourceDir = "{$bundleDir}/src";
        $sourceFile = "{$sourceDir}/DemoService.php";
        mkdir($sourceDir, 0777, true);
        file_put_contents("{$bundleDir}/composer.json", '{}');
        file_put_contents($sourceFile, '<?php final class DemoService {}');

        $registry = $this->registry($bundleDir);
        $kernel = $this->kernel($projectDir);
        $container = $kernel->createContainer();
        $loaded = $kernel->configureContainer($container->builder(), $container, $registry);
        $kernel->createRuntimeContainer($container, $registry, $loaded);

        file_put_contents($sourceFile, '<?php final class DemoService { public function touch(): void {} }');
        touch($sourceFile, time() + 5);
        clearstatcache(true, $sourceFile);

        $staleKernel = $this->kernel($projectDir);
        $staleContainer = $staleKernel->createContainer();
        self::assertFalse($staleKernel->tryUseRuntimeContainer($staleContainer, $registry));
    }

    public function testRuntimeCacheInvalidatesWhenImportedConfigFileChanges(): void
    {
        $projectDir = $this->tmpPath('runtime-import-project');
        $configDir = "{$projectDir}/config";
        $importedFile = "{$configDir}/imported.php";
        mkdir($configDir, 0777, true);
        $this->writeImportingConfig("{$configDir}/services.php", 'first');

        $kernel = $this->kernel($projectDir);
        $container = $kernel->createContainer();
        $loaded = $kernel->configureContainer($container->builder(), $container, new BundleRegistry());
        $kernel->createRuntimeContainer($container, new BundleRegistry(), $loaded);

        $cachedKernel = $this->kernel($projectDir);
        $cachedContainer = $cachedKernel->createContainer();
        self::assertTrue($cachedKernel->tryUseRuntimeContainer($cachedContainer, new BundleRegistry()));

        $this->writeImportedConfig($importedFile, 'second');
        touch($importedFile, time() + 5);
        clearstatcache(true, $importedFile);

        $staleKernel = $this->kernel($projectDir);
        $staleContainer = $staleKernel->createContainer();
        self::assertFalse($staleKernel->tryUseRuntimeContainer($staleContainer, new BundleRegistry()));
    }

    private function writeImportingConfig(string $file, string $value): void
    {
        $this->writeImportedConfig(sprintf('%s/imported.php', dirname($file)), $value);
        file_put_contents(
            $file,
            <<<'PHP'
<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->import('imported.php');
};
PHP
            ,
        );
    }

    private function writeImportedConfig(string $file, string $value): void
    {
        file_put_contents(
            $file,
            sprintf(
                <<<'PHP'
<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('imported.value', '%s');
};
PHP
                ,
                $value,
            ),
        );
    }
}
