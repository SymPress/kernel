<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Panel;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\PanelInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'primary', priority: 20)]
final class PrimaryPanel implements PanelInterface
{
    public function title(): string
    {
        return 'Primary';
    }
}
