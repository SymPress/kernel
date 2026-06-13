<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ResourceTagSummary
{
    /**
     * @param array<string, list<array<string, mixed>>> $resourceTags
     */
    public function __construct(
        #[Autowire(param: 'kernel_fixture.resource_tags')]
        private readonly array $resourceTags,
    ) {
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function values(): array
    {
        return $this->resourceTags;
    }
}
