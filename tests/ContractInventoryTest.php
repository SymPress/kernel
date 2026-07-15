<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\App;
use Symfony\Component\Console\Attribute\AsCommand;

final class ContractInventoryTest extends TestCase
{
    public function testMachineReadableContractInventoryMatchesRuntimeSurface(): void
    {
        $root = dirname(__DIR__);
        $inventory = json_decode(
            (string) file_get_contents($root . '/resources/kernel-contracts.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $schema = json_decode(
            (string) file_get_contents($root . '/' . $inventory['composerExtraSchema']),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $schemaKeys = array_keys($schema['properties']);
        sort($schemaKeys);
        self::assertSame($inventory['composerExtraKeys'], $schemaKeys);

        foreach ($inventory['publicEntryPoints'] as $class) {
            self::assertTrue(class_exists($class) || interface_exists($class), "Missing public entry point {$class}.");
        }

        foreach ($inventory['lifecycleActions'] as $constant => $value) {
            self::assertSame($value, constant(App::class . '::' . $constant));
        }

        foreach ($inventory['consoleCommands'] as $name => $class) {
            $attributes = (new \ReflectionClass($class))->getAttributes(AsCommand::class);
            self::assertCount(1, $attributes, "{$class} must declare one AsCommand attribute.");
            self::assertSame($name, $attributes[0]->getArguments()['name'] ?? null);
        }
    }
}
