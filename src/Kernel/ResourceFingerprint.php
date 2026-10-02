<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

/** @internal */
final class ResourceFingerprint
{
    public static function contentHashes(): bool
    {
        $value = $_SERVER['SYMPRESS_KERNEL_CONTENT_HASHES']
            ?? $_ENV['SYMPRESS_KERNEL_CONTENT_HASHES']
            ?? getenv('SYMPRESS_KERNEL_CONTENT_HASHES');

        return filter_var($value, FILTER_VALIDATE_BOOL) === true;
    }

    public static function file(string $file): string
    {
        clearstatcache(true, $file);
        $stat = @stat($file);
        if ($stat === false || !is_file($file)) {
            return 'missing';
        }
        if (!self::contentHashes()) {
            return sprintf('stat:%d:%d', $stat['mtime'], $stat['size']);
        }
        $hash = hash_file('sha256', $file);

        return is_string($hash) ? 'sha256:' . $hash : 'unreadable';
    }
}
