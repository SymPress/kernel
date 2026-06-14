<?php

declare(strict_types=1);

namespace SymPress\Kernel\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;

final class EnvironmentParameterLoader
{
    private const array DEFAULT_ALLOWED_NAMES = [
        'APP_BUILD_DIR',
        'APP_CACHE_DIR',
        'APP_ENV',
        'APP_LOG_DIR',
        'APP_RUNTIME_ENV',
        'APP_RUNTIME_MODE',
        'APP_SHARE_DIR',
        'KERNEL_PACKAGE_PREFIXES',
        'SYMPRESS_KERNEL_BUILD_ID',
        'SYMPRESS_KERNEL_VALIDATE_SOURCE_RESOURCES',
        'WP_ENV',
        'WP_ENVIRONMENT_TYPE',
        'WORDPRESS_ENV',
    ];

    private const string SENSITIVE_NAME_PATTERN = '/(?:AUTH|CERT|CREDENTIAL|KEY|PASS|PASSWORD|PRIVATE|SALT|SECRET|TOKEN)/i';

    /**
     * @param array<string, mixed> $environment
     */
    public function load(ContainerBuilder $builder, array $environment, mixed $configuredNames = null): void
    {
        $allowedNames = $this->allowedNames($configuredNames);

        foreach ($environment as $name => $value) {
            $name = strtoupper((string) $name);

            if (!isset($allowedNames[$name]) || $this->isSensitiveName($name)) {
                continue;
            }

            if (!is_bool($value) && !is_numeric($value) && !is_string($value)) {
                continue;
            }

            $builder->setParameter(
                sprintf('env.%s', strtolower($name)),
                $this->sanitizeValue($value),
            );
        }
    }

    /** @return array<string, true> */
    private function allowedNames(mixed $configuredNames): array
    {
        $names = self::DEFAULT_ALLOWED_NAMES;

        if (is_string($configuredNames)) {
            $configuredNames = preg_split('/[,\s]+/', $configuredNames) ?: [];
        }

        if (is_array($configuredNames)) {
            foreach ($configuredNames as $name) {
                if (!is_scalar($name) && !$name instanceof \Stringable) {
                    continue;
                }

                $name = trim((string) $name);

                if ($name === '') {
                    continue;
                }

                $names[] = $name;
            }
        }

        $normalized = [];

        foreach ($names as $name) {
            $name = strtoupper(trim($name));

            if ($name === '') {
                continue;
            }

            $normalized[$name] = true;
        }

        return $normalized;
    }

    private function isSensitiveName(string $name): bool
    {
        return preg_match(self::SENSITIVE_NAME_PATTERN, $name) === 1;
    }

    private function sanitizeValue(bool|float|int|string $value): bool|float|int|string
    {
        if (!is_string($value)) {
            return $value;
        }

        return str_replace('%', '%%', $value);
    }
}
