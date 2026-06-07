<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Panel;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\PanelInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'secondary', priority: 5)]
final class SecondaryPanel implements PanelInterface
{
    public function title(): string
    {
        return 'Secondary';
    }
}
