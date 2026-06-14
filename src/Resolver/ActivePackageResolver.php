<?php

declare(strict_types=1);

namespace SymPress\Kernel\Resolver;

final class ActivePackageResolver
{
    /** @var array<string, bool> */
    private array $active = [];

    /** @var list<string>|null */
    private ?array $activePluginEntries = null;

    public function isActive(string $type, string $entry, string $installPath): bool
    {
        $key = implode("\0", [$type, $entry, $installPath]);

        if (array_key_exists($key, $this->active)) {
            return $this->active[$key];
        }

        $this->active[$key] = match ($type) {
            'wordpress-muplugin' => $this->isMuPluginActive($entry, $installPath),
            'wordpress-plugin' => $this->isPluginActive($entry, $installPath),
            'wordpress-theme' => $this->isThemeActive($entry, $installPath),
            default => false,
        };

        return $this->active[$key];
    }

    private function isMuPluginActive(string $entry, string $installPath): bool
    {
        $muPluginDir = $this->muPluginDir();

        if ($muPluginDir === null) {
            return false;
        }

        $entryFile = sprintf('%s/%s', rtrim($muPluginDir, '/'), ltrim($entry, '/'));

        if (is_file($entryFile)) {
            return true;
        }

        return $this->isSameEntryFile($muPluginDir, $entry, $installPath)
            || $this->pathStartsWith($installPath, $muPluginDir);
    }

    private function isPluginActive(string $entry, string $installPath): bool
    {
        if (!function_exists('get_option')) {
            return false;
        }

        $active = $this->activePluginEntries();

        if (in_array($entry, $active, true)) {
            return true;
        }

        $pluginDir = $this->pluginDir();

        if ($pluginDir === null) {
            return false;
        }

        foreach ($active as $activeEntry) {
            if ($activeEntry === '') {
                continue;
            }

            if ($this->isSameEntryFile($pluginDir, $activeEntry, $installPath, $entry)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function activePluginEntries(): array
    {
        if ($this->activePluginEntries !== null) {
            return $this->activePluginEntries;
        }

        $active = [];
        $activePlugins = get_option('active_plugins', []);

        if (is_array($activePlugins)) {
            $active = [...$active, ...$activePlugins];
        }

        if (
            function_exists('is_multisite')
            && is_multisite()
            && function_exists('get_site_option')
        ) {
            $network = get_site_option('active_sitewide_plugins', []);

            if (is_array($network)) {
                $active = [...$active, ...array_keys($network)];
            }
        }

        $this->activePluginEntries = array_values(
            array_unique(
                array_filter(
                    $active,
                    static fn (mixed $entry): bool => is_string($entry) && $entry !== '',
                ),
            ),
        );

        return $this->activePluginEntries;
    }

    private function isThemeActive(string $entry, string $installPath): bool
    {
        if (function_exists('get_stylesheet') && get_stylesheet() === $entry) {
            return true;
        }

        if (!function_exists('get_stylesheet_directory')) {
            return false;
        }

        return $this->samePath((string) get_stylesheet_directory(), $installPath);
    }

    private function contentDir(): ?string
    {
        if (defined('WP_CONTENT_DIR')) {
            return (string) WP_CONTENT_DIR;
        }

        if (defined('ABSPATH')) {
            return sprintf('%s/wp-content', dirname((string) ABSPATH));
        }

        return null;
    }

    private function pluginDir(): ?string
    {
        if (defined('WP_PLUGIN_DIR')) {
            return (string) WP_PLUGIN_DIR;
        }

        $contentDir = $this->contentDir();

        return $contentDir !== null ? sprintf('%s/plugins', rtrim($contentDir, '/')) : null;
    }

    private function muPluginDir(): ?string
    {
        if (defined('WPMU_PLUGIN_DIR')) {
            return (string) WPMU_PLUGIN_DIR;
        }

        $contentDir = $this->contentDir();

        return $contentDir !== null ? sprintf('%s/mu-plugins', rtrim($contentDir, '/')) : null;
    }

    private function isSameEntryFile(
        string $baseDir,
        string $activeEntry,
        string $installPath,
        ?string $metadataEntry = null,
    ): bool {

        $activeFile = sprintf('%s/%s', rtrim($baseDir, '/'), ltrim($activeEntry, '/'));
        $metadataEntry ??= $activeEntry;

        foreach ($this->installEntryCandidates($installPath, $activeEntry, $metadataEntry) as $candidate) {
            if ($this->samePath($activeFile, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function installEntryCandidates(string $installPath, string ...$entries): array
    {
        $candidates = [];

        foreach ($entries as $entry) {
            $relativePath = $this->entryPathWithinInstall($entry);

            if ($relativePath === '') {
                continue;
            }

            $candidates[] = sprintf('%s/%s', rtrim($installPath, '/'), $relativePath);
        }

        return array_values(array_unique($candidates));
    }

    private function entryPathWithinInstall(string $entry): string
    {
        $parts = array_values(
            array_filter(
                explode('/', str_replace('\\', '/', $entry)),
                static fn (string $part): bool => $part !== '',
            ),
        );

        if (count($parts) > 1) {
            array_shift($parts);
        }

        return implode('/', $parts);
    }

    private function pathStartsWith(string $path, string $directory): bool
    {
        $path = $this->normalizePath($path);
        $directory = rtrim($this->normalizePath($directory), '/') . '/';

        return str_starts_with(rtrim($path, '/') . '/', $directory);
    }

    private function samePath(string $left, string $right): bool
    {
        return $this->normalizePath($left) === $this->normalizePath($right);
    }

    private function normalizePath(string $path): string
    {
        $realPath = realpath($path);

        if (is_string($realPath)) {
            $path = $realPath;
        }

        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
