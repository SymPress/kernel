<?php

declare(strict_types=1);

namespace SymPress\Kernel\Admin;

use SymPress\Kernel\Package\PackageMetadata;

final readonly class PackageManagerPageView
{
    /**
     * @param \Closure(string): string $viewUrl
     * @param \Closure(string, PackageMetadata): string $actionUrl
     * @param \Closure(string, PackageMetadata): bool $isActionAvailable
     * @param \Closure(string, PackageMetadata): bool $canRun
     */
    public function __construct(
        private string $slug,
        private string $viewQueryVar,
        private string $bulkNonceAction,
        private string $currentView,
        private string $notice,
        private string $noticeMessage,
        private string $formActionUrl,
        private \Closure $viewUrl,
        private \Closure $actionUrl,
        private \Closure $isActionAvailable,
        private \Closure $canRun,
    ) {
    }

    /**
     * @param list<PackageMetadata> $packages
     * @param list<PackageMetadata> $visiblePackages
     */
    public function render(array $packages, array $visiblePackages): void
    {
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
                action="<?php echo esc_url($this->formActionUrl); ?>"
                onsubmit="<?php echo esc_attr($this->bulkDeleteConfirmScript()); ?>"
            >
                <input type="hidden" name="page" value="<?php echo esc_attr($this->slug); ?>">
                <?php if ($this->currentView !== 'all') : ?>
                    <input
                        type="hidden"
                        name="<?php echo esc_attr($this->viewQueryVar); ?>"
                        value="<?php echo esc_attr($this->currentView); ?>"
                    >
                <?php endif; ?>
                <?php wp_nonce_field($this->bulkNonceAction); ?>
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
                <?php $this->renderTableHeader(); ?>
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
                <?php $this->renderTableHeader('cb-select-all-2'); ?>
            </tfoot>
        </table>
        <?php
    }

    private function renderTableHeader(string $checkboxId = 'cb-select-all-1'): void
    {
        ?>
        <tr>
            <td id="cb" class="manage-column column-cb check-column">
                <?php $this->renderSelectAllCheckbox($checkboxId); ?>
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
                $this->currentView === $view ? ' class="current" aria-current="page"' : '',
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
        if ($this->notice === '') {
            return;
        }

        $class = $this->notice === 'error' ? 'notice notice-error' : 'notice notice-success';

        printf(
            '<div class="%s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            esc_html($this->noticeMessage),
        );
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

    private function isActionAvailable(string $action, PackageMetadata $package): bool
    {
        return ($this->isActionAvailable)($action, $package);
    }

    private function canRun(string $action, PackageMetadata $package): bool
    {
        return ($this->canRun)($action, $package);
    }

    private function actionUrl(string $action, PackageMetadata $package): string
    {
        return ($this->actionUrl)($action, $package);
    }

    private function viewUrl(string $view): string
    {
        return ($this->viewUrl)($view);
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

    /** @param array<string, string> $attributes */
    private function htmlAttributes(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $name => $value) {
            $parts[] = sprintf('%s="%s"', esc_attr($name), esc_attr($value));
        }

        return implode(' ', $parts);
    }
}
