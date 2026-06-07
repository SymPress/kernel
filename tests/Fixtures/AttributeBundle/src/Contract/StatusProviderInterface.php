<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('kernel_fixture.status_provider')]
interface StatusProviderInterface
{
    public function label(): string;
}
