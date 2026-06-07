<?php

declare(strict_types=1);

namespace SymPress\Kernel\Package;

final readonly class PackageMetadata
{
    public function __construct(
        private string $package,
        private string $type,
        private string $entry,
        private string $path,
        private string $composerFile,
        private string $bundleClass,
        private string $name,
        private string $description,
        private string $version,
        private bool $active,
    ) {
    }

    public function package(): string
    {
        return $this->package;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function entry(): string
    {
        return $this->entry;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function composerFile(): string
    {
        return $this->composerFile;
    }

    public function bundleClass(): string
    {
        return $this->bundleClass;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function isPlugin(): bool
    {
        return $this->type === 'wordpress-plugin';
    }

    public function isMustUsePlugin(): bool
    {
        return $this->type === 'wordpress-muplugin';
    }

    public function isTheme(): bool
    {
        return $this->type === 'wordpress-theme';
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'wordpress-muplugin' => 'Must-Use Plugin',
            'wordpress-plugin' => 'Plugin',
            'wordpress-theme' => 'Theme',
            default => $this->type,
        };
    }

    public function statusLabel(): string
    {
        return $this->active ? 'Active' : 'Inactive';
    }
}
