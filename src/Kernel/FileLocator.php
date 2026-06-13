<?php

declare(strict_types=1);

namespace SymPress\Kernel\Kernel;

use Symfony\Component\Config\FileLocator as BaseFileLocator;

final class FileLocator extends BaseFileLocator
{
    public function __construct(
        private readonly KernelInterface $kernel,
        array|string|null $paths = [],
    ) {
        parent::__construct($paths ?? []);
    }

    public function locate(string $file, ?string $currentPath = null, bool $first = true): string|array
    {
        if (isset($file[0]) && $file[0] === '@') {
            $resource = $this->kernel->locateResource($file);

            return $first ? $resource : [$resource];
        }

        return parent::locate($file, $currentPath, $first);
    }
}
