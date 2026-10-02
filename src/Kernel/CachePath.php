<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use Symfony\Component\Filesystem\Path;

final class CachePath
{
    public static function resolve(string $project, string $environment, ?string $configured = null): string
    {
        $base = $configured === null ? $project . '/var/cache' : Path::makeAbsolute($configured, $project);
        $path = rtrim($base, '/') . '/' . $environment . '/kernel';
        $publicRoots = [$_SERVER['DOCUMENT_ROOT'] ?? null, defined('WP_CONTENT_DIR') ? constant('WP_CONTENT_DIR') : null];
        foreach ($publicRoots as $root) {
            if (!is_string($root) || $root === '') {
                continue;
            }
            $resolved = self::canonical($path);
            $public = rtrim(self::canonical($root), '/');
            if ($resolved === $public || str_starts_with($resolved, $public . '/')) {
                if ($configured !== null) {
                    throw new \RuntimeException('The kernel cache must be outside publicly served directories.');
                }
                return self::privateFallback($project, $environment);
            }
        }
        // Existing group-writable PHP dumps may already have been replaced. Never
        // adopt them by chmod; start a fresh generation in the private fallback.
        if (!is_link($path) && is_dir($path) && (fileperms($path) & 0022) !== 0) {
            return self::privateFallback($project, $environment);
        }
        if ($configured === null && !is_file($path . '/meta.php') && !self::writableAncestor($path)) {
            return self::privateFallback($project, $environment);
        }
        return $path;
    }

    public static function ensureDirectory(string $directory): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException('Refusing a symlinked kernel cache directory.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the private kernel cache directory.');
        }
        if ((fileperms($directory) & 0022) !== 0) {
            throw new \RuntimeException('Kernel cache directories cannot be writable by other users.');
        }
    }

    public static function canonical(string $path): string
    {
        $path = Path::canonicalize(Path::makeAbsolute($path, getcwd() ?: sys_get_temp_dir()));
        $ancestor = $path;
        $suffix = '';
        $real = realpath($ancestor);
        while ($real === false) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                return $path;
            }
            $suffix = '/' . basename($ancestor) . $suffix;
            $ancestor = $parent;
            $real = realpath($ancestor);
        }
        return rtrim($real, '/') . $suffix;
    }

    private static function privateFallback(string $project, string $environment): string
    {
        $user = function_exists('posix_geteuid') ? (string) posix_geteuid() : hash('sha256', get_current_user());
        $root = sys_get_temp_dir() . '/sympress-kernel-' . $user;
        self::ensureDirectory($root);
        if (function_exists('posix_geteuid') && fileowner($root) !== posix_geteuid()) {
            throw new \RuntimeException('The private temporary cache root belongs to another user.');
        }
        return $root . '/' . substr(hash('sha256', $project), 0, 24) . '/' . $environment . '/kernel';
    }

    private static function writableAncestor(string $path): bool
    {
        while (!file_exists($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                return false;
            }
            $path = $parent;
        }
        return is_dir($path) && is_writable($path);
    }
}
