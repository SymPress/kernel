<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureResourceTag;

#[AutoconfigureResourceTag('kernel_fixture.resource', ['kind' => 'fixture'])]
final class ResourceTaggedService
{
}
