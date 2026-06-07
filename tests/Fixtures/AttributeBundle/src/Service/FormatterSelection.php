<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\FormatterInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

final class FormatterSelection
{
    public function __construct(
        #[Target('adminFormatter')]
        private readonly FormatterInterface $adminFormatter,
        #[Target('reportFormatter')]
        private readonly FormatterInterface $reportFormatter,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function formats(): array
    {
        return [
            'admin' => $this->adminFormatter->format('kernel'),
            'report' => $this->reportFormatter->format('kernel'),
        ];
    }
}
