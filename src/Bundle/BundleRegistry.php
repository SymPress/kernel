<?php

declare(strict_types=1);

namespace SymPress\Kernel\Bundle;

/** @implements \IteratorAggregate<int, BundleMetadata> */
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
            foreach ($this->bundleConfigDirectories($bundle->bundle()) as $configDir) {
                $directories[] = $configDir;
            }
        }

        return array_values(array_unique($directories));
    }

    /** @return array<string, string> */
    public function translationDirectories(): array
    {
        $directories = [];

        foreach ($this->bundles as $metadata) {
            $bundle = $metadata->bundle();
            $translationPath = $bundle->translationPath();

            if (!is_string($translationPath) || $translationPath === '') {
                continue;
            }

            $directories[$metadata->package()] = $translationPath;
        }

        return $directories;
    }

    /** @return array<int, string> */
    public function identityFingerprintParts(): array
    {
        $parts = [];

        foreach ($this->bundles as $bundle) {
            $parts = [...$parts, ...$bundle->identityFingerprintParts()];
        }

        return $parts;
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

    /** @return list<string> */
    private function bundleConfigDirectories(BundleInterface $bundle): array
    {
        return array_values(
            array_filter(
                $bundle->configPaths(),
                static fn (string $path): bool => $path !== '',
            ),
        );
    }
}
