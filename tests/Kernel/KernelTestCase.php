<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\App;
use SymPress\Kernel\Bundle\AbstractBundle;
use SymPress\Kernel\Bundle\BundleMetadata;
use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\AbstractKernel;
use SymPress\Kernel\Tests\Support\TestSiteConfig;
use SymPress\Kernel\WpContext;
use Symfony\Component\Filesystem\Filesystem;

abstract class KernelTestCase extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function setUp(): void
    {
        $GLOBALS['kernel_test_filter_values'] = [];
        $GLOBALS['kernel_test_do_actions'] = [];
        $this->resetApp();
    }

    protected function tearDown(): void
    {
        unset(
            $_ENV['APP_API_KEY'],
            $_ENV['APP_KERNEL_TEST_VALUE'],
            $_ENV['APP_RUNTIME_MODE'],
            $_ENV['APP_SECRET'],
            $_ENV['DB_PASSWORD'],
            $_ENV['WP_AUTH_KEY'],
            $_SERVER['SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES'],
            $GLOBALS['kernel_test_do_actions'],
            $GLOBALS['kernel_test_filter_values'],
        );
        $this->resetApp();

        if ($this->paths === []) {
            return;
        }

        (new Filesystem())->remove($this->paths);
        $this->paths = [];
    }

    protected function kernel(string $projectDir): AbstractKernel
    {
        return new class (
            $projectDir,
            'test',
            false,
            new TestSiteConfig('test'),
            WpContext::new()->force(WpContext::CORE),
        ) extends AbstractKernel {
        };
    }

    protected function registry(string $bundleDir): BundleRegistry
    {
        return $this->registryWithBundle(
            $bundleDir,
            new class ($bundleDir) extends AbstractBundle {
                public function __construct(
                    private readonly string $bundlePath,
                ) {
                }

                public function path(): string
                {
                    return $this->bundlePath;
                }
            },
        );
    }

    protected function registryWithBundle(string $bundleDir, AbstractBundle $bundle): BundleRegistry
    {
        return (new BundleRegistry())->add(
            new BundleMetadata(
                'sympress/test-bundle',
                'wordpress-plugin',
                'test-bundle/test-bundle.php',
                $bundleDir,
                "{$bundleDir}/composer.json",
                $bundle,
            ),
        );
    }

    protected function tmpPath(string $prefix): string
    {
        $path = sprintf('%s/%s-%s', sys_get_temp_dir(), $prefix, uniqid('', true));
        $this->paths[] = $path;

        return $path;
    }

    private function resetApp(): void
    {
        $property = new \ReflectionProperty(App::class, 'app');
        $property->setValue(null, null);
    }
}
