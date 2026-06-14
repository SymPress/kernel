<?php

declare(strict_types=1);

namespace {
    if (!defined('WP_CONTENT_DIR')) {
        define('WP_CONTENT_DIR', sys_get_temp_dir() . '/kernel-package-manager-wp-content');
    }

    if (!defined('WP_PLUGIN_DIR')) {
        define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
    }

    if (!class_exists('WP_Error')) {
        // phpcs:disable SymPress.Namespaces.Psr4.InvalidPSR4,Squiz.Classes.ValidClassName.NotCamelCaps,PSR1.Methods.CamelCapsMethodName.NotCamelCaps,Squiz.Classes.ClassDeclaration.SpaceBeforeKeyword
        class WP_Error
        {
            public function __construct(
                private readonly string $code,
                private readonly string $message,
            ) {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
        // phpcs:enable
    }

    if (!function_exists('__')) {
        // phpcs:ignore SymPress.NamingConventions.ElementNameMinimalLength.TooShort
        function __(string $text, string $domain = 'default'): string
        {
            return $text;
        }
    }

    if (!function_exists('add_menu_page')) {
        function add_menu_page(
            string $pageTitle,
            string $menuTitle,
            string $capability,
            string $menuSlug,
            ?callable $callback = null,
            string $iconUrl = '',
            int|float|string|null $position = null,
        ): string {
            $GLOBALS['kernel_test_admin_menu_pages'][] = [
                'page_title' => $pageTitle,
                'menu_title' => $menuTitle,
                'capability' => $capability,
                'menu_slug'  => $menuSlug,
                'callback'   => $callback,
                'icon_url'   => $iconUrl,
                'position'   => $position,
            ];

            return 'toplevel_page_kernel-packages';
        }
    }

    if (!function_exists('add_action')) {
        function add_action(
            string $hook,
            callable $callback,
            int $priority = 10,
            int $acceptedArgs = 1,
        ): void {
            $entry = [
                'hook'          => $hook,
                'callback'      => $callback,
                'priority'      => $priority,
                'acceptedArgs'  => $acceptedArgs,
            ];

            $GLOBALS['kernel_test_actions'][] = $entry;
            $GLOBALS['kernel_test_admin_actions'][] = $entry;
        }
    }

    if (!function_exists('current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return ($GLOBALS['kernel_test_capabilities'][$capability] ?? false) === true;
        }
    }

    if (!function_exists('wp_is_file_mod_allowed')) {
        function wp_is_file_mod_allowed(string $context): bool
        {
            return ($GLOBALS['kernel_test_file_mod_allowed'][$context] ?? true) === true;
        }
    }

    if (!function_exists('activate_plugin')) {
        function activate_plugin(string $plugin, string $redirect = '', bool $networkWide = false, bool $silent = false): null
        {
            return null;
        }
    }

    if (!function_exists('delete_plugins')) {
        function delete_plugins(array $plugins): bool
        {
            return true;
        }
    }

    if (!function_exists('request_filesystem_credentials')) {
        function request_filesystem_credentials(): bool
        {
            return true;
        }
    }

    if (!function_exists('is_uninstallable_plugin')) {
        function is_uninstallable_plugin(string $plugin): bool
        {
            return false;
        }
    }

    if (!function_exists('wp_clean_plugins_cache')) {
        function wp_clean_plugins_cache(bool $clearUpdateCache = true): void
        {
            $GLOBALS['kernel_test_plugins_cache_cleaned'] = $clearUpdateCache;
        }
    }
}

namespace SymPress\Kernel\Tests\Admin {
    use PHPUnit\Framework\TestCase;
    use SymPress\Kernel\Admin\PackageManagerPage;
    use SymPress\Kernel\Package\PackageDiscovery;
    use SymPress\Kernel\Package\PackageMetadata;
    use SymPress\Kernel\Resolver\ActivePackageResolver;
    use Symfony\Component\Filesystem\Filesystem;

    final class PackageManagerPageTest extends TestCase
    {
        /** @var list<string> */
        private array $paths = [];

        protected function setUp(): void
        {
            $GLOBALS['kernel_test_admin_menu_pages'] = [];
            $GLOBALS['kernel_test_admin_actions'] = [];
            $GLOBALS['kernel_test_capabilities'] = [
                'activate_plugins' => true,
                'delete_plugins'   => true,
            ];
            $GLOBALS['kernel_test_file_mod_allowed'] = [
                'plugins' => true,
                'themes'  => true,
            ];
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['kernel_test_admin_actions'],
                $GLOBALS['kernel_test_admin_menu_pages'],
                $GLOBALS['kernel_test_capabilities'],
                $GLOBALS['kernel_test_file_mod_allowed'],
                $GLOBALS['kernel_test_plugins_cache_cleaned'],
            );

            if ($this->paths === []) {
                return;
            }

            (new Filesystem())->remove($this->paths);
            $this->paths = [];
        }

        public function testPackageManagerDoesNotRegisterAdminPageWhenDisabled(): void
        {
            $this->page(false)->register();

            self::assertSame([], $GLOBALS['kernel_test_admin_menu_pages']);
        }

        public function testPackageManagerRegistersAdminPageWhenEnabled(): void
        {
            $this->page(true)->register();

            self::assertCount(1, $GLOBALS['kernel_test_admin_menu_pages']);
            self::assertSame(
                'kernel-packages',
                $GLOBALS['kernel_test_admin_menu_pages'][0]['menu_slug'],
            );
        }

        public function testActionsAreBlockedWhenWordPressDisallowsFileModifications(): void
        {
            $GLOBALS['kernel_test_file_mod_allowed']['plugins'] = false;

            self::assertFalse(
                $this->invokePageMethod('canRun', 'activate', $this->pluginPackage('/tmp/demo')),
            );
        }

        public function testUnmanagedSymlinkDeleteIsRejected(): void
        {
            $target = $this->tmpPath('unmanaged-target');
            $link = $this->tmpPath('unmanaged-link');
            rmdir($link);
            symlink($target, $link);

            $result = $this->invokePageMethod('deleteSymlinkPackage', $this->pluginPackage($link));

            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('kernel_package_unmanaged_symlink', $result->get_error_code());
            self::assertTrue(is_link($link));
        }

        public function testManagedSymlinkDeleteRemovesExpectedWordPressPackageLink(): void
        {
            $contentDir = (string) WP_CONTENT_DIR;
            $pluginRoot = "{$contentDir}/plugins";
            $target = $this->tmpPath('managed-target');
            $link = "{$pluginRoot}/demo";

            if (!is_dir($pluginRoot)) {
                mkdir($pluginRoot, 0777, true);
            }

            $this->paths[] = $contentDir;
            symlink($target, $link);

            $result = $this->invokePageMethod('deleteSymlinkPackage', $this->pluginPackage($link));

            self::assertNull($result);
            self::assertFalse(is_link($link));
            self::assertTrue($GLOBALS['kernel_test_plugins_cache_cleaned']);
        }

        private function page(bool $enabled): PackageManagerPage
        {
            return new PackageManagerPage(
                new PackageDiscovery(new ActivePackageResolver()),
                $enabled,
            );
        }

        private function pluginPackage(string $path): PackageMetadata
        {
            return new PackageMetadata(
                'sympress/demo',
                'wordpress-plugin',
                'demo/demo.php',
                $path,
                "{$path}/composer.json",
                self::class,
                'Demo',
                '',
                '',
                false,
            );
        }

        private function invokePageMethod(string $method, mixed ...$arguments): mixed
        {
            $reflection = new \ReflectionMethod(PackageManagerPage::class, $method);

            return $reflection->invoke($this->page(true), ...$arguments);
        }

        private function tmpPath(string $prefix): string
        {
            $path = sprintf('%s/%s-%s', sys_get_temp_dir(), $prefix, uniqid('', true));
            mkdir($path, 0777, true);
            $this->paths[] = $path;

            return $path;
        }
    }
}
