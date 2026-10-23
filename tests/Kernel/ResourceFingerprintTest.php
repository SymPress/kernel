<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use SymPress\Kernel\Kernel\ResourceFingerprint;

final class ResourceFingerprintTest extends TestCase
{
    public function testMissingFileIsReportedWithoutLeavingAPhpError(): void
    {
        error_clear_last();

        self::assertSame('missing', ResourceFingerprint::file(sys_get_temp_dir() . '/sympress-missing-' . bin2hex(random_bytes(8))));
        // A suppressed warning still lands in error_get_last(), which makes WordPress add the php-error admin class.
        self::assertNull(error_get_last());
    }

    public function testDirectoryIsMissingAndFileKeepsStatFormat(): void
    {
        self::assertSame('missing', ResourceFingerprint::file(sys_get_temp_dir()));

        $file = tempnam(sys_get_temp_dir(), 'sympress-res');
        self::assertIsString($file);
        try {
            file_put_contents($file, 'abc');
            self::assertSame(sprintf('stat:%d:3', filemtime($file)), ResourceFingerprint::file($file));
        } finally {
            unlink($file);
        }
    }
}
