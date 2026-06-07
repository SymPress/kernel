<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Formatter\HtmlFormatter;
use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Formatter\JsonFormatter;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final class LocatorSummary
{
    public function __construct(
        #[AutowireLocator([
            'html' => HtmlFormatter::class,
            'json' => JsonFormatter::class,
        ])]
        private readonly ContainerInterface $formatters,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function formats(): array
    {
        return [
            'html' => $this->formatters->get('html')->format('kernel'),
            'json' => $this->formatters->get('json')->format('kernel'),
        ];
    }
}
