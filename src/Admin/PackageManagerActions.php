<?php

declare(strict_types=1);

namespace SymPress\Kernel\Admin;

use SymPress\Kernel\Package\PackageMetadata;

final class PackageManagerActions
{
    public function run(string $action, PackageMetadata $package): ?\WP_Error
    {
        return match ($action) {
            'activate' => $this->activate($package),
            'deactivate' => $this->deactivate($package),
            'delete' => $this->delete($package),
            default => new \WP_Error(
                'kernel_unknown_package_action',
                __('Unknown package action.', 'sympress-kernel'),
            ),
        };
    }

    public function canRun(string $action, PackageMetadata $package): bool
    {
        if (!$this->fileModificationsAllowed($package)) {
            return false;
        }

        if ($action === 'delete') {
            if ($package->isTheme()) {
                return current_user_can('delete_themes');
            }

            return $package->isPlugin() && current_user_can('delete_plugins');
        }

        if ($action === 'deactivate') {
            if ($package->isTheme()) {
                return current_user_can('switch_themes');
            }

            return $package->isPlugin() && current_user_can('activate_plugins');
        }

        if ($action === 'activate' && $package->isTheme()) {
            return current_user_can('switch_themes');
        }

        return $action === 'activate'
            && $package->isPlugin()
            && current_user_can('activate_plugins');
    }

    public function isKnownAction(string $action): bool
    {
        return in_array($action, ['activate', 'deactivate', 'delete'], true);
    }

    public function isActionAvailable(string $action, PackageMetadata $package): bool
    {
        if ($package->isMustUsePlugin()) {
            return false;
        }

        return match ($action) {
            'activate' => !$package->active() && ($package->isPlugin() || $package->isTheme()),
            'deactivate' => $package->active() && ($package->isPlugin() || $package->isTheme()),
            'delete' => !$package->active() && ($package->isPlugin() || $package->isTheme()),
            default => false,
        };
    }

    public function actionUnavailableMessage(string $action, PackageMetadata $package): string
    {
        if ($package->isMustUsePlugin()) {
            return __('Must-use packages cannot be changed here.', 'sympress-kernel');
        }

        if ($action === 'delete' && $package->active()) {
            return __('Deactivate the package before deleting it.', 'sympress-kernel');
        }

        return __('This package action is not available.', 'sympress-kernel');
    }

    public function deleteSymlinkPackage(PackageMetadata $package): ?\WP_Error
    {
        if (!$this->isManagedSymlinkPackage($package)) {
            return new \WP_Error(
                'kernel_package_unmanaged_symlink',
                __('Only managed WordPress package symlinks can be deleted here.', 'sympress-kernel'),
            );
        }

        $this->beforeSymlinkDelete($package);
        $deleted = @unlink($package->path());
        $this->afterSymlinkDelete($package, $deleted);

        if (!$deleted) {
            return new \WP_Error(
                'kernel_package_delete_failed',
                __('The package symlink could not be deleted.', 'sympress-kernel'),
            );
        }

        if ($package->isPlugin() && function_exists('wp_clean_plugins_cache')) {
            wp_clean_plugins_cache(true);
        }

        if ($package->isTheme() && function_exists('wp_clean_themes_cache')) {
            wp_clean_themes_cache(true);
        }

        return null;
    }

    private function activate(PackageMetadata $package): ?\WP_Error
    {
        if ($package->isPlugin()) {
            $this->loadPluginAdminFunctions();
            $result = activate_plugin($package->entry(), '', is_network_admin(), false);

            return $result instanceof \WP_Error ? $result : null;
        }

        if ($package->isTheme()) {
            switch_theme($package->entry());

            return null;
        }

        return new \WP_Error(
            'kernel_package_not_activatable',
            __('This package type cannot be activated here.', 'sympress-kernel'),
        );
    }

    private function deactivate(PackageMetadata $package): ?\WP_Error
    {
        if ($package->isPlugin()) {
            $this->loadPluginAdminFunctions();
            deactivate_plugins($package->entry(), false, is_network_admin());

            return null;
        }

        if ($package->isTheme()) {
            return $this->deactivateTheme($package);
        }

        return new \WP_Error(
            'kernel_package_not_deactivatable',
            __('This package type cannot be deactivated here.', 'sympress-kernel'),
        );
    }

    private function deactivateTheme(PackageMetadata $package): ?\WP_Error
    {
        $fallback = $this->fallbackTheme($package->entry());

        if ($fallback === '') {
            return new \WP_Error(
                'kernel_package_theme_without_fallback',
                __(
                    'Install another theme before deactivating this theme package.',
                    'sympress-kernel',
                ),
            );
        }

        switch_theme($fallback);

        return null;
    }

    private function fallbackTheme(string $currentTheme): string
    {
        $defaultTheme = defined('WP_DEFAULT_THEME') ? (string) WP_DEFAULT_THEME : '';

        if ($this->themeExists($defaultTheme) && $defaultTheme !== $currentTheme) {
            return $defaultTheme;
        }

        if (!function_exists('wp_get_themes')) {
            return '';
        }

        foreach (wp_get_themes() as $stylesheet => $theme) {
            if (!is_string($stylesheet) || $stylesheet === $currentTheme) {
                continue;
            }

            if (!$theme->exists()) {
                continue;
            }

            return $stylesheet;
        }

        return '';
    }

    private function themeExists(string $stylesheet): bool
    {
        if ($stylesheet === '' || !function_exists('wp_get_theme')) {
            return false;
        }

        return wp_get_theme($stylesheet)->exists();
    }

    private function delete(PackageMetadata $package): ?\WP_Error
    {
        if ($package->active()) {
            return new \WP_Error(
                'kernel_package_active_delete',
                __('Deactivate the package before deleting it.', 'sympress-kernel'),
            );
        }

        if (is_link($package->path())) {
            return $this->deleteSymlinkPackage($package);
        }

        if ($package->isPlugin()) {
            $this->loadPluginAdminFunctions();
            $result = delete_plugins([$package->entry()]);

            return $this->deleteResultToError($result);
        }

        if ($package->isTheme()) {
            $this->loadThemeAdminFunctions();
            $result = delete_theme($package->entry(), admin_url('admin.php'));

            return $this->deleteResultToError($result);
        }

        return new \WP_Error(
            'kernel_package_not_deletable',
            __('This package type cannot be deleted here.', 'sympress-kernel'),
        );
    }

    private function beforeSymlinkDelete(PackageMetadata $package): void
    {
        if ($package->isPlugin()) {
            $this->loadPluginAdminFunctions();

            if (is_uninstallable_plugin($package->entry())) {
                uninstall_plugin($package->entry());
            }

            if (function_exists('do_action')) {
                do_action('delete_plugin', $package->entry());
            }
        }

        if (!$package->isTheme()) {
            return;
        }

        if (!function_exists('do_action')) {
            return;
        }

        do_action('delete_theme', $package->entry());
    }

    private function afterSymlinkDelete(PackageMetadata $package, bool $deleted): void
    {
        if ($package->isPlugin() && function_exists('do_action')) {
            do_action('deleted_plugin', $package->entry(), $deleted);
        }

        if (!$package->isTheme() || !function_exists('do_action')) {
            return;
        }

        do_action('deleted_theme', $package->entry(), $deleted);
    }

    private function deleteResultToError(mixed $result): ?\WP_Error
    {
        if ($result === true) {
            return null;
        }

        if ($result instanceof \WP_Error) {
            return $result;
        }

        return new \WP_Error(
            'kernel_package_delete_failed',
            __('The package could not be deleted.', 'sympress-kernel'),
        );
    }

    private function fileModificationsAllowed(PackageMetadata $package): bool
    {
        $context = $package->isTheme() ? 'themes' : 'plugins';

        if (function_exists('wp_is_file_mod_allowed')) {
            return wp_is_file_mod_allowed($context);
        }

        return !defined('DISALLOW_FILE_MODS') || !constant('DISALLOW_FILE_MODS');
    }

    private function isManagedSymlinkPackage(PackageMetadata $package): bool
    {
        $root = $package->isTheme() ? $this->themeRootDirectory() : $this->pluginRootDirectory();
        $entryRoot = $this->entryRoot($package->entry());

        if ($root === null || $entryRoot === '') {
            return false;
        }

        return $this->normalizePath($package->path()) === $this->normalizePath(sprintf('%s/%s', $root, $entryRoot));
    }

    private function entryRoot(string $entry): string
    {
        $parts = array_values(
            array_filter(
                explode('/', str_replace('\\', '/', $entry)),
                static fn (string $part): bool => $part !== '',
            ),
        );

        return $parts[0] ?? '';
    }

    private function pluginRootDirectory(): ?string
    {
        if (defined('WP_PLUGIN_DIR')) {
            return (string) WP_PLUGIN_DIR;
        }

        if (defined('WP_CONTENT_DIR')) {
            return sprintf('%s/plugins', rtrim((string) WP_CONTENT_DIR, '/'));
        }

        return null;
    }

    private function themeRootDirectory(): ?string
    {
        if (function_exists('get_theme_root')) {
            $themeRoot = get_theme_root();

            if ($themeRoot !== '') {
                return $themeRoot;
            }
        }

        if (defined('WP_CONTENT_DIR')) {
            return sprintf('%s/themes', rtrim((string) WP_CONTENT_DIR, '/'));
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function loadPluginAdminFunctions(): void
    {
        if (!function_exists('activate_plugin') || !function_exists('delete_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (function_exists('request_filesystem_credentials')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    private function loadThemeAdminFunctions(): void
    {
        if (!function_exists('delete_theme')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        if (function_exists('request_filesystem_credentials')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
}
