<?php

declare(strict_types=1);

namespace SymPress\Kernel;

final class WpContext implements \JsonSerializable
{
    public const string AJAX = 'ajax';
    public const string BACKOFFICE = 'backoffice';
    public const string CLI = 'wpcli';
    public const string CORE = 'core';
    public const string CRON = 'cron';
    public const string FRONTOFFICE = 'frontoffice';
    public const string INSTALLING = 'installing';
    public const string LOGIN = 'login';
    public const string REST = 'rest';
    public const string XML_RPC = 'xml-rpc';
    public const string WP_ACTIVATE = 'wp-activate';

    private const array ALL = [
        self::AJAX,
        self::BACKOFFICE,
        self::CLI,
        self::CORE,
        self::CRON,
        self::FRONTOFFICE,
        self::INSTALLING,
        self::LOGIN,
        self::REST,
        self::XML_RPC,
        self::WP_ACTIVATE,
    ];

    /**
     * @param array<string, bool> $data
     * @param array<string, callable> $actionCallbacks
     */
    private function __construct(
        private array $data,
        private array $actionCallbacks = [],
    ) {
    }

    public static function new(): self
    {
        return new self(array_fill_keys(self::ALL, false));
    }

    public static function determine(): self
    {
        $installing = defined('WP_INSTALLING') && WP_INSTALLING;
        $xmlRpc = defined('XMLRPC_REQUEST') && XMLRPC_REQUEST;
        $isCore = defined('ABSPATH');
        $isCli = defined('WP_CLI');
        $notInstalling = $isCore && !$installing;
        $isAjax = $notInstalling && function_exists('wp_doing_ajax') && wp_doing_ajax();
        $isAdmin = $notInstalling && function_exists('is_admin') && is_admin() && !$isAjax;
        $isCron = $notInstalling && function_exists('wp_doing_cron') && wp_doing_cron();
        $isWpActivate = $installing
            && function_exists('is_multisite')
            && is_multisite()
            && self::isWpActivateRequest();
        $undetermined = $notInstalling && !$isAdmin && !$isCron && !$isCli && !$xmlRpc && !$isAjax;
        $isRest = $undetermined && self::isRestRequest();
        $isLogin = $undetermined && !$isRest && self::isLoginRequest();
        $isFront = $undetermined && !$isRest && !$isLogin;

        $instance = new self(
            [
                self::AJAX => $isAjax,
                self::BACKOFFICE => $isAdmin,
                self::CLI => $isCli,
                self::CORE => ($isCore || $xmlRpc) && (!$installing || $isWpActivate),
                self::CRON => $isCron,
                self::FRONTOFFICE => $isFront,
                self::INSTALLING => $installing && !$isWpActivate,
                self::LOGIN => $isLogin,
                self::REST => $isRest,
                self::XML_RPC => $xmlRpc && !$installing,
                self::WP_ACTIVATE => $isWpActivate,
            ],
        );

        $instance->addActionHooks();

        return $instance;
    }

    public function force(string $context): self
    {
        if (!in_array($context, self::ALL, true)) {
            throw new \LogicException(sprintf("'%s' is not a valid context.", $context));
        }

        $this->removeActionHooks();
        $data = array_fill_keys(self::ALL, false);
        $data[$context] = true;

        if (!in_array($context, [self::INSTALLING, self::CLI, self::CORE], true)) {
            $data[self::CORE] = true;
        }

        $this->data = $data;

        return $this;
    }

    public function withCli(): self
    {
        $this->data[self::CLI] = true;

        return $this;
    }

    public function is(string $context, string ...$contexts): bool
    {
        array_unshift($contexts, $context);

        foreach ($contexts as $item) {
            if (($this->data[$item] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    public function isCore(): bool
    {
        return $this->is(self::CORE);
    }

    public function isFrontoffice(): bool
    {
        return $this->is(self::FRONTOFFICE);
    }

    public function isBackoffice(): bool
    {
        return $this->is(self::BACKOFFICE);
    }

    public function isAjax(): bool
    {
        return $this->is(self::AJAX);
    }

    public function isLogin(): bool
    {
        return $this->is(self::LOGIN);
    }

    public function isRest(): bool
    {
        return $this->is(self::REST);
    }

    public function isCron(): bool
    {
        return $this->is(self::CRON);
    }

    public function isWpCli(): bool
    {
        return $this->is(self::CLI);
    }

    public function isXmlRpc(): bool
    {
        return $this->is(self::XML_RPC);
    }

    public function isInstalling(): bool
    {
        return $this->is(self::INSTALLING);
    }

    public function isWpActivate(): bool
    {
        return $this->is(self::WP_ACTIVATE);
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }

    private static function isRestRequest(): bool
    {
        $isRestRequest = defined('REST_REQUEST') && REST_REQUEST;

        if ($isRestRequest || !empty($_GET['rest_route'])) {
            return true;
        }

        if (
            !function_exists('get_option')
            || !function_exists('add_query_arg')
            || !function_exists('get_rest_url')
        ) {
            return false;
        }

        if (!get_option('permalink_structure')) {
            return false;
        }

        if (empty($GLOBALS['wp_rewrite']) && class_exists(\WP_Rewrite::class)) {
            $GLOBALS['wp_rewrite'] = new \WP_Rewrite();
        }

        $currentPath = trim(
            (string) parse_url((string) add_query_arg([]), PHP_URL_PATH),
            '/',
        ) . '/';
        $restPath = trim((string) parse_url((string) get_rest_url(), PHP_URL_PATH), '/') . '/';

        return str_starts_with($currentPath, $restPath);
    }

    private static function isLoginRequest(): bool
    {
        if (function_exists('is_login')) {
            return is_login() !== false;
        }

        if (!empty($_REQUEST['interim-login'])) {
            return true;
        }

        if (!function_exists('wp_login_url')) {
            return false;
        }

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        return stripos(wp_login_url(), (string) $scriptName) !== false;
    }

    private static function isWpActivateRequest(): bool
    {
        if (!function_exists('network_site_url')) {
            return false;
        }

        return self::isPageNow('wp-activate.php', network_site_url('wp-activate.php'));
    }

    private static function isPageNow(string $page, string $url): bool
    {
        $pageNow = (string) ($GLOBALS['pagenow'] ?? '');

        if ($pageNow !== '' && basename($pageNow) === $page) {
            return true;
        }

        if (!function_exists('add_query_arg')) {
            return false;
        }

        $currentPath = (string) parse_url((string) add_query_arg([]), PHP_URL_PATH);
        $targetPath = (string) parse_url($url, PHP_URL_PATH);

        return trim($currentPath, '/') === trim($targetPath, '/');
    }

    private function addActionHooks(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        $this->actionCallbacks = [
            'login_init' => function (): void {
                $this->resetAndForce(self::LOGIN);
            },
            'rest_api_init' => function (): void {
                $this->resetAndForce(self::REST);
            },
            'activate_header' => function (): void {
                $this->resetAndForce(self::WP_ACTIVATE);
            },
            'template_redirect' => function (): void {
                $this->resetAndForce(self::FRONTOFFICE);
            },
            'current_screen' => function (\WP_Screen $screen): void {
                if ($screen->in_admin()) {
                    $this->resetAndForce(self::BACKOFFICE);
                }
            },
        ];

        foreach ($this->actionCallbacks as $action => $callback) {
            add_action($action, $callback, PHP_INT_MIN);
        }
    }

    private function removeActionHooks(): void
    {
        if (!function_exists('remove_action')) {
            $this->actionCallbacks = [];

            return;
        }

        foreach ($this->actionCallbacks as $action => $callback) {
            remove_action($action, $callback, PHP_INT_MIN);
        }

        $this->actionCallbacks = [];
    }

    private function resetAndForce(string $context): void
    {
        $cli = $this->isWpCli();
        $this->force($context);

        if ($cli) {
            $this->withCli();
        }
    }
}
