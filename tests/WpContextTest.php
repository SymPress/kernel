<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests;

use SymPress\Kernel\WpContext;
use PHPUnit\Framework\TestCase;

final class WpContextTest extends TestCase
{
    public function testForceMarksCoreForNonCoreContexts(): void
    {
        $context = WpContext::new()->force(WpContext::REST);

        self::assertTrue($context->isRest());
        self::assertTrue($context->isCore());
        self::assertFalse($context->isBackoffice());
    }

    public function testWithCliKeepsCurrentContext(): void
    {
        $context = WpContext::new()
            ->force(WpContext::BACKOFFICE)
            ->withCli();

        self::assertTrue($context->isBackoffice());
        self::assertTrue($context->isWpCli());
        self::assertTrue($context->isCore());
    }
}
