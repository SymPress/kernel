<?php

declare(strict_types=1);

namespace SymPress\Kernel\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

final readonly class WpCliConsoleBridge
{
    public function __construct(
        private Application $application,
    ) {
    }

    public function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command(
            'console',
            $this,
            [
                'shortdesc' => 'Run Symfony Console commands registered in the kernel.',
                'synopsis' => '<command> [<arguments>...] [--<field>=<value>]',
            ],
        );
    }

    /**
     * Run a Symfony Console command registered in the kernel.
     *
     * ## OPTIONS
     *
     * <command>
     * : Symfony command name, for example migration:status.
     *
     * [<arguments>...]
     * : Arguments passed through to the Symfony command.
     *
     * [--<field>=<value>]
     * : Options passed through to the Symfony command.
     *
     * @param list<string> $args
     * @param array<string, scalar|array<scalar|null>|null> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs = []): void
    {
        if ($args === []) {
            \WP_CLI::error('Please provide a Symfony command name. Example: wp console migration:status');
        }

        $status = $this->application->run(
            new ArgvInput($this->argv($args, $assocArgs)),
            new ConsoleOutput(),
        );

        if ($status !== 0) {
            \WP_CLI::halt($status);
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, scalar|array<scalar|null>|null> $assocArgs
     * @return list<string>
     */
    private function argv(array $args, array $assocArgs): array
    {
        $argv = ['wp console'];

        foreach ($args as $arg) {
            $argv[] = $arg;
        }

        foreach ($assocArgs as $name => $value) {
            foreach ($this->optionTokens((string) $name, $value) as $token) {
                $argv[] = $token;
            }
        }

        return $argv;
    }

    /**
     * @param scalar|array<scalar|null>|null $value
     * @return list<string>
     */
    private function optionTokens(string $name, mixed $value): array
    {
        if (is_array($value)) {
            $tokens = [];

            foreach ($value as $item) {
                $tokens = [...$tokens, ...$this->optionTokens($name, $item)];
            }

            return $tokens;
        }

        if ($value === false) {
            return [sprintf('--no-%s', $name)];
        }

        if ($value === true || $value === null) {
            return [sprintf('--%s', $name)];
        }

        return [sprintf('--%s=%s', $name, (string) $value)];
    }
}
