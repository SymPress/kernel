<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!class_exists('WP_CLI')) {
    final class WP_CLI
    {
        /**
         * @param callable|object|string $callable
         * @param array<string, mixed> $args
         */
        public static function add_command(string $name, callable|object|string $callable, array $args = []): void
        {
        }

        public static function error(string $message): never
        {
            exit(1);
        }

        public static function halt(int $status): never
        {
            exit($status);
        }
    }
}
