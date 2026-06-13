<?php

declare(strict_types=1);

namespace SymPress\Kernel\Console;

use SymPress\Kernel\Container;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

#[AsCommand(
    name: 'debug:container',
    description: 'List kernel services or parameters.',
)]
final class DebugContainerCommand extends Command
{
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('search', InputArgument::OPTIONAL, 'Optional service or parameter substring filter.')
            ->addOption('parameters', null, InputOption::VALUE_NONE, 'List container parameters instead of services.')
            ->addOption('env-vars', null, InputOption::VALUE_NONE, 'List env placeholders known to the container.')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'List services with the given tag.')
            ->addOption('types', null, InputOption::VALUE_NONE, 'Show service class, visibility, and tags.')
            ->addOption('show-arguments', null, InputOption::VALUE_NONE, 'Show constructor arguments for definitions.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $search = strtolower((string) $input->getArgument('search'));
        $items = $this->items($input);

        foreach ($items as $item) {
            if ($search !== '' && !str_contains(strtolower($item), $search)) {
                continue;
            }

            $output->writeln($item);
        }

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function items(InputInterface $input): array
    {
        if ($input->getOption('parameters')) {
            return $this->parameters();
        }

        if ($input->getOption('env-vars')) {
            return $this->envVars();
        }

        $tag = $input->getOption('tag');

        if (is_string($tag) && $tag !== '') {
            return $this->taggedServices(
                $tag,
                (bool) $input->getOption('types'),
                (bool) $input->getOption('show-arguments'),
            );
        }

        if ($input->getOption('types') || $input->getOption('show-arguments')) {
            return $this->describedServices((bool) $input->getOption('show-arguments'));
        }

        return $this->serviceIds();
    }

    /** @return list<string> */
    private function serviceIds(): array
    {
        $runtimeContainer = $this->container->runtimeContainer();

        if (
            $runtimeContainer instanceof SymfonyContainerInterface
            && method_exists($runtimeContainer, 'getServiceIds')
        ) {
            $ids = $runtimeContainer->getServiceIds();
            sort($ids);

            return array_values($ids);
        }

        $ids = $this->container->builder()->getServiceIds();
        sort($ids);

        return array_values($ids);
    }

    /** @return list<string> */
    private function taggedServices(string $tag, bool $types, bool $showArguments): array
    {
        $builder = $this->container->builder();
        $ids = array_keys($builder->findTaggedServiceIds($tag));
        sort($ids);

        if (!$types && !$showArguments) {
            return array_values($ids);
        }

        return array_map(
            fn (string $id): string => $this->describeService($id, $showArguments),
            $ids,
        );
    }

    /** @return list<string> */
    private function describedServices(bool $showArguments): array
    {
        $ids = $this->serviceIds();

        return array_map(
            fn (string $id): string => $this->describeService($id, $showArguments),
            $ids,
        );
    }

    private function describeService(string $id, bool $showArguments): string
    {
        $builder = $this->container->builder();

        if ($builder->hasAlias($id)) {
            return $this->describeAlias($id, $builder->getAlias($id));
        }

        if (!$builder->hasDefinition($id)) {
            return $id;
        }

        $definition = $builder->getDefinition($id);
        $parts = [$id];
        $class = $definition->getClass();

        if (is_string($class) && $class !== '') {
            $parts[] = sprintf('class=%s', $class);
        }

        $parts[] = $definition->isPublic() ? 'public' : 'private';

        $tags = array_keys($definition->getTags());

        if ($tags !== []) {
            sort($tags);
            $parts[] = sprintf('tags=%s', implode(',', $tags));
        }

        if ($showArguments) {
            $parts[] = sprintf('arguments=%s', $this->formatValue($definition->getArguments()));
        }

        return implode(' ', $parts);
    }

    private function describeAlias(string $id, Alias $alias): string
    {
        return sprintf('%s alias=%s %s', $id, (string) $alias, $alias->isPublic() ? 'public' : 'private');
    }

    /** @return list<string> */
    private function parameters(): array
    {
        $builder = $this->container->builder();

        if (!$builder instanceof ContainerBuilder) {
            return [];
        }

        $parameters = array_keys($builder->getParameterBag()->all());
        sort($parameters);

        return array_values($parameters);
    }

    /** @return list<string> */
    private function envVars(): array
    {
        $builder = $this->container->builder();
        $envs = [];

        foreach ($builder->getParameterBag()->all() as $value) {
            foreach ($this->extractEnvVars($value) as $env) {
                $envs[] = $env;
            }
        }

        foreach ($builder->getDefinitions() as $definition) {
            foreach ($this->extractEnvVars($definition->getArguments()) as $env) {
                $envs[] = $env;
            }
        }

        $envs = array_values(array_unique($envs));
        sort($envs);

        return $envs;
    }

    /** @return list<string> */
    private function extractEnvVars(mixed $value): array
    {
        if (is_string($value) && preg_match_all('/%env\\(([^)]+)\\)%/', $value, $matches) > 0) {
            return $matches[1];
        }

        if (!is_array($value)) {
            return [];
        }

        $envs = [];

        foreach ($value as $item) {
            $envs = [...$envs, ...$this->extractEnvVars($item)];
        }

        return $envs;
    }

    private function formatValue(mixed $value): string
    {
        if ($value instanceof Reference) {
            return sprintf('@%s', (string) $value);
        }

        if (is_array($value)) {
            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = sprintf('%s: %s', (string) $key, $this->formatValue($item));
            }

            return sprintf('[%s]', implode(', ', $parts));
        }

        if (is_scalar($value) || $value === null) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return get_debug_type($value);
    }
}
