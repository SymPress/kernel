<?php

declare(strict_types=1);

namespace SymPress\Kernel\Console;

use SymPress\Kernel\Kernel\KernelInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

final readonly class ConsoleApplicationFactory
{
    public function __construct(
        private KernelInterface $kernel,
        private CommandLoaderInterface $commandLoader,
    ) {
    }

    public function create(): Application
    {
        $application = new Application('SymPress Console', $this->kernel->getEnvironment());
        $application->setAutoExit(false);
        $application->setCommandLoader($this->commandLoader);

        return $application;
    }
}
