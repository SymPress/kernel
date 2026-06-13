<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use Symfony\Component\DependencyInjection\Attribute\AutowireCallable;
use Symfony\Component\DependencyInjection\Attribute\AutowireInline;
use Symfony\Component\DependencyInjection\Attribute\AutowireMethodOf;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

final readonly class CallableSummary
{
    public function __construct(
        #[AutowireCallable(service: CallableTarget::class, method: 'format')]
        private \Closure $format,
        #[AutowireMethodOf(CallableTarget::class)]
        private \Closure $describe,
        #[AutowireServiceClosure(CallableTarget::class)]
        private \Closure $targetFactory,
        #[AutowireInline(InlineDependency::class, ['inline'])]
        private InlineDependency $inline,
    ) {
    }

    /** @return array<string, string> */
    public function values(): array
    {
        $target = ($this->targetFactory)();

        return [
            'callable' => ($this->format)('value'),
            'method'   => ($this->describe)(),
            'closure'  => $target->describe(),
            'inline'   => $this->inline->value(),
        ];
    }
}
