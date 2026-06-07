<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Message;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract\StatusProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\WhenNot;

#[WhenNot(env: 'development')]
final class NotDevelopmentStatusProvider implements StatusProviderInterface
{
    public function label(): string
    {
        return 'not-development';
    }
}
