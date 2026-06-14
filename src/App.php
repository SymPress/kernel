<?php

declare(strict_types=1);

namespace SymPress\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Hook\HookLoader;
use SymPress\Kernel\Kernel\KernelInterface;
use SymPress\Kernel\Kernel\SiteKernel;

final class App
{
    public const string ACTION_BEFORE_CONTAINER_BUILD = 'kernel.before_container_build';
    public const string ACTION_BOOTED = 'kernel.booted';
    public const string ACTION_BOOTING = 'kernel.booting';
    public const string ACTION_CONTAINER_CONFIGURED = 'kernel.container_configured';
    public const string ACTION_CONTAINER_READY = 'kernel.container_ready';
    public const string ACTION_ERROR = 'kernel.error';
    public const string LEGACY_ACTION_BEFORE_CONTAINER_BUILD = 'symfony_before_container_build';
    public const string LEGACY_ACTION_CONTAINER_LOADED = 'symfony_container_loaded';
    public const string LEGACY_ACTION_CONTAINER_READY = 'symfony_container_ready';

    private static ?self $app = null;

    private ?Container $container = null;
    private ?BundleRegistry $bundles = null;
    private bool $booted = false;
    private bool $booting = false;

    private function __construct(
        private readonly KernelInterface $kernel,
    ) {
    }

    public static function new(?KernelInterface $kernel = null): self
    {
        if (self::$app instanceof self && $kernel === null) {
            return self::$app;
        }

        $kernel ??= new SiteKernel(self::defaultProjectDir());
        self::$app = new self($kernel);

        return self::$app;
    }

    public static function bootKernel(?KernelInterface $kernel = null): void
    {
        self::new($kernel)->boot();
    }

    public static function make(string $id, mixed $default = null): mixed
    {
        if (!self::$app instanceof self) {
            self::handleThrowable(new \RuntimeException('No valid app found.'));

            return $default;
        }

        return self::$app->resolve($id, $default);
    }

    public static function kernel(): ?KernelInterface
    {
        return self::$app?->kernel;
    }

    public static function container(): ?Container
    {
        return self::$app?->container;
    }

    public static function handleThrowable(\Throwable $throwable): void
    {
        if (function_exists('do_action')) {
            do_action(self::ACTION_ERROR, $throwable);
        }

        if (function_exists('error_log')) {
            error_log(
                sprintf(
                    '[sympress/kernel] %s: %s in %s:%d',
                    $throwable::class,
                    $throwable->getMessage(),
                    $throwable->getFile(),
                    $throwable->getLine(),
                ),
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            throw $throwable;
        }
    }

    public function enableDebug(): self
    {
        return $this;
    }

    public function disableDebug(): self
    {
        return $this;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function boot(): void
    {
        try {
            if ($this->booted) {
                return;
            }

            if ($this->booting) {
                throw new \DomainException("Can't call App::boot() when already booting.");
            }

            $this->booting = true;
            self::dispatchAction(self::ACTION_BOOTING, $this->kernel);
            $this->initializeContainer();
            $this->bundles = $this->kernel->discoverBundles();

            if (!$this->kernel->tryUseRuntimeContainer($this->container, $this->bundles)) {
                self::dispatchAction(self::ACTION_BEFORE_CONTAINER_BUILD, $this->container, $this->bundles);
                self::dispatchAction(self::LEGACY_ACTION_BEFORE_CONTAINER_BUILD);
                $loadedConfigFiles = $this->kernel->configureContainer(
                    $this->container->builder(),
                    $this->container,
                    $this->bundles,
                );
                self::dispatchAction(
                    self::ACTION_CONTAINER_CONFIGURED,
                    $this->container,
                    $this->bundles,
                    $loadedConfigFiles,
                );
                $this->kernel->createRuntimeContainer(
                    $this->container,
                    $this->bundles,
                    $loadedConfigFiles,
                );
            }

            self::dispatchAction(self::ACTION_CONTAINER_READY, $this->container, $this->bundles);
            self::dispatchAction(self::LEGACY_ACTION_CONTAINER_READY, $this->container);
            $this->registerHooks();
            $this->kernel->boot($this->container, $this->bundles);
            $this->booted = true;
            $this->booting = false;
            self::dispatchAction(self::ACTION_BOOTED, $this->container, $this->bundles);
            self::dispatchAction(self::LEGACY_ACTION_CONTAINER_LOADED, $this->container);
        } catch (\Throwable $throwable) {
            $this->booted = false;
            $this->booting = false;
            $this->container = null;
            $this->bundles = null;
            self::handleThrowable($throwable);
            throw $throwable;
        }
    }

    public function resolve(string $id, mixed $default = null): mixed
    {
        $value = $default;

        try {
            if (!$this->booted && !$this->booting) {
                throw new \DomainException("Can't resolve from an uninitialised application.");
            }

            $this->initializeContainer();

            if (!$this->container->has($id)) {
                if (function_exists('do_action')) {
                    do_action(
                        self::ACTION_ERROR,
                        new \RuntimeException(sprintf('Unknown service "%s".', $id)),
                    );
                }

                return $default;
            }

            $value = $this->container->get($id);
        } catch (\Throwable $throwable) {
            self::handleThrowable($throwable);
        }

        return $value;
    }

    private function initializeContainer(): void
    {
        if ($this->container instanceof Container) {
            return;
        }

        $this->container = $this->kernel->createContainer();
        $this->container->setApp($this);
    }

    private function registerHooks(): void
    {
        if (!$this->container instanceof Container || !$this->container->has(HookLoader::class)) {
            return;
        }

        $hookLoader = $this->container->get(HookLoader::class);

        if (!$hookLoader instanceof HookLoader) {
            return;
        }

        $hookLoader->register();
    }

    private static function defaultProjectDir(): string
    {
        $directory = __DIR__;

        while (true) {
            $composerFile = sprintf('%s/composer.json', $directory);

            if (is_file($composerFile) && self::isProjectComposerFile($composerFile)) {
                return $directory;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return dirname(__DIR__, 3);
    }

    private static function isProjectComposerFile(string $composerFile): bool
    {
        $contents = file_get_contents($composerFile);

        if (!is_string($contents) || $contents === '') {
            return false;
        }

        $metadata = json_decode($contents, true);

        return is_array($metadata) && ($metadata['type'] ?? null) === 'project';
    }

    private static function dispatchAction(string $action, mixed ...$arguments): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action($action, ...$arguments);
    }
}
