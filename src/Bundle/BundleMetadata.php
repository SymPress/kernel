<?php

declare(strict_types=1);

namespace SymPress\Kernel\Bundle;

final readonly class BundleMetadata
{
    public function __construct(
        private string $package,
        private string $type,
        private string $entry,
        private string $path,
        private string $composerFile,
        private BundleInterface $bundle,
    ) {
    }

    public function package(): string
    {
        return $this->package;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function entry(): string
    {
        return $this->entry;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function composerFile(): string
    {
        return $this->composerFile;
    }

    public function bundle(): BundleInterface
    {
        return $this->bundle;
    }

    /**
     * @return array<int, string>
     */
    public function fingerprintParts(bool $trackSourceHashes = true): array
    {
        $reflection = new \ReflectionObject($this->bundle);
        $bundleFile = (string) $reflection->getFileName();

        $parts = [
            $this->package,
            $this->type,
            $this->entry,
            $this->bundle->id(),
            sprintf(
                '%s:%s',
                $this->composerFile,
                is_file($this->composerFile) ? (string) filemtime($this->composerFile) : 'missing',
            ),
        ];

        if (!$trackSourceHashes) {
            $parts[] = sprintf(
                '%s:%s',
                $bundleFile,
                $bundleFile !== '' && is_file($bundleFile) ? (string) filemtime($bundleFile) : 'missing',
            );

            return $parts;
        }

        $parts[] = $this->sourceFingerprint();
        $parts[] = sprintf(
            '%s:%s',
            $bundleFile,
            $bundleFile !== '' && is_file($bundleFile) ? sha1_file($bundleFile) : 'missing',
        );
        $parts[] = sprintf(
            '%s:%s',
            $this->composerFile,
            is_file($this->composerFile) ? sha1_file($this->composerFile) : 'missing',
        );

        return $parts;
    }

    private function sourceFingerprint(): string
    {
        $sourceDir = sprintf('%s/src', rtrim($this->path, '/'));

        if (!is_dir($sourceDir)) {
            return 'source:missing';
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $pathname = $file->getPathname();
            $files[] = sprintf('%s:%s', $pathname, sha1_file($pathname));
        }

        sort($files);

        return sprintf('source:%s', hash('sha256', implode('|', $files)));
    }
}
