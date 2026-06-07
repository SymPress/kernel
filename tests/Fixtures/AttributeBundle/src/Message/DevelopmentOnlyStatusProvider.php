<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Message;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\StatusProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

#[When(env: 'development')]
final class DevelopmentOnlyStatusProvider implements StatusProviderInterface
{
    public function label(): string
    {
        return 'development';
    }
}
