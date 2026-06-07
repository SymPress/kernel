<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Formatter;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\FormatterInterface;

final class JsonFormatter implements FormatterInterface
{
    public function format(string $value): string
    {
        return json_encode(['value' => $value], JSON_THROW_ON_ERROR);
    }
}
