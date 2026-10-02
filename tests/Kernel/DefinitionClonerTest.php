<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\Kernel\DefinitionCloner;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Definition;

final class DefinitionClonerTest extends KernelTestCase
{
    public function testDeepCopyPreservesInheritedAndExplicitOverrideChanges(): void
    {
        $child = (new ChildDefinition('parent'))
            ->setBindings(['$label' => new Definition(\stdClass::class)]);
        $copy = (new DefinitionCloner())->definition($child);
        self::assertSame($child->getChanges(), $copy->getChanges());
        self::assertNotSame($child->getBindings()['$label'], $copy->getBindings()['$label']);
        self::assertNotSame($child->getBindings()['$label']->getValues()[0], $copy->getBindings()['$label']->getValues()[0]);

        $child->setFactory(null)->setConfigurator(null);
        $copy = (new DefinitionCloner())->definition($child);
        self::assertSame($child->getChanges(), $copy->getChanges());
        self::assertArrayHasKey('factory', $copy->getChanges());
        self::assertArrayHasKey('configurator', $copy->getChanges());
        self::assertNull($copy->getFactory());
        self::assertNull($copy->getConfigurator());

        $child->setFactory([new Definition(\stdClass::class), 'create'])
            ->setConfigurator([new Definition(\stdClass::class), 'configure']);
        $copy = (new DefinitionCloner())->definition($child);
        self::assertSame($child->getChanges(), $copy->getChanges());
        self::assertNotSame($child->getFactory()[0], $copy->getFactory()[0]);
        self::assertNotSame($child->getConfigurator()[0], $copy->getConfigurator()[0]);
    }

    public function testRuntimeDumpAndSourceLintRetainParentFactoryConfiguratorAndBindings(): void
    {
        $kernel = $this->kernel($this->tmpPath('definition-inheritance'));
        $container = $kernel->createContainer();
        $registry = new BundleRegistry();
        $files = $kernel->configureContainer($container->builder(), $container, $registry);
        $parent = $container->builder()->register('product.parent', DefinitionCloneProduct::class)
            ->setAbstract(true)
            ->setAutowired(true)
            ->setBindings(['$label' => 'parent-binding'])
            ->setFactory([DefinitionCloneProduct::class, 'create'])
            ->setConfigurator([DefinitionCloneProduct::class, 'configure']);
        $children = [
            'inherited' => (new ChildDefinition('product.parent'))->setPublic(true),
            'binding' => (new ChildDefinition('product.parent'))->setPublic(true)->setBindings(['$label' => 'child-binding']),
            'null-factory' => (new ChildDefinition('product.parent'))->setPublic(true)->setFactory(null),
            'null-configurator' => (new ChildDefinition('product.parent'))->setPublic(true)->setConfigurator(null),
            'changed' => (new ChildDefinition('product.parent'))->setPublic(true)
                ->setFactory([DefinitionCloneProduct::class, 'createChanged'])
                ->setConfigurator([DefinitionCloneProduct::class, 'configureChanged']),
        ];
        $changes = [];
        foreach ($children as $name => $child) {
            $container->builder()->setDefinition('product.' . $name, $child);
            $changes[$name] = $child->getChanges();
        }
        $parentBinding = $parent->getBindings()['$label']->getValues();
        $childBinding = $children['binding']->getBindings()['$label']->getValues();
        $kernel->createRuntimeContainer($container, $registry, $files);

        // Compilation must not turn inherited values into explicit null overrides
        // or mark the retained source's BoundArgument objects as consumed.
        foreach ($children as $name => $child) {
            self::assertSame($child, $container->builder()->getDefinition('product.' . $name));
            self::assertSame($changes[$name], $child->getChanges());
        }
        self::assertSame($parentBinding, $parent->getBindings()['$label']->getValues());
        self::assertSame($childBinding, $children['binding']->getBindings()['$label']->getValues());
        self::assertNull($children['inherited']->getFactory());
        self::assertNull($children['inherited']->getConfigurator());

        $expected = [
            'inherited' => ['factory/parent-binding', 'parent'],
            'binding' => ['factory/child-binding', 'parent'],
            'null-factory' => ['parent-binding', 'parent'],
            'null-configurator' => ['factory/parent-binding', ''],
            'changed' => ['changed/parent-binding', 'changed'],
        ];
        foreach ($expected as $name => [$label, $configured]) {
            $product = $container->get('product.' . $name);
            self::assertInstanceOf(DefinitionCloneProduct::class, $product);
            self::assertSame($label, $product->label);
            self::assertSame($configured, $product->configured);
        }
        // Exercise the retained source builder separately, as lint:container does.
        $container->builder()->compile();
        foreach ($expected as $name => [$label, $configured]) {
            $product = $container->builder()->get('product.' . $name);
            self::assertInstanceOf(DefinitionCloneProduct::class, $product);
            self::assertSame($label, $product->label);
            self::assertSame($configured, $product->configured);
        }
    }
}
