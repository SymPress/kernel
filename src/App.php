<?php

declare(strict_types=1);

namespace SymPress\Kernel;

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
    private bool $booted = false;
    private bool $booting = false;
    private ?bool $debugEnabled = null;

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
        $this->debugEnabled = true;
        $this->applyDebugState();

        return $this;
    }

    public function disableDebug(): self
    {
        $this->debugEnabled = false;
        $this->applyDebugState();

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
            $container = $this->initializeContainer();
            $bundles = $this->kernel->discoverBundles();

            if (!$this->kernel->tryUseRuntimeContainer($container, $bundles)) {
                self::dispatchAction(self::ACTION_BEFORE_CONTAINER_BUILD, $container, $bundles);
                self::dispatchAction(self::LEGACY_ACTION_BEFORE_CONTAINER_BUILD);
                $loadedConfigFiles = $this->kernel->configureContainer(
                    $container->builder(),
                    $container,
                    $bundles,
                );
                self::dispatchAction(
                    self::ACTION_CONTAINER_CONFIGURED,
                    $container,
                    $bundles,
                    $loadedConfigFiles,
                );
                $this->kernel->createRuntimeContainer(
                    $container,
                    $bundles,
                    $loadedConfigFiles,
                );
            }

            self::dispatchAction(self::ACTION_CONTAINER_READY, $container, $bundles);
            self::dispatchAction(self::LEGACY_ACTION_CONTAINER_READY, $container);
            $this->applyDebugState();
            $this->registerHooks();
            $this->kernel->boot($container, $bundles);
            $this->booted = true;
            $this->booting = false;
            self::dispatchAction(self::ACTION_BOOTED, $container, $bundles);
            self::dispatchAction(self::LEGACY_ACTION_CONTAINER_LOADED, $container);
        } catch (\Throwable $throwable) {
            $this->booted = false;
            $this->booting = false;
            $this->container = null;
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

            $container = $this->initializeContainer();

            if (!$container->has($id)) {
                if (function_exists('do_action')) {
                    do_action(
                        self::ACTION_ERROR,
                        new \RuntimeException(sprintf('Unknown service "%s".', $id)),
                    );
                }

                return $default;
            }

            $value = $container->get($id);
        } catch (\Throwable $throwable) {
            self::handleThrowable($throwable);
        }

        return $value;
    }

    private function initializeContainer(): Container
    {
        if ($this->container instanceof Container) {
            return $this->container;
        }

        $container = $this->kernel->createContainer();
        $container->setApp($this);
        $this->container = $container;

        return $container;
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

    private function applyDebugState(): void
    {
        if (
            !$this->container instanceof Container
            || $this->debugEnabled === null
            || !$this->container->has('profiler')
        ) {
            return;
        }

        $profiler = $this->container->get('profiler');
        $method = $this->debugEnabled ? 'enable' : 'disable';

        if (!is_object($profiler) || !method_exists($profiler, $method)) {
            return;
        }

        $profiler->{$method}();
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
        if ($action === '' || !function_exists('do_action')) {
            return;
        }

        do_action($action, ...$arguments);
    }
}
