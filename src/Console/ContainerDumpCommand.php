<?php

declare(strict_types=1);

namespace SymPress\Kernel\Console;

use SymPress\Kernel\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Dumper\XmlDumper;
use Symfony\Component\DependencyInjection\Dumper\YamlDumper;

#[AsCommand(
    name: 'container:dump',
    description: 'Dump the configured kernel container.',
)]
final class ContainerDumpCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Dump format: php, yaml, or xml.', 'yaml');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        $format = is_string($format) ? strtolower($format) : 'yaml';
        $builder = $this->container->builder();

        if ($format === 'yaml') {
            $output->write((new YamlDumper($builder))->dump());

            return Command::SUCCESS;
        }

        if ($format === 'xml') {
            $output->write((new XmlDumper($builder))->dump());

            return Command::SUCCESS;
        }

        if ($format !== 'php') {
            $output->writeln(sprintf('<error>Unsupported dump format "%s".</error>', $format));

            return Command::FAILURE;
        }

        try {
            $builder->compile(true);
            $output->write((new PhpDumper($builder))->dump(['class' => 'DumpedKernelContainer']));
        } catch (\Throwable $throwable) {
            $output->writeln(sprintf('<error>%s</error>', $throwable->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
