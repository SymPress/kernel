<?php

declare(strict_types=1);

namespace SymPress\Kernel\Discovery;

use function opcache_invalidate as native_opcache_invalidate;

function opcache_invalidate(string $filename, bool $force = false): bool
{
    $GLOBALS['cache_request_invalidations'] = ($GLOBALS['cache_request_invalidations'] ?? 0) + 1;

    return native_opcache_invalidate($filename, $force);
}
