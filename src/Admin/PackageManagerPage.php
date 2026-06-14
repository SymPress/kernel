<?php

declare(strict_types=1);

namespace SymPress\Kernel\Admin;

use SymPress\Kernel\Package\PackageDiscovery;
use SymPress\Kernel\Package\PackageMetadata;

final class PackageManagerPage
{
    private const string SLUG = 'kernel-packages';
    private const int MENU_POSITION = 66;
    private const string ACTION_QUERY_VAR = 'kernel-package-action';
    private const string PACKAGE_QUERY_VAR = 'kernel-package';
    private const string NOTICE_QUERY_VAR = 'kernel-package-notice';
    private const string MESSAGE_QUERY_VAR = 'kernel-package-message';
    private const string VIEW_QUERY_VAR = 'package_status';
    private const string BULK_NONCE_ACTION = 'bulk-packages';

    public function __construct(
        private readonly PackageDiscovery $packages,
        private readonly bool $enabled = false,
    ) {
    }

    public function register(): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!function_exists('add_menu_page')) {
            return;
        }

        $hook = add_menu_page(
            __('Packages', 'sympress-kernel'),
            __('Packages', 'sympress-kernel'),
            'activate_plugins',
            self::SLUG,
            $this->render(...),
            $this->menuIcon(),
            self::MENU_POSITION,
        );

        if ($hook === '') {
            return;
        }

        add_action("load-{$hook}", $this->handleAction(...));
    }

    public function handleAction(): void
    {
        if ($this->isPostRequest()) {
            $this->handleBulkAction();

            return;
        }

        $action = $this->requestString(self::ACTION_QUERY_VAR);

        if ($action === '') {
            return;
        }

        if (!$this->isKnownAction($action)) {
            $this->redirect(
                'error',
                __('Unknown package action.', 'sympress-kernel'),
            );
        }

        $packageName = $this->requestString(self::PACKAGE_QUERY_VAR);
        $package = $this->packages->find($packageName);

        if (!$package instanceof PackageMetadata) {
            $this->redirect('error', __('Package not found.', 'sympress-kernel'));
        }

        check_admin_referer($this->nonceAction($action, $package));

        if (!$this->isActionAvailable($action, $package)) {
            $this->redirect('error', $this->actionUnavailableMessage($action, $package));
        }

        if (!$this->canRun($action, $package)) {
            $this->permissionDenied();
        }

        $error = $this->runPackageAction($action, $package);

        if ($error instanceof \WP_Error) {
            $this->redirect('error', $error->get_error_message());
        }

        $this->redirect($action);
    }

    private function handleBulkAction(): void
    {
        $action = $this->bulkAction();

        if ($action === '') {
            return;
        }

        check_admin_referer(self::BULK_NONCE_ACTION);

        $selected = $this->selectedPackageNames();

        if ($selected === []) {
            $this->redirect('error', __('No packages selected.', 'sympress-kernel'));
        }

        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $lastError = '';

        foreach ($selected as $packageName) {
            $package = $this->packages->find($packageName);

            if (!$package instanceof PackageMetadata) {
                ++$failed;
                $lastError = __('Package not found.', 'sympress-kernel');

                continue;
            }

            if (!$this->isActionAvailable($action, $package)) {
                ++$skipped;

                continue;
            }

            if (!$this->canRun($action, $package)) {
                $this->permissionDenied();
            }

            $error = $this->runPackageAction($action, $package);

            if ($error instanceof \WP_Error) {
                ++$failed;
                $lastError = $error->get_error_message();

                continue;
            }

            ++$processed;
        }

        if ($processed < 1 && $failed > 0) {
            $this->redirect('error', $lastError);
        }

        if ($processed < 1) {
            $this->redirect(
                'error',
                __('No selected packages can run this action.', 'sympress-kernel'),
            );
        }

        $this->redirect(
            $failed > 0 ? 'error' : $action,
            $this->bulkNoticeMessage($action, $processed, $skipped, $failed, $lastError),
        );
    }

    private function runPackageAction(string $action, PackageMetadata $package): ?\WP_Error
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

    public function render(): void
    {
        if (!current_user_can('activate_plugins')) {
            $this->permissionDenied();
        }

        $packages = $this->packages->all();
        $visiblePackages = $this->filterPackages($packages, $this->currentView());

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php echo esc_html__('Packages', 'sympress-kernel'); ?>
            </h1>
            <hr class="wp-header-end">

            <?php $this->renderNotice(); ?>
            <?php $this->renderViews($packages); ?>

            <form
                method="post"
                action="<?php echo esc_url($this->pageUrl($this->currentViewArgs())); ?>"
                onsubmit="<?php echo esc_attr($this->bulkDeleteConfirmScript()); ?>"
            >
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <?php if ($this->currentView() !== 'all') : ?>
                    <input
                        type="hidden"
                        name="<?php echo esc_attr(self::VIEW_QUERY_VAR); ?>"
                        value="<?php echo esc_attr($this->currentView()); ?>"
                    >
                <?php endif; ?>
                <?php wp_nonce_field(self::BULK_NONCE_ACTION); ?>
                <?php $this->renderTableNav($visiblePackages, 'top'); ?>
                <?php $this->renderTable($visiblePackages); ?>
                <?php $this->renderTableNav($visiblePackages, 'bottom'); ?>
            </form>
        </div>
        <?php
    }

    /** @param list<PackageMetadata> $packages */
    private function renderTableNav(array $packages, string $which): void
    {
        ?>
        <div class="tablenav <?php echo esc_attr($which); ?>">
            <?php $this->renderBulkActions($which); ?>
            <div class="tablenav-pages one-page">
                <span class="displaying-num">
                    <?php echo esc_html($this->itemCountLabel(count($packages))); ?>
                </span>
            </div>
            <br class="clear">
        </div>
        <?php
    }

    private function renderBulkActions(string $which): void
    {
        $name = $which === 'top' ? 'action' : 'action2';
        $buttonId = $which === 'top' ? 'doaction' : 'doaction2';
        $selectId = sprintf('bulk-action-selector-%s', $which);

        ?>
        <div class="alignleft actions bulkactions">
            <label for="<?php echo esc_attr($selectId); ?>" class="screen-reader-text">
                <?php echo esc_html__('Select bulk action'); ?>
            </label>
            <select name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($selectId); ?>">
                <option value="-1"><?php echo esc_html__('Bulk actions'); ?></option>
                <option value="activate-selected"><?php echo esc_html__('Activate'); ?></option>
                <option value="deactivate-selected"><?php echo esc_html__('Deactivate'); ?></option>
                <option value="delete-selected"><?php echo esc_html__('Delete'); ?></option>
            </select>
            <?php submit_button(__('Apply'), 'action', $buttonId, false); ?>
        </div>
        <?php
    }

    private function bulkDeleteConfirmScript(): string
    {
        $confirm = wp_json_encode(
            __('Are you sure you want to delete the selected packages?', 'sympress-kernel'),
        );

        return sprintf(
            'var a=this.elements["action"],b=this.elements["action2"];'
                . 'if((a&&a.value==="delete-selected")||(b&&b.value==="delete-selected")){'
                . 'return confirm(%s);'
                . '}return true;',
            is_string($confirm) ? $confirm : '""',
        );
    }

    private function itemCountLabel(int $count): string
    {
        return sprintf(
            /* translators: %s: Number of packages. */
            _n('%s item', '%s items', $count),
            number_format_i18n($count),
        );
    }

    /** @param list<PackageMetadata> $packages */
    private function renderTable(array $packages): void
    {
        ?>
        <table class="wp-list-table widefat plugins striped table-view-list">
            <thead>
                <tr>
                    <td id="cb" class="manage-column column-cb check-column">
                        <?php $this->renderSelectAllCheckbox('cb-select-all-1'); ?>
                    </td>
                    <th scope="col" class="manage-column column-primary">
                        <?php echo esc_html__('Package', 'sympress-kernel'); ?>
                    </th>
                    <th scope="col" class="manage-column column-description">
                        <?php echo esc_html__('Description'); ?>
                    </th>
                    <th scope="col" class="manage-column">
                        <?php echo esc_html__('Type', 'sympress-kernel'); ?>
                    </th>
                    <th scope="col" class="manage-column">
                        <?php echo esc_html__('Status'); ?>
                    </th>
                </tr>
            </thead>
            <tbody id="the-list">
                <?php if ($packages === []) : ?>
                    <tr class="no-items">
                        <td class="colspanchange" colspan="5">
                            <?php echo esc_html__('No packages found.', 'sympress-kernel'); ?>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($packages as $package) : ?>
                    <?php $this->renderRow($package); ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <?php $this->renderSelectAllCheckbox('cb-select-all-2'); ?>
                    </td>
                    <th scope="col" class="manage-column column-primary">
                        <?php echo esc_html__('Package', 'sympress-kernel'); ?>
                    </th>
                    <th scope="col" class="manage-column column-description">
                        <?php echo esc_html__('Description'); ?>
                    </th>
                    <th scope="col" class="manage-column">
                        <?php echo esc_html__('Type', 'sympress-kernel'); ?>
                    </th>
                    <th scope="col" class="manage-column">
                        <?php echo esc_html__('Status'); ?>
                    </th>
                </tr>
            </tfoot>
        </table>
        <?php
    }

    private function renderRow(PackageMetadata $package): void
    {
        $rowClass = $package->active() ? 'active' : 'inactive';

        ?>
        <tr
            class="<?php echo esc_attr($rowClass); ?>"
            data-slug="<?php echo esc_attr($package->package()); ?>"
        >
            <th scope="row" class="check-column">
                <?php $this->renderRowCheckbox($package); ?>
            </th>
            <td class="plugin-title column-primary">
                <strong><?php echo esc_html($package->name()); ?></strong>
                <?php $this->renderRowActions($package); ?>
                <button type="button" class="toggle-row">
                    <span class="screen-reader-text">
                        <?php echo esc_html__('Show more details'); ?>
                    </span>
                </button>
            </td>
            <td class="column-description desc">
                <div class="plugin-description">
                    <p><?php echo esc_html($this->description($package)); ?></p>
                </div>
                <div class="active second plugin-version-author-uri">
                    <?php echo esc_html($this->metaLine($package)); ?>
                </div>
            </td>
            <td data-colname="<?php echo esc_attr__('Type', 'sympress-kernel'); ?>">
                <?php echo esc_html($package->typeLabel()); ?>
            </td>
            <td data-colname="<?php echo esc_attr__('Status'); ?>">
                <?php echo esc_html($package->statusLabel()); ?>
            </td>
        </tr>
        <?php
    }

    private function renderSelectAllCheckbox(string $id): void
    {
        ?>
        <label class="screen-reader-text" for="<?php echo esc_attr($id); ?>">
            <?php echo esc_html__('Select All'); ?>
        </label>
        <input id="<?php echo esc_attr($id); ?>" type="checkbox">
        <?php
    }

    private function renderRowCheckbox(PackageMetadata $package): void
    {
        if (!$this->hasBulkAction($package)) {
            echo '&nbsp;';

            return;
        }

        $id = sprintf('package-%s', sanitize_html_class($package->package()));

        ?>
        <label class="screen-reader-text" for="<?php echo esc_attr($id); ?>">
            <?php
            printf(
                /* translators: %s: Package name. */
                esc_html__('Select %s'),
                esc_html($package->name()),
            );
            ?>
        </label>
        <input
            id="<?php echo esc_attr($id); ?>"
            type="checkbox"
            name="checked[]"
            value="<?php echo esc_attr($package->package()); ?>"
        >
        <?php
    }

    private function renderRowActions(PackageMetadata $package): void
    {
        $actions = $this->rowActions($package);

        if ($actions === []) {
            return;
        }

        echo '<div class="row-actions visible">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo implode(' | ', $actions);
        echo '</div>';
    }

    /** @return list<string> */
    private function rowActions(PackageMetadata $package): array
    {
        if ($package->isMustUsePlugin()) {
            return [
                sprintf(
                    '<span class="mu-plugin">%s</span>',
                    esc_html__('Must-use', 'sympress-kernel'),
                ),
            ];
        }

        $actions = [];

        if ($this->isActionAvailable('deactivate', $package)) {
            $actions[] = $this->actionLink('deactivate', $package, __('Deactivate'));
        }

        if ($this->isActionAvailable('activate', $package)) {
            $actions[] = $this->actionLink('activate', $package, __('Activate'));
        }

        if ($this->isActionAvailable('delete', $package)) {
            $actions[] = $this->actionLink('delete', $package, __('Delete'), 'delete');
        }

        return array_values(array_filter($actions));
    }

    private function actionLink(
        string $action,
        PackageMetadata $package,
        string $label,
        string $class = '',
    ): string {

        if (!$this->canRun($action, $package)) {
            return '';
        }

        $attributes = [
            'href' => esc_url($this->actionUrl($action, $package)),
        ];

        if ($class !== '') {
            $attributes['class'] = $class;
        }

        if ($action === 'delete') {
            $confirm = wp_json_encode(
                __('Are you sure you want to delete this package?', 'sympress-kernel'),
            );
            $attributes['onclick'] = sprintf(
                'return confirm(%s);',
                is_string($confirm) ? $confirm : '""',
            );
        }

        return sprintf(
            '<span class="%s"><a %s>%s</a></span>',
            esc_attr($action),
            $this->htmlAttributes($attributes),
            esc_html($label),
        );
    }

    /** @param list<PackageMetadata> $packages */
    private function renderViews(array $packages): void
    {
        $counts = $this->counts($packages);
        $views = [
            'all'      => __('All'),
            'active'   => __('Active'),
            'inactive' => __('Inactive'),
            'mustuse'  => __('Must-Use', 'sympress-kernel'),
            'plugins'  => __('Plugins'),
            'themes'   => __('Themes'),
        ];
        $currentView = $this->currentView();
        $items = [];

        foreach ($views as $view => $label) {
            if (($counts[$view] ?? 0) < 1 && $view !== 'all') {
                continue;
            }

            $items[] = sprintf(
                '<li class="%1$s"><a href="%2$s"%3$s>%4$s '
                    . '<span class="count">(%5$s)</span></a></li>',
                esc_attr($view),
                esc_url($this->viewUrl($view)),
                $currentView === $view ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                esc_html((string) ($counts[$view] ?? 0)),
            );
        }

        echo '<ul class="subsubsub">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo implode(' | ', $items);
        echo '</ul>';
    }

    private function renderNotice(): void
    {
        $notice = $this->requestString(self::NOTICE_QUERY_VAR);

        if ($notice === '') {
            return;
        }

        $message = $this->noticeMessage($notice, $this->requestString(self::MESSAGE_QUERY_VAR));
        $class = $notice === 'error' ? 'notice notice-error' : 'notice notice-success';

        printf(
            '<div class="%s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            esc_html($message),
        );
    }

    private function noticeMessage(string $notice, string $message): string
    {
        if ($message !== '') {
            return $message;
        }

        if ($notice === 'error') {
            return __('Package action failed.', 'sympress-kernel');
        }

        return match ($notice) {
            'activate' => __('Package activated.', 'sympress-kernel'),
            'deactivate' => __('Package deactivated.', 'sympress-kernel'),
            'delete' => __('Package deleted.', 'sympress-kernel'),
            default => __('Package updated.', 'sympress-kernel'),
        };
    }

    private function bulkNoticeMessage(
        string $action,
        int $processed,
        int $skipped,
        int $failed,
        string $lastError,
    ): string {

        $message = match ($action) {
            'activate' => sprintf(
                /* translators: %s: Number of packages. */
                _n(
                    '%s package activated.',
                    '%s packages activated.',
                    $processed,
                    'sympress-kernel',
                ),
                number_format_i18n($processed),
            ),
            'deactivate' => sprintf(
                /* translators: %s: Number of packages. */
                _n(
                    '%s package deactivated.',
                    '%s packages deactivated.',
                    $processed,
                    'sympress-kernel',
                ),
                number_format_i18n($processed),
            ),
            'delete' => sprintf(
                /* translators: %s: Number of packages. */
                _n(
                    '%s package deleted.',
                    '%s packages deleted.',
                    $processed,
                    'sympress-kernel',
                ),
                number_format_i18n($processed),
            ),
            default => __('Packages updated.', 'sympress-kernel'),
        };

        if ($skipped > 0) {
            $message .= ' ' . sprintf(
                /* translators: %s: Number of packages. */
                _n(
                    '%s package skipped.',
                    '%s packages skipped.',
                    $skipped,
                    'sympress-kernel',
                ),
                number_format_i18n($skipped),
            );
        }

        if ($failed > 0) {
            $message .= ' ' . sprintf(
                /* translators: 1: Number of packages. 2: Error message. */
                _n(
                    '%1$s package failed: %2$s',
                    '%1$s packages failed: %2$s',
                    $failed,
                    'sympress-kernel',
                ),
                number_format_i18n($failed),
                $lastError !== ''
                    ? $lastError
                    : __('Unknown error.', 'sympress-kernel'),
            );
        }

        return $message;
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
            $result = delete_theme($package->entry(), $this->pageUrl());

            return $this->deleteResultToError($result);
        }

        return new \WP_Error(
            'kernel_package_not_deletable',
            __('This package type cannot be deleted here.', 'sympress-kernel'),
        );
    }

    private function deleteSymlinkPackage(PackageMetadata $package): ?\WP_Error
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

    private function canRun(string $action, PackageMetadata $package): bool
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

    private function hasBulkAction(PackageMetadata $package): bool
    {
        foreach (['activate', 'deactivate', 'delete'] as $action) {
            if ($this->isActionAvailable($action, $package) && $this->canRun($action, $package)) {
                return true;
            }
        }

        return false;
    }

    private function isKnownAction(string $action): bool
    {
        return in_array($action, ['activate', 'deactivate', 'delete'], true);
    }

    private function isActionAvailable(string $action, PackageMetadata $package): bool
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

    private function actionUnavailableMessage(string $action, PackageMetadata $package): string
    {
        if ($package->isMustUsePlugin()) {
            return __('Must-use packages cannot be changed here.', 'sympress-kernel');
        }

        if ($action === 'delete' && $package->active()) {
            return __('Deactivate the package before deleting it.', 'sympress-kernel');
        }

        return __('This package action is not available.', 'sympress-kernel');
    }

    /**
     * @param list<PackageMetadata> $packages
     * @return list<PackageMetadata>
     */
    private function filterPackages(array $packages, string $view): array
    {
        return array_values(
            array_filter(
                $packages,
                static fn (PackageMetadata $package): bool => match ($view) {
                    'active' => $package->active(),
                    'inactive' => !$package->active(),
                    'mustuse' => $package->isMustUsePlugin(),
                    'plugins' => $package->isPlugin(),
                    'themes' => $package->isTheme(),
                    default => true,
                },
            ),
        );
    }

    /**
     * @param list<PackageMetadata> $packages
     * @return array<string, int>
     */
    private function counts(array $packages): array
    {
        $counts = [
            'all'      => count($packages),
            'active'   => 0,
            'inactive' => 0,
            'mustuse'  => 0,
            'plugins'  => 0,
            'themes'   => 0,
        ];

        foreach ($packages as $package) {
            ++$counts[$package->active() ? 'active' : 'inactive'];

            if ($package->isMustUsePlugin()) {
                ++$counts['mustuse'];
            }

            if ($package->isPlugin()) {
                ++$counts['plugins'];
            }

            if (!$package->isTheme()) {
                continue;
            }

            ++$counts['themes'];
        }

        return $counts;
    }

    private function currentView(): string
    {
        $view = $this->requestString(self::VIEW_QUERY_VAR);

        if ($view === '') {
            $view = $this->postString(self::VIEW_QUERY_VAR);
        }

        $allowed = ['all', 'active', 'inactive', 'mustuse', 'plugins', 'themes'];

        return in_array($view, $allowed, true) ? $view : 'all';
    }

    private function actionUrl(string $action, PackageMetadata $package): string
    {
        $args = array_merge(
            $this->currentViewArgs(),
            [
                self::ACTION_QUERY_VAR  => $action,
                self::PACKAGE_QUERY_VAR => $package->package(),
            ],
        );

        return wp_nonce_url(
            $this->pageUrl($args),
            $this->nonceAction($action, $package),
        );
    }

    private function viewUrl(string $view): string
    {
        $args = $view === 'all' ? [] : [self::VIEW_QUERY_VAR => $view];

        return $this->pageUrl($args);
    }

    /** @return array<string, string> */
    private function currentViewArgs(): array
    {
        $view = $this->currentView();

        return $view === 'all' ? [] : [self::VIEW_QUERY_VAR => $view];
    }

    /** @param array<string, mixed> $args */
    private function pageUrl(array $args = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::SLUG], $args),
            self_admin_url('admin.php'),
        );
    }

    private function menuIcon(): string
    {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
<path fill="black" d="M10 2 3 5.5v9L10 18l7-3.5v-9L10 2Z"/>
<path fill="white" d="M10 4.2 5.6 6.4 10 8.6l4.4-2.2L10 4.2Z"/>
<path fill="white" d="M5 8.2v5l4 2V10.3L5 8.2Zm10 0-4 2.1v4.9l4-2v-5Z"/>
</svg>
SVG;

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function nonceAction(string $action, PackageMetadata $package): string
    {
        return sprintf('kernel_package_%s_%s', $action, $package->package());
    }

    private function redirect(string $notice, string $message = ''): never
    {
        wp_safe_redirect(
            $this->pageUrl(
                array_filter(
                    array_merge(
                        $this->currentViewArgs(),
                        [
                            self::NOTICE_QUERY_VAR  => $notice,
                            self::MESSAGE_QUERY_VAR => $message,
                        ],
                    ),
                    static fn (string $value): bool => $value !== '',
                ),
            ),
        );
        exit;
    }

    private function isPostRequest(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    }

    private function bulkAction(): string
    {
        $action = $this->postString('action');

        if ($action === '' || $action === '-1') {
            $action = $this->postString('action2');
        }

        return match ($action) {
            'activate-selected' => 'activate',
            'deactivate-selected' => 'deactivate',
            'delete-selected' => 'delete',
            default => '',
        };
    }

    /** @return list<string> */
    private function selectedPackageNames(): array
    {
        $value = $_POST['checked'] ?? [];

        if (!is_array($value)) {
            return [];
        }

        $packages = [];

        foreach ($value as $package) {
            if (!is_string($package)) {
                continue;
            }

            $package = sanitize_text_field(wp_unslash($package));

            if ($package === '') {
                continue;
            }

            $packages[$package] = true;
        }

        return array_keys($packages);
    }

    private function requestString(string $key): string
    {
        $value = $_GET[$key] ?? '';
        $value = is_string($value) ? wp_unslash($value) : '';

        return sanitize_text_field($value);
    }

    private function postString(string $key): string
    {
        $value = $_POST[$key] ?? '';
        $value = is_string($value) ? wp_unslash($value) : '';

        return sanitize_text_field($value);
    }

    private function description(PackageMetadata $package): string
    {
        if ($package->description() !== '') {
            return $package->description();
        }

        return __('No description available.', 'sympress-kernel');
    }

    private function metaLine(PackageMetadata $package): string
    {
        $items = [
            sprintf(
                /* translators: %s: Package type. */
                __('Type: %s', 'sympress-kernel'),
                $package->typeLabel(),
            ),
            sprintf(
                /* translators: %s: Composer package name. */
                __('Package: %s', 'sympress-kernel'),
                $package->package(),
            ),
            sprintf(
                /* translators: %s: Package entry file or theme stylesheet. */
                __('Entry: %s', 'sympress-kernel'),
                $package->entry(),
            ),
        ];

        if ($package->version() !== '') {
            array_unshift(
                $items,
                sprintf(
                    /* translators: %s: Package version. */
                    __('Version %s'),
                    $package->version(),
                ),
            );
        }

        return implode(' | ', $items);
    }

    /** @param array<string, string> $attributes */
    private function htmlAttributes(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $name => $value) {
            $parts[] = sprintf('%s="%s"', esc_attr($name), esc_attr($value));
        }

        return implode(' ', $parts);
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

    private function permissionDenied(): never
    {
        wp_die(
            esc_html__(
                'Sorry, you are not allowed to manage packages.',
                'sympress-kernel',
            ),
        );
    }
}
