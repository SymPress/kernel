<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use Symfony\Component\Filesystem\Path;

final class CachePath
{
    private static ?int $owner = null;

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
        // PHP dumps that other users could have replaced are never adopted by chmod;
        // start a fresh generation in the private fallback.
        if (!self::isTrusted($path, $project)) {
            if ($configured !== null) {
                throw new \RuntimeException('APP_CACHE_DIR contains a kernel directory that other users can replace (a symlink, another owner, or a group/world-writable directory or parent). Configure a durable private directory with trusted parents, create it as the PHP-FPM user with mode 0700, and run cache warmup as that same user.');
            }
            return self::privateFallback($project, $environment);
        }
        if ($configured === null && !self::writableAncestor($path) && (glob($path . '/meta_*.json') ?: []) === []) {
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

    /**
     * Cached PHP is executed, so trust a cache directory only when no other user can
     * have planted or swapped it: a real directory owned by this process without
     * group/world write access, below ancestors other users cannot rename entries in
     * (sticky shared directories excepted). The walk stops at the project, whose
     * integrity the deployed code already depends on. A missing directory is judged
     * by its existing ancestors, so it is safe to create.
     */
    public static function isTrusted(string $directory, string $project): bool
    {
        if (
            (file_exists($directory) || is_link($directory))
            && (!is_dir($directory) || !self::ownedByProcess($directory) || (fileperms($directory) & 0022) !== 0)
        ) {
            return false;
        }
        $boundary = self::canonical($project);
        $path = self::canonical($directory);
        for ($parent = dirname($path); $parent !== $path && $parent !== $boundary; $parent = dirname($path)) {
            $path = $parent;
            if (file_exists($path) && self::replaceableByOthers(fileperms($path))) {
                return false;
            }
        }
        return true;
    }

    public static function ownedByProcess(string $path): bool
    {
        return !is_link($path) && file_exists($path) && fileowner($path) === self::currentOwner();
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
        $owner = self::currentOwner();
        $user = (string) $owner;
        $root = $project . '/var/cache-private-' . $user;
        if (self::isPublic($root) || !self::writableAncestor($root) || !self::isTrusted($root, $project)) {
            // A cold read-only project or classic WordPress docroot still boots.
            // A shared temporary parent is safe only with a private, owned root:
            // never follow or adopt a pre-created symlink or another user's tree.
            $temporary = self::canonical(sys_get_temp_dir());
            if (self::replaceableByOthers(fileperms($temporary))) {
                throw new \RuntimeException('A private kernel cache requires a trusted temporary parent or APP_CACHE_DIR; shared temporary directories must have the sticky bit.');
            }
            $root = self::temporaryRoot(rtrim($temporary, '/') . '/sympress-kernel-' . $user . '-'
                . substr(hash('sha256', self::canonical($project)), 0, 24), $owner);
        }
        if (self::isPublic($root)) {
            throw new \RuntimeException('No private kernel cache is available outside the webroot. Set APP_CACHE_DIR to a durable private directory and warm it as the PHP-FPM user.');
        }
        $created = !is_dir($root);
        self::ensureDirectory($root);
        if (fileowner($root) !== $owner) {
            throw new \RuntimeException('The private kernel cache root belongs to another user; configure APP_CACHE_DIR for the PHP-FPM identity.');
        }
        if ($created) {
            error_log('SymPress migrated its implicit kernel cache to ' . $root . '. Configure APP_CACHE_DIR for a shared deployment cache and warm it as the PHP-FPM user.');
        }
        return $root . '/' . $environment . '/kernel';
    }

    /**
     * The temporary root name is predictable, so another local user can create it first.
     * Never adopt such a path and never fail the boot over it: reuse or atomically create
     * a randomly named sibling. Only this user can own one, and the sticky temporary
     * parent keeps others from swapping it, so ownership is enough to find it again.
     */
    private static function temporaryRoot(string $root, int $owner): string
    {
        if ((!file_exists($root) && !is_link($root)) || self::privateDirectory($root, $owner)) {
            return $root;
        }
        foreach (glob($root . '.*', GLOB_NOESCAPE) ?: [] as $candidate) {
            if (self::privateDirectory($candidate, $owner)) {
                return $candidate;
            }
        }
        $candidate = $root . '.' . bin2hex(random_bytes(8));
        if (!mkdir($candidate, 0700)) {
            throw new \RuntimeException('Unable to create a private kernel cache next to ' . $root . '; configure APP_CACHE_DIR for the PHP-FPM identity.');
        }
        error_log('SymPress found ' . $root . ' not owned privately by the PHP user and uses ' . $candidate . ' as its kernel cache instead. Configure APP_CACHE_DIR for a durable private cache.');
        return $candidate;
    }

    private static function privateDirectory(string $path, int $owner): bool
    {
        return !is_link($path) && is_dir($path) && fileowner($path) === $owner && (fileperms($path) & 0022) === 0;
    }

    private static function isPublic(string $path): bool
    {
        $resolved = self::canonical($path);
        foreach ([$_SERVER['DOCUMENT_ROOT'] ?? null, defined('WP_CONTENT_DIR') ? constant('WP_CONTENT_DIR') : null] as $public) {
            if (is_string($public) && $public !== '' && ($resolved === self::canonical($public) || str_starts_with($resolved, rtrim(self::canonical($public), '/') . '/'))) {
                return true;
            }
        }
        return false;
    }

    private static function currentOwner(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }
        if (self::$owner !== null) {
            return self::$owner;
        }
        // get_current_user() identifies the script owner, not the process user.
        // The owner of a file we create remains reliable without ext-posix.
        $probe = tmpfile();
        if (!is_resource($probe)) {
            throw new \RuntimeException('Unable to verify private cache ownership; configure APP_CACHE_DIR.');
        }
        try {
            $status = fstat($probe);
            if ($status === false) {
                throw new \RuntimeException('Unable to verify private cache ownership; configure APP_CACHE_DIR.');
            }
            self::$owner = $status['uid'];
            return self::$owner;
        } finally {
            fclose($probe);
        }
    }

    private static function replaceableByOthers(int|false $permissions): bool
    {
        return $permissions === false || (($permissions & 0022) !== 0 && ($permissions & 01000) === 0);
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
