<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Message;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(tags: ['kernel_fixture.note_provider'])]
final class AutoconfiguredNoteProvider
{
    public function message(): string
    {
        return 'autoconfigure';
    }
}
