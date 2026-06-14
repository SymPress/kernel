<?php

declare(strict_types=1);

namespace SymPress\Kernel\Discovery;

use Composer\InstalledVersions;

final class KernelPackageManifestCache
{
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

        $metadata = require $file;

        if (!is_array($metadata) || ($metadata['fingerprint'] ?? null) !== $this->fingerprint()) {
            return null;
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

    /** @param list<string> $packages */
    public function write(array $packages): void
    {
        $file = $this->cacheFile();

        if ($file === null) {
            return;
        }

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            return;
        }

        sort($packages);
        $payload = sprintf(
            "<?php\n\nreturn %s;\n",
            var_export(
                [
                    'fingerprint' => $this->fingerprint(),
                    'packages'    => array_values(array_unique($packages)),
                ],
                true,
            ),
        );
        $temporaryFile = sprintf('%s.%s.tmp', $file, bin2hex(random_bytes(6)));

        if (file_put_contents($temporaryFile, $payload, LOCK_EX) === false) {
            return;
        }

        @rename($temporaryFile, $file);
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

        return sprintf(
            '%s/var/cache/%s/kernel/discovery-packages.php',
            rtrim($this->projectDir, '/'),
            $environment,
        );
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
                    $this->fileFingerprint($this->rootComposerFile()),
                    $this->fileFingerprint($this->rootComposerLockFile()),
                    $this->fileFingerprint($this->installedPackagesFile()),
                ],
            ),
        );
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
        if ($file === '' || !is_file($file)) {
            return 'missing';
        }

        return sprintf('%s:%s', (string) filemtime($file), (string) filesize($file));
    }
}
