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

    private readonly PackageManagerActions $actions;

    public function __construct(
        private readonly PackageDiscovery $packages,
        private readonly bool $enabled = false,
        ?PackageManagerActions $actions = null,
    ) {
        $this->actions = $actions ?? new PackageManagerActions();
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
        return $this->actions->run($action, $package);
    }

    public function render(): void
    {
        if (!current_user_can('activate_plugins')) {
            $this->permissionDenied();
        }

        $packages = $this->packages->all();
        $currentView = $this->currentView();
        $notice = $this->requestString(self::NOTICE_QUERY_VAR);
        $message = $this->noticeMessage($notice, $this->requestString(self::MESSAGE_QUERY_VAR));

        $this->view($currentView, $notice, $message)->render(
            $packages,
            $this->filterPackages($packages, $currentView),
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

    private function isKnownAction(string $action): bool
    {
        return $this->actions->isKnownAction($action);
    }

    private function isActionAvailable(string $action, PackageMetadata $package): bool
    {
        return $this->actions->isActionAvailable($action, $package);
    }

    private function actionUnavailableMessage(string $action, PackageMetadata $package): string
    {
        return $this->actions->actionUnavailableMessage($action, $package);
    }

    private function canRun(string $action, PackageMetadata $package): bool
    {
        return $this->actions->canRun($action, $package);
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

    private function view(string $currentView, string $notice, string $message): PackageManagerPageView
    {
        return new PackageManagerPageView(
            self::SLUG,
            self::VIEW_QUERY_VAR,
            self::BULK_NONCE_ACTION,
            $currentView,
            $notice,
            $message,
            $this->pageUrl($this->currentViewArgs()),
            fn (string $view): string => $this->viewUrl($view),
            fn (string $action, PackageMetadata $package): string => $this->actionUrl($action, $package),
            fn (string $action, PackageMetadata $package): bool => $this->isActionAvailable($action, $package),
            fn (string $action, PackageMetadata $package): bool => $this->canRun($action, $package),
        );
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
