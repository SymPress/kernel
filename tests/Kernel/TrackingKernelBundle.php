<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\AbstractBundle;

final class TrackingKernelBundle extends AbstractBundle
{
    public bool $booted = false;
    public bool $shutdown = false;

    public function __construct(
        private readonly string $bundlePath,
    ) {
    }

    public function path(): string
    {
        return $this->bundlePath;
    }

    public function boot(): void
    {
        $this->booted = true;
    }

    public function shutdown(): void
    {
        $this->shutdown = true;
    }

    public function hasContainer(): bool
    {
        return $this->container !== null;
    }
}
