<?php

declare(strict_types=1);

namespace SymPress\Kernel\Translation;

use SymPress\Kernel\Bundle\BundleInterface;

final class TranslationLoader
{
    /** @param array<string, string> $translationPaths */
    public function __construct(
        private array $translationPaths = [],
    ) {
        $this->translationPaths = $this->normalizePaths($translationPaths);
    }

    public function registerBundleTranslations(BundleInterface|string $bundle, ?string $path = null): void
    {
        if ($bundle instanceof BundleInterface) {
            $name = $bundle->id();
            $path ??= sprintf('%s/Resources/translations', $bundle->path());

            if ($name === '' || $path === null || $path === '' || !is_dir($path)) {
                return;
            }

            $this->translationPaths[$name] = $path;

            return;
        }

        $name = $bundle;

        if ($name === '' || $path === null || $path === '' || !is_dir($path)) {
            return;
        }

        $this->translationPaths[$name] = $path;
    }

    /** @return array<string, string> */
    public function getTranslationPaths(): array
    {
        return $this->translationPaths;
    }

    /** @return array<string, array<string, string>> */
    public function loadTranslations(string $locale): array
    {
        $translations = [];

        foreach ($this->translationPaths as $name => $path) {
            $loaded = [];

            foreach ($this->translationFiles($path, $locale) as $file) {
                $loaded = array_replace($loaded, $this->loadXliff($file));
            }

            if ($loaded === []) {
                continue;
            }

            $translations[$name] = $loaded;
        }

        return $translations;
    }

    /**
     * @param array<string, string> $paths
     * @return array<string, string>
     */
    private function normalizePaths(array $paths): array
    {
        $normalized = [];

        foreach ($paths as $name => $path) {
            if (!is_string($name) || $name === '' || !is_string($path) || $path === '' || !is_dir($path)) {
                continue;
            }

            $normalized[$name] = $path;
        }

        return $normalized;
    }

    /** @return list<string> */
    private function translationFiles(string $path, string $locale): array
    {
        $patterns = [
            sprintf('%s/*.%s.xlf', rtrim($path, '/'), $locale),
            sprintf('%s/*.%s.xliff', rtrim($path, '/'), $locale),
        ];
        $files = [];

        foreach ($patterns as $pattern) {
            $matches = glob($pattern) ?: [];
            sort($matches);
            $files = [...$files, ...$matches];
        }

        return array_values(array_unique($files));
    }

    /** @return array<string, string> */
    private function loadXliff(string $file): array
    {
        if (!class_exists(\DOMDocument::class) || !class_exists(\DOMXPath::class)) {
            throw new \RuntimeException('The DOM extension is required to load XLIFF translation files.');
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            if (!$document->load($file)) {
                throw new \RuntimeException(sprintf('Unable to load XLIFF file "%s".', $file));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[local-name() = "trans-unit" or local-name() = "unit"]');
        $translations = [];

        if (!$nodes instanceof \DOMNodeList) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $source = $this->firstText($xpath, $node, './/*[local-name() = "source"]');
            $target = $this->firstText($xpath, $node, './/*[local-name() = "target"]');
            $id = $node->getAttribute('id');
            $key = $id !== '' ? $id : $source;

            if ($key === '' || $target === '') {
                continue;
            }

            $translations[$key] = $target;
        }

        return $translations;
    }

    private function firstText(\DOMXPath $xpath, \DOMElement $node, string $query): string
    {
        $nodes = $xpath->query($query, $node);

        if (!$nodes instanceof \DOMNodeList || $nodes->length < 1) {
            return '';
        }

        $first = $nodes->item(0);

        return $first instanceof \DOMNode ? trim($first->textContent) : '';
    }
}
