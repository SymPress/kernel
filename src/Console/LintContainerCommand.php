<?php

declare(strict_types=1);

namespace SymPress\Kernel\Console;

use SymPress\Kernel\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'lint:container',
    description: 'Compile-check the configured kernel container.',
)]
final class LintContainerCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            ConfiguredBuilder::of($this->container)->compile(true);
        } catch (\Throwable $throwable) {
            $output->writeln(sprintf('<error>%s</error>', $throwable->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln('<info>The container is valid.</info>');

        return Command::SUCCESS;
    }
}
