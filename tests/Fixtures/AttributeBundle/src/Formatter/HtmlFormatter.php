<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Formatter;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\FormatterInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(FormatterInterface::class)]
final class HtmlFormatter implements FormatterInterface
{
    public function format(string $value): string
    {
        return sprintf('<strong>%s</strong>', $value);
    }
}
