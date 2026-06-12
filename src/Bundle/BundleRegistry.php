<?php

declare(strict_types=1);

namespace SymPress\Kernel\Bundle;

final class BundleRegistry implements \IteratorAggregate, \Countable
{
    /** @var array<int, BundleMetadata> */
    private array $bundles = [];

    public function add(BundleMetadata $bundle): self
    {
        $this->bundles[] = $bundle;

        return $this;
    }

    /** @return array<int, BundleMetadata> */
    public function all(): array
    {
        return $this->bundles;
    }

    /** @return array<int, string> */
    public function configDirectories(): array
    {
        $directories = [];

        foreach ($this->bundles as $bundle) {
            $configDir = $bundle->bundle()->configPath();

            if ($configDir === null) {
                continue;
            }

            $directories[] = $configDir;
        }

        return $directories;
    }

    /** @return array<int, string> */
    public function fingerprintParts(bool $trackSourceHashes = true): array
    {
        $parts = [];

        foreach ($this->bundles as $bundle) {
            $parts = [...$parts, ...$bundle->fingerprintParts($trackSourceHashes)];
        }

        return $parts;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->bundles);
    }

    public function count(): int
    {
        return count($this->bundles);
    }
}
