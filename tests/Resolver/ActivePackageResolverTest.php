<?php

declare(strict_types=1);

namespace {
    if (!defined('WP_PLUGIN_DIR')) {
        define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/kernel-active-package-resolver/plugins');
    }

    if (!function_exists('get_option')) {
        function get_option(string $option, mixed $default = false): mixed
        {
            $GLOBALS['kernel_test_option_reads'][$option] = ($GLOBALS['kernel_test_option_reads'][$option] ?? 0) + 1;

            return $GLOBALS['kernel_test_options'][$option] ?? $default;
        }
    }

    if (!function_exists('get_site_option')) {
        function get_site_option(string $option, mixed $default = false): mixed
        {
            return $GLOBALS['kernel_test_site_options'][$option] ?? $default;
        }
    }

    if (!function_exists('is_multisite')) {
        function is_multisite(): bool
        {
            return (bool) ($GLOBALS['kernel_test_is_multisite'] ?? false);
        }
    }
}

namespace SymPress\Kernel\Tests\Resolver {
    use SymPress\Kernel\Resolver\ActivePackageResolver;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Filesystem\Filesystem;

    final class ActivePackageResolverTest extends TestCase
    {
        /**
         * @var list<string>
         */
        private array $paths = [];

        protected function setUp(): void
        {
            $filesystem = new Filesystem();
            $filesystem->remove(WP_PLUGIN_DIR);
            $filesystem->mkdir(WP_PLUGIN_DIR);

            $GLOBALS['kernel_test_options'] = [];
            $GLOBALS['kernel_test_option_reads'] = [];
            $GLOBALS['kernel_test_site_options'] = [];
            $GLOBALS['kernel_test_is_multisite'] = false;
        }

        protected function tearDown(): void
        {
            (new Filesystem())->remove($this->paths);
            $this->paths = [];
        }

        public function testPluginIsActiveWhenComposerEntryDirectoryDiffersFromSymlinkedPluginDirectory(): void
        {
            $packageDir = $this->tmpPath('booking-package');
            $pluginDir = WP_PLUGIN_DIR . '/booking';

            (new Filesystem())->mkdir($packageDir);
            touch($packageDir . '/sympress-booking.php');
            symlink($packageDir, $pluginDir);
            $this->paths[] = $pluginDir;

            $GLOBALS['kernel_test_options']['active_plugins'] = [
                'booking/sympress-booking.php',
            ];

            self::assertTrue(
                (new ActivePackageResolver())->isActive(
                    'wordpress-plugin',
                    'sympress-booking/sympress-booking.php',
                    $packageDir,
                ),
            );
        }

        public function testNetworkActivePluginsAreDetected(): void
        {
            $packageDir = $this->tmpPath('category-image-package');
            $pluginDir = WP_PLUGIN_DIR . '/category-image';

            (new Filesystem())->mkdir($packageDir);
            touch($packageDir . '/category-image.php');
            symlink($packageDir, $pluginDir);
            $this->paths[] = $pluginDir;

            $GLOBALS['kernel_test_is_multisite'] = true;
            $GLOBALS['kernel_test_site_options']['active_sitewide_plugins'] = [
                'category-image/category-image.php' => time(),
            ];

            self::assertTrue(
                (new ActivePackageResolver())->isActive(
                    'wordpress-plugin',
                    'category-image/category-image.php',
                    $packageDir,
                ),
            );
        }

        public function testPluginActiveOptionsAreReadOncePerResolver(): void
        {
            $resolver = new ActivePackageResolver();
            $GLOBALS['kernel_test_options']['active_plugins'] = [
                'demo/demo.php',
            ];

            self::assertTrue(
                $resolver->isActive(
                    'wordpress-plugin',
                    'demo/demo.php',
                    $this->tmpPath('demo-package'),
                ),
            );
            self::assertFalse(
                $resolver->isActive(
                    'wordpress-plugin',
                    'missing/missing.php',
                    $this->tmpPath('missing-package'),
                ),
            );
            self::assertSame(1, $GLOBALS['kernel_test_option_reads']['active_plugins'] ?? 0);
        }

        private function tmpPath(string $prefix): string
        {
            $path = sprintf('%s/%s-%s', sys_get_temp_dir(), $prefix, uniqid('', true));
            $this->paths[] = $path;

            return $path;
        }
    }
}
