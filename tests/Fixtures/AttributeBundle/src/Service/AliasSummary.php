<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\FormatterInterface;

final readonly class AliasSummary
{
    public function __construct(
        private FormatterInterface $formatter,
    ) {
    }

    public function value(): string
    {
        return $this->formatter->format('alias');
    }
}
