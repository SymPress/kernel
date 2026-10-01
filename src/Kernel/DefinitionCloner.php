<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\Definition;

/** Keep runtime compilation from rewriting the source builder's mutable definition graph. */
final class DefinitionCloner
{
    /** @var \SplObjectStorage<object, object> */
    private \SplObjectStorage $copies;

    public function __construct()
    {
        $this->copies = new \SplObjectStorage();
    }

    public function definition(Definition $source): Definition
    {
        if ($this->copies->offsetExists($source)) {
            /** @var Definition $copy */
            $copy = $this->copies[$source];
            return $copy;
        }
        $copy = clone $source;
        $this->copies[$source] = $copy;
        $copy->setArguments($this->values($source->getArguments()));
        $copy->setProperties($this->values($source->getProperties()));
        $copy->setMethodCalls($this->values($source->getMethodCalls()));
        $copy->setBindings($this->values($source->getBindings()));
        $copy->setInstanceofConditionals($this->values($source->getInstanceofConditionals()));
        $factory = $source->getFactory();
        $copy->setFactory(is_array($factory) ? $this->values($factory) : $factory);
        $configurator = $source->getConfigurator();
        $copy->setConfigurator(is_array($configurator) ? $this->values($configurator) : $configurator);
        return $copy;
    }

    /**
     * @template T of array
     * @param T $source
     * @return T
     */
    private function values(array $source): array
    {
        foreach ($source as $key => $value) {
            if ($value instanceof Definition) {
                $source[$key] = $this->definition($value);
            } elseif ($value instanceof ArgumentInterface) {
                $copy = clone $value;
                $copy->setValues($this->values($value->getValues()));
                $source[$key] = $copy;
            } elseif (is_array($value)) {
                $source[$key] = $this->values($value);
            }
        }
        // Array shapes and definition/argument types are unchanged by this recursive copy.
        /** @var T $copy */
        $copy = $source;
        return $copy;
    }
}
