<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class ExcludedService
{
}
