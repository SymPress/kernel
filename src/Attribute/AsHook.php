<?php

declare(strict_types=1);

namespace SymPress\Kernel\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class AsHook
{
    public function __construct(
        public readonly string $hook,
        public readonly string $method = '__invoke',
        public readonly string $type = 'action',
        public readonly int $priority = 10,
        public readonly ?int $acceptedArgs = null,
    ) {
    }

    /** @return array<string, int|string> */
    public function toTag(): array
    {
        $tag = [
            'hook'     => $this->hook,
            'method'   => $this->method,
            'type'     => $this->type,
            'priority' => $this->priority,
        ];

        if ($this->acceptedArgs === null) {
            return $tag;
        }

        $tag['accepted_args'] = $this->acceptedArgs;

        return $tag;
    }
}
