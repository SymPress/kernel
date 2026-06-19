<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use SymPress\Kernel\Bundle\BundleRegistry;
use SymPress\Kernel\SiteConfig;

final readonly class KernelConfigurationResolver
{
    public function __construct(
        private string $projectDir,
        private string $environment,
        private SiteConfig $config,
    ) {
    }

    public function cacheDir(): string
    {
        $dir = $this->serverString('APP_CACHE_DIR');

        if ($dir !== null) {
            return sprintf('%s/kernel', $this->environmentDirectory($dir));
        }

        return sprintf('%s/var/cache/%s/kernel', $this->projectDir, $this->environment);
    }

    public function buildDir(): string
    {
        $dir = $this->serverString('APP_BUILD_DIR');

        if ($dir !== null) {
            return sprintf('%s/kernel', $this->environmentDirectory($dir));
        }

        return $this->cacheDir();
    }

    public function shareDir(): ?string
    {
        $dir = $this->serverNullableDirectory('APP_SHARE_DIR');

        if ($dir !== null) {
            return sprintf('%s/kernel', $this->environmentDirectory($dir));
        }

        if ($this->serverValueIsFalse('APP_SHARE_DIR')) {
            return null;
        }

        return $this->cacheDir();
    }

    public function logDir(): ?string
    {
        $dir = $this->serverNullableDirectory('APP_LOG_DIR');

        if ($dir !== null) {
            return $this->environmentDirectory($dir);
        }

        if ($this->serverValueIsFalse('APP_LOG_DIR')) {
            return null;
        }

        return sprintf('%s/var/log', $this->projectDir);
    }

    /** @return list<string> */
    public function packagePrefixes(): array
    {
        $configured = $this->config->get('KERNEL_PACKAGE_PREFIXES', null);

        if ($configured === null) {
            $configured = $this->composerKernelPackagePrefixes();
        }

        return $this->normalizePackagePrefixes($configured);
    }

    /** @return list<string> */
    public function knownEnvironments(): array
    {
        $known = [
            $this->environment,
            'all',
            'dev',
            'development',
            'local',
            'prod',
            'production',
            'stage',
            'staging',
            'test',
        ];
        $bundlesDefinition = sprintf('%s/config/bundles.php', $this->projectDir);

        if (!is_file($bundlesDefinition)) {
            return $this->normalizeKnownEnvironments($known);
        }

        $configuration = require $bundlesDefinition;

        if (!is_array($configuration)) {
            return $this->normalizeKnownEnvironments($known);
        }

        foreach ($configuration as $envs) {
            if (!is_array($envs)) {
                continue;
            }

            foreach (array_keys($envs) as $environment) {
                if (!is_string($environment)) {
                    continue;
                }

                $known[] = $environment;
            }
        }

        return $this->normalizeKnownEnvironments($known);
    }

    /** @return array<int, string> */
    public function configDirectories(BundleRegistry $bundles): array
    {
        $directories = [];
        $libraryDir = dirname(__DIR__, 2) . '/config';
        $siteDir = sprintf('%s/config', $this->projectDir);

        if (is_dir($libraryDir)) {
            $directories[] = $libraryDir;
        }

        foreach ($bundles->configDirectories() as $configDir) {
            $directories[] = $configDir;
        }

        if (is_dir($siteDir)) {
            $directories[] = $siteDir;
        }

        return array_values(array_unique($directories));
    }

    /** @return array<int, string> */
    public function resolveConfigFiles(string $configDir): array
    {
        $files = [];

        foreach ($this->patterns($configDir) as $pattern) {
            $matches = glob($pattern, GLOB_BRACE) ?: [];
            sort($matches);
            $files = [...$files, ...$matches];
        }

        return $files;
    }

    /** @return array<int, string> */
    public function runtimeConfigFiles(BundleRegistry $bundles): array
    {
        $files = [];

        foreach ($this->configDirectories($bundles) as $configDir) {
            $files = [...$files, ...$this->resolveConfigFiles($configDir)];
        }

        return array_values(array_unique($files));
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

    private function composerKernelPackagePrefixes(): mixed
    {
        $composerFile = sprintf('%s/composer.json', $this->projectDir);

        if (!is_file($composerFile)) {
            return null;
        }

        $contents = file_get_contents($composerFile);

        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $metadata = json_decode($contents, true);

        if (!is_array($metadata)) {
            return null;
        }

        $metadata = $this->stringKeyMap($metadata);
        $extra = $metadata['extra'] ?? null;
        $kernel = is_array($extra) ? ($extra['kernel'] ?? null) : null;

        if (!is_array($kernel)) {
            return null;
        }

        return $kernel['package_prefixes'] ?? $kernel['packagePrefixes'] ?? null;
    }

    /** @return list<string> */
    private function normalizePackagePrefixes(mixed $packagePrefixes): array
    {
        if (is_string($packagePrefixes)) {
            $packagePrefixes = preg_split('/[,\s]+/', $packagePrefixes) ?: [];
        }

        if (!is_array($packagePrefixes)) {
            return [];
        }

        $normalized = [];

        foreach ($packagePrefixes as $prefix) {
            if (!is_scalar($prefix) && !$prefix instanceof \Stringable) {
                continue;
            }

            $prefix = trim((string) $prefix);

            if ($prefix === '') {
                continue;
            }

            $normalized[] = str_ends_with($prefix, '/') ? $prefix : "{$prefix}/";
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param list<string> $environments
     * @return list<string>
     */
    private function normalizeKnownEnvironments(array $environments): array
    {
        return array_values(
            array_unique(
                array_filter($environments, static fn (string $env): bool => $env !== ''),
            ),
        );
    }

    private function serverString(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;

        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function serverNullableDirectory(string $name): ?string
    {
        if ($this->serverValueIsFalse($name)) {
            return null;
        }

        return $this->serverString($name);
    }

    private function serverValueIsFalse(string $name): bool
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;

        if ($value === null) {
            return false;
        }

        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) === false;
    }

    private function environmentDirectory(string $directory): string
    {
        if ($directory !== '' && in_array($directory[0], ['/', '\\'], true)) {
            return sprintf('%s/%s', rtrim($directory, '/'), $this->environment);
        }

        if (
            DIRECTORY_SEPARATOR === '\\'
            && isset($directory[1])
            && $directory[1] === ':'
            && preg_match('/^[A-Za-z]:/', $directory) === 1
        ) {
            return sprintf('%s/%s', rtrim($directory, '/'), $this->environment);
        }

        return sprintf('%s/%s/%s', $this->projectDir, trim($directory, '/'), $this->environment);
    }

    /** @return array<int, string> */
    private function patterns(string $configDir): array
    {
        $env = $this->environment;
        $extensions = '{php,yaml,yml,ini}';

        return [
            sprintf('%s/packages/*.%s', $configDir, $extensions),
            sprintf('%s/packages/%s/*.%s', $configDir, $env, $extensions),
            sprintf('%s/services.%s', $configDir, $extensions),
            sprintf('%s/services_%s.%s', $configDir, $env, $extensions),
            sprintf('%s/wordpress.%s', $configDir, $extensions),
            sprintf('%s/wordpress_%s.%s', $configDir, $env, $extensions),
        ];
    }
}
