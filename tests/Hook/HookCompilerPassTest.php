<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Hook;

use SymPress\Kernel\Hook\HookCompilerPass;
use SymPress\Kernel\Hook\HookLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

final class HookCompilerPassTest extends TestCase
{
    public function testAcceptedArgsAreInferredFromHookMethod(): void
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(
            HookLoader::class,
            (new Definition(HookLoader::class))
                ->setPublic(true)
                ->setArguments([null, []]),
        );
        $builder->register(TestHook::class, TestHook::class)
            ->addTag(
                HookLoader::TAG,
                [
                    'hook' => 'init',
                    'method' => 'register',
                    'type' => 'action',
                    'priority' => 20,
                ],
            );

        (new HookCompilerPass())->process($builder);

        $definition = $builder->getDefinition(HookLoader::class);
        $hooks = $definition->getArgument(1);

        self::assertCount(1, $hooks);
        self::assertSame(TestHook::class, $hooks[0]['service']);
        self::assertSame('init', $hooks[0]['hook']);
        self::assertSame('register', $hooks[0]['method']);
        self::assertSame('action', $hooks[0]['type']);
        self::assertSame(20, $hooks[0]['priority']);
        self::assertSame(2, $hooks[0]['accepted_args']);
    }

    public function testMissingHookMethodFailsDuringCompilation(): void
    {
        $builder = $this->builderWithHookLoader();
        $builder->register(TestHook::class, TestHook::class)
            ->addTag(
                HookLoader::TAG,
                [
                    'hook' => 'init',
                    'method' => 'missing',
                ],
            );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not define hook method "missing"');

        (new HookCompilerPass())->process($builder);
    }

    public function testInvalidAcceptedArgsFailsDuringCompilation(): void
    {
        $builder = $this->builderWithHookLoader();
        $builder->register(TestHook::class, TestHook::class)
            ->addTag(
                HookLoader::TAG,
                [
                    'hook' => 'init',
                    'method' => 'register',
                    'accepted_args' => 'many',
                ],
            );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('accepted_args');

        (new HookCompilerPass())->process($builder);
    }

    private function builderWithHookLoader(): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->setDefinition(
            HookLoader::class,
            (new Definition(HookLoader::class))
                ->setPublic(true)
                ->setArguments([null, []]),
        );

        return $builder;
    }
}

final class TestHook
{
    public function register(string $context, int $priority): void
    {
    }
}
