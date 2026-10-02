<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ContainerResourceFingerprinter
{
    public function __construct(
        private readonly string $projectDir,
        private readonly string $environment,
        private readonly bool $debug,
    ) {
    }

    /** @param array<int, string> $configFiles */
    public function fingerprint(BundleRegistry $bundles, array $configFiles): string
    {
        sort($configFiles);

        $parts = [
            $this->projectDir,
            $this->environment,
            (string) (int) $this->debug,
            $this->deploymentFingerprint(),
            $this->kernelFingerprint(),

        ];

        foreach ($bundles->all() as $bundle) {
            $parts[] = implode(':', [$bundle->package(), $bundle->type(), $bundle->entry(), $bundle->path(), $bundle->bundle()->id()]);
            if ($this->immutable()) {
                continue;
            }

            $parts[] = $this->fileFingerprint($bundle->composerFile());
        }

        // Consumed config contents (including imports) are validated separately by
        // configResourcesAreFresh and participate in the compiled generation key.
        // Keep only discovery identity here to avoid reading each config twice on hits.
        array_push($parts, ...$configFiles);

        return hash('sha256', implode('|', $parts));
    }

    public function tracksSourceChanges(): bool
    {
        return $this->debug && !$this->resourceTrackingDisabled();
    }

    /**
     * @param array<mixed, mixed> $values
     * @return array<string, mixed>
     */
    public function stringKeyMap(array $values): array
    {
        $map = [];

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $map[$key] = $value;
        }

        return $map;
    }

    /** @return array<string, string> */
    public function sourceResourceManifest(BundleRegistry $bundles): array
    {
        $resources = [];
        $this->collectSourceResources(dirname(__DIR__, 2) . '/src', $resources);

        foreach ($bundles->all() as $metadata) {
            $this->collectSourceResources(sprintf('%s/src', rtrim($metadata->path(), '/')), $resources, 'php');

            $bundleFile = (new \ReflectionObject($metadata->bundle()))->getFileName();

            if (!is_string($bundleFile)) {
                continue;
            }

            $resources[$bundleFile] = $this->sourceFileMtime($bundleFile);
        }

        ksort($resources);

        return $resources;
    }

    /** @param array<string, string> $resources */
    public function sourceResourceFingerprint(array $resources): string
    {
        ksort($resources);
        $parts = [];

        foreach ($resources as $path => $fingerprint) {
            $parts[] = sprintf('%s:%s', $path, $fingerprint);
        }

        return hash('sha256', implode('|', $parts));
    }

    public function sourceResourcesAreFresh(mixed $resources): bool
    {
        if (!is_array($resources) || $resources === []) {
            return false;
        }
        if ($this->immutable()) {
            return true;
        }

        foreach ($resources as $path => $expected) {
            if (!is_string($path) || !is_string($expected)) {
                return false;
            }

            $actual = str_starts_with($expected, 'dir:')
                ? $this->sourceDirectoryMtime($path)
                : $this->sourceFileMtime($path);

            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $configFiles
     * @return array<string, string>
     */
    public function configResourceManifest(ContainerBuilder $builder, array $configFiles): array
    {
        $resources = [];

        foreach ($configFiles as $file) {
            if ($file === '') {
                continue;
            }

            $resources[$file] = $this->fileFingerprint($file);
        }

        foreach ($builder->getResources() as $resource) {
            if ($resource instanceof DirectoryResource) {
                $key = 'directory:' . base64_encode(json_encode([$resource->getResource(), $resource->getPattern()], JSON_THROW_ON_ERROR));
                $resources[$key] = $this->configResourceValue($key);
                continue;
            }
            if ($resource instanceof FileResource) {
                $file = $resource->getResource();
                $resources[$file] = $this->fileFingerprint($file);

                continue;
            }

            if (!$resource instanceof FileExistenceResource) {
                continue;
            }

            $file = $resource->getResource();
            $resources[sprintf('exists:%s', $file)] = file_exists($file) ? 'exists:1' : 'exists:0';
        }

        ksort($resources);

        return $resources;
    }

    /** @param array<array-key, mixed> $resources
     * @return array<string, string>
     */
    public function refreshConfigResources(array $resources): array
    {
        $fresh = [];
        foreach ($resources as $key => $unused) {
            if (!is_string($key) || !is_string($unused)) {
                continue;
            }
            $fresh[$key] = $this->configResourceValue($key);
        }
        ksort($fresh);
        return $fresh;
    }

    public function configResourcesAreFresh(mixed $resources): bool
    {
        if (!is_array($resources)) {
            return false;
        }
        foreach ($resources as $path => $expected) {
            if (!is_string($path) || !is_string($expected)) {
                return false;
            }
        }
        if ($this->immutable()) {
            return true;
        }

        foreach ($resources as $path => $expected) {
            if ($this->configResourceValue($path) !== $expected) {
                return false;
            }
        }

        return true;
    }

    public function shouldValidateCachedSourceResources(): bool
    {
        $value = $_SERVER['SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES']
            ?? $_ENV['SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES']
            ?? null;

        if ($value === null) {
            return $this->tracksSourceChanges();
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) === true;
    }

    public function immutable(): bool
    {
        $enabled = $_SERVER['SYMPRESS_KERNEL_IMMUTABLE_CACHE'] ?? $_ENV['SYMPRESS_KERNEL_IMMUTABLE_CACHE'] ?? getenv('SYMPRESS_KERNEL_IMMUTABLE_CACHE');
        if (filter_var($enabled, FILTER_VALIDATE_BOOL) !== true) {
            return false;
        }
        $build = defined('SYMPRESS_KERNEL_BUILD_ID') ? constant('SYMPRESS_KERNEL_BUILD_ID') : ($_SERVER['SYMPRESS_KERNEL_BUILD_ID'] ?? $_ENV['SYMPRESS_KERNEL_BUILD_ID'] ?? getenv('SYMPRESS_KERNEL_BUILD_ID'));
        if (!is_string($build) || $build === '') {
            throw new \RuntimeException('Immutable kernel cache requires an explicit deployment build ID.');
        }
        return true;
    }

    /**
     * @param list<string> $directories
     * @return array<string, string>
     */
    public function discoveryResourceManifest(array $directories): array
    {
        $resources = [];
        foreach ($directories as $directory) {
            foreach ([$directory, $directory . '/packages', $directory . '/packages/' . $this->environment] as $path) {
                $resources[$path] = $this->sourceDirectoryMtime($path);
            }
        }
        return $resources;
    }

    public function discoveryResourcesAreFresh(mixed $resources): bool
    {
        if (!is_array($resources)) {
            return false;
        }
        if ($this->immutable()) {
            return true;
        }
        foreach ($resources as $path => $expected) {
            if (!is_string($path) || $this->sourceDirectoryMtime($path) !== $expected) {
                return false;
            }
        }
        return true;
    }

    private function resourceTrackingDisabled(): bool
    {
        $value = $_SERVER['SYMFONY_DISABLE_RESOURCE_TRACKING']
            ?? $_ENV['SYMFONY_DISABLE_RESOURCE_TRACKING']
            ?? null;

        if ($value === null) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return true;
        }

        $value = (string) $value;

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? ($value !== '');
    }

    private function configResourceValue(string $key): string
    {
        if (str_starts_with($key, 'exists:')) {
            return file_exists(substr($key, 7)) ? 'exists:1' : 'exists:0';
        }
        if (!str_starts_with($key, 'directory:')) {
            return $this->fileFingerprint($key);
        }
        $decoded = base64_decode(substr($key, 10), true);
        $descriptor = is_string($decoded) ? json_decode($decoded, true) : null;
        if (
            !is_array($descriptor) || count($descriptor) !== 2 || !is_string($descriptor[0] ?? null)
            || (!is_string($descriptor[1] ?? null) && ($descriptor[1] ?? null) !== null)
        ) {
            return 'invalid-directory';
        }
        $root = $descriptor[0];
        $pattern = is_string($descriptor[1] ?? null) ? $descriptor[1] : null;
        if (!is_dir($root) || is_link($root)) {
            return 'missing-directory';
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }
            if ($pattern !== null && preg_match($pattern, $file->getFilename()) !== 1) {
                continue;
            }
            $path = $file->getPathname();
            $files[$path] = $this->fileFingerprint($path);
        }
        ksort($files);
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }

    private function fileFingerprint(string $file): string
    {
        if (!is_file($file)) {
            return 'missing';
        }

        clearstatcache(true, $file);
        $hash = hash_file('sha256', $file);

        return is_string($hash) ? $hash : 'unreadable';
    }

    private function deploymentFingerprint(): string
    {
        $buildId = defined('SYMPRESS_KERNEL_BUILD_ID')
            ? constant('SYMPRESS_KERNEL_BUILD_ID')
            : ($_SERVER['SYMPRESS_KERNEL_BUILD_ID'] ?? $_ENV['SYMPRESS_KERNEL_BUILD_ID'] ?? getenv('SYMPRESS_KERNEL_BUILD_ID'));

        if ((is_scalar($buildId) || $buildId instanceof \Stringable) && (string) $buildId !== '') {
            return 'build:' . (string) $buildId;
        }

        $composerLock = sprintf('%s/composer.lock', $this->projectDir);

        if (is_file($composerLock)) {
            return 'composer-lock:' . $this->fileFingerprint($composerLock);
        }

        return 'build:implicit';
    }

    private function kernelFingerprint(): string
    {
        $packageDir = dirname(__DIR__, 2);
        $composerFile = sprintf('%s/composer.json', $packageDir);

        return hash(
            'sha256',
            implode(
                '|',
                [
                    $packageDir,
                    $this->immutable() ? 'immutable' : sprintf('%s:%s', $composerFile, $this->fileFingerprint($composerFile)),
                ],
            ),
        );
    }

    /**
     * @param array<string, string> $resources
     */
    private function collectSourceResources(
        string $directory,
        array &$resources,
        ?string $fileExtension = null,
    ): void {

        if (!is_dir($directory)) {
            return;
        }

        $resources[$directory] = $this->sourceDirectoryMtime($directory);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $resource) {
            if (!$resource instanceof \SplFileInfo) {
                continue;
            }

            $pathname = $resource->getPathname();

            if ($resource->isDir()) {
                $resources[$pathname] = $this->sourceDirectoryMtime($pathname);

                continue;
            }

            if (!$resource->isFile()) {
                continue;
            }

            if ($fileExtension !== null && $resource->getExtension() !== $fileExtension) {
                continue;
            }

            $resources[$pathname] = $this->sourceFileMtime($pathname);
        }
    }

    private function sourceDirectoryMtime(string $directory): string
    {
        if (!is_dir($directory)) {
            return 'missing';
        }

        clearstatcache(true, $directory);

        return 'dir:' . hash('sha256', implode('\0', scandir($directory) ?: []));
    }

    private function sourceFileMtime(string $file): string
    {
        if (!is_file($file)) {
            return 'missing';
        }

        clearstatcache(true, $file);
        if ($this->tracksSourceChanges()) {
            return $this->fileFingerprint($file);
        }

        return sprintf('file:%s:%s', (string) filemtime($file), (string) filesize($file));
    }
}
