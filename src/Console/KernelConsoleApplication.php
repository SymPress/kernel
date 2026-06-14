<?php

declare(strict_types=1);

namespace SymPress\Kernel\Console;

use SymPress\Kernel\Container;
use SymPress\Kernel\Kernel\KernelInterface;
use Symfony\Component\Console\Application;

final class KernelConsoleApplication extends Application
{
    public function __construct(
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct('SymPress Console', $kernel->getEnvironment());
    }

    public function getKernel(): KernelInterface
    {
        return $this->kernel;
    }

    public function getContainer(): Container
    {
        return $this->kernel->getContainer();
    }
}
