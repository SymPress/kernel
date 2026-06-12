<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\PanelInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PanelSummary
{
    public function __construct(
        #[AutowireIterator('kernel_fixture.panel', indexAttribute: 'index')]
        private readonly iterable $panels,
    ) {
    }

    /** @return array<string, string> */
    public function titles(): array
    {
        $titles = [];

        foreach ($this->panels as $key => $panel) {
            if (!$panel instanceof PanelInterface) {
                continue;
            }

            $titles[(string) $key] = $panel->title();
        }

        return $titles;
    }
}
