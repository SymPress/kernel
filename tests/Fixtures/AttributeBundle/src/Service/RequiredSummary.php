<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Formatter\HtmlFormatter;
use Symfony\Contracts\Service\Attribute\Required;

final class RequiredSummary
{
    private ?HtmlFormatter $formatter = null;

    #[Required]
    public function setFormatter(HtmlFormatter $formatter): void
    {
        $this->formatter = $formatter;
    }

    public function isInjected(): bool
    {
        return $this->formatter instanceof HtmlFormatter;
    }
}
