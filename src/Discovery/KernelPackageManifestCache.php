<?php

declare(strict_types=1);

namespace SymPress\Kernel\Discovery;

use Composer\InstalledVersions;
use SymPress\Kernel\Kernel\CachePath;
use SymPress\Kernel\Kernel\ContainerResourceFingerprinter;
use SymPress\Kernel\Kernel\ResourceFingerprint;

final class KernelPackageManifestCache
{
    /** @var array<string, array<string, mixed>> */
    private array $metadata = [];

    /** @return array<string, array<string, mixed>> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param list<string> $packagePrefixes
     */
    public function __construct(
        private readonly ?string $projectDir,
        private readonly ?string $environment,
        private readonly array $packagePrefixes,
    ) {
    }

    /** @return list<string>|null */
    public function read(): ?array
    {
        $file = $this->cacheFile();

        if ($file === null || !is_file($file)) {
            return null;
        }

        if (is_link($file) || is_link(dirname($file)) || (fileperms(dirname($file)) & 0022) !== 0) {
            return null;
        }
        try {
            $contents = file_get_contents($file);
            $metadata = $contents === false ? null : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($metadata) || ($metadata['fingerprint'] ?? null) !== $this->fingerprint()) {
            return null;
        }

        $inputs = $metadata['inputs'] ?? null;
        $descriptors = $metadata['metadata'] ?? null;
        if (!is_array($inputs) || !is_array($descriptors)) {
            return null;
        }
        foreach ($inputs as $path => $expected) {
            if (!is_string($path) || (!$this->immutable() && $this->fileFingerprint($path) !== $expected)) {
                return null;
            }
        }
        foreach ($descriptors as $path => $descriptor) {
            if (!is_string($path) || !is_array($descriptor)) {
                return null;
            }
            $projected = [];
            foreach ($descriptor as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                $projected[$key] = $value;
            }
            $this->metadata[$path] = $projected;
        }

        $packages = $metadata['packages'] ?? null;

        if (!is_array($packages)) {
            return null;
        }

        $packages = array_values(
            array_filter(
                $packages,
                static fn (mixed $package): bool => is_string($package) && $package !== '',
            ),
        );

        sort($packages);

        return $packages;
    }

    /**
     * @param list<string> $packages
     * @param array<string, array<string, mixed>> $metadata
     * @param array<string, string> $inputs
     */
    public function write(array $packages, array $metadata = [], array $inputs = []): void
    {
        $file = $this->cacheFile();

        if ($file === null) {
            return;
        }

        $directory = dirname($file);

        try {
            CachePath::ensureDirectory($directory);
        } catch (\RuntimeException) {
            return;
        }

        sort($packages);
        $payload = json_encode(
            [
                'fingerprint' => $this->fingerprint(),
                'packages'    => array_values(array_unique($packages)),
                'metadata' => $metadata,
                'inputs' => $inputs,
            ],
            JSON_THROW_ON_ERROR,
        );
        $temporaryFile = sprintf('%s.%s.tmp', $file, bin2hex(random_bytes(6)));

        $previousMask = umask(0077);
        try {
            if (file_put_contents($temporaryFile, $payload, LOCK_EX) === false) {
                return;
            }
            chmod($temporaryFile, 0600);
            @rename($temporaryFile, $file);
        } finally {
            umask($previousMask);
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    private function cacheFile(): ?string
    {
        if ($this->projectDir === null || $this->projectDir === '') {
            return null;
        }

        $environment = $this->environment;

        if ($environment === null || $environment === '') {
            $environment = 'production';
        }

        $configured = $_SERVER['APP_CACHE_DIR'] ?? $_ENV['APP_CACHE_DIR'] ?? getenv('APP_CACHE_DIR');
        return CachePath::resolve($this->projectDir, $environment, is_string($configured) && $configured !== '' ? $configured : null) . '/discovery-packages.json';
    }

    private function fingerprint(): string
    {
        return hash(
            'sha256',
            implode(
                '|',
                [
                    (string) $this->projectDir,
                    (string) $this->environment,
                    implode(',', $this->packagePrefixes),
                    ResourceFingerprint::contentHashes() ? 'content' : 'metadata',
                    $this->immutable() ? 'immutable' : $this->fileFingerprint($this->rootComposerFile()),
                    $this->immutable() ? $this->buildIdentity() : $this->fileFingerprint($this->rootComposerLockFile()),
                    $this->immutable() ? 'immutable' : $this->fileFingerprint($this->installedPackagesFile()),
                ],
            ),
        );
    }

    private function buildIdentity(): string
    {
        $value = defined('SYMPRESS_KERNEL_BUILD_ID') ? constant('SYMPRESS_KERNEL_BUILD_ID') : ($_SERVER['SYMPRESS_KERNEL_BUILD_ID'] ?? $_ENV['SYMPRESS_KERNEL_BUILD_ID'] ?? getenv('SYMPRESS_KERNEL_BUILD_ID'));
        return is_string($value) ? $value : '';
    }

    private function immutable(): bool
    {
        return (new ContainerResourceFingerprinter((string) $this->projectDir, (string) $this->environment, false))->immutable();
    }

    private function rootComposerFile(): string
    {
        return sprintf('%s/composer.json', rtrim((string) $this->projectDir, '/'));
    }

    private function rootComposerLockFile(): string
    {
        return sprintf('%s/composer.lock', rtrim((string) $this->projectDir, '/'));
    }

    private function installedPackagesFile(): string
    {
        $reflection = new \ReflectionClass(InstalledVersions::class);
        $file = $reflection->getFileName();

        if (!is_string($file)) {
            return '';
        }

        return sprintf('%s/installed.php', dirname($file));
    }

    private function fileFingerprint(string $file): string
    {
        return ResourceFingerprint::file($file);
    }
}
