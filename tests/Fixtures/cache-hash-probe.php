<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use function hash_file as native_hash_file;
use function opcache_invalidate as native_opcache_invalidate;

function hash_file(string $algorithm, string $filename, bool $binary = false): string|false
{
    $GLOBALS['cache_request_hashes'][$filename] = ($GLOBALS['cache_request_hashes'][$filename] ?? 0) + 1;
    $GLOBALS['cache_request_bytes'] = ($GLOBALS['cache_request_bytes'] ?? 0) + filesize($filename);

    return native_hash_file($algorithm, $filename, $binary);
}

function opcache_invalidate(string $filename, bool $force = false): bool
{
    $GLOBALS['cache_request_invalidations'] = ($GLOBALS['cache_request_invalidations'] ?? 0) + 1;

    return native_opcache_invalidate($filename, $force);
}
