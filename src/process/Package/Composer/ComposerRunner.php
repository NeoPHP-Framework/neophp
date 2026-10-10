<?php

declare(strict_types=1);

namespace NeoPHP\Process\Package\Composer;

use NeoPHP\Process\Package\Exception\PackageException;

/**
 * @internal
 */
class ComposerRunner
{
    public const BINARY_ENV = 'COMPOSER_BINARY';

    public function __construct(protected string $workingDirectory, protected ?string $binary = null)
    {
    }

    public function run(array $arguments): int
    {
        $this->assertAvailable();

        $descriptors = [
            0 => defined('STDIN') ? STDIN : ['pipe', 'r'],
            1 => defined('STDOUT') ? STDOUT : ['pipe', 'w'],
            2 => defined('STDERR') ? STDERR : ['pipe', 'w'],
        ];

        $process = proc_open($this->command($arguments), $descriptors, $pipes, $this->workingDirectory);

        if (!is_resource($process)) {
            throw new PackageException('Unable to run Composer: {command}.', 0, null, ['command' => $this->display($arguments)]);
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return proc_close($process);
    }

    public function capture(array $arguments): ?string
    {
        $this->assertAvailable();

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($this->command($arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes, $this->workingDirectory);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return proc_close($process) === 0 && $output !== false ? $output : null;
    }

    public function display(array $arguments): string
    {
        return implode(' ', array_map(static fn (string $part): string => preg_match('/^[\w.:\/^~@=<>*-]+$/', $part) === 1 ? $part : escapeshellarg($part), [...$this->binary(), ...array_map('strval', $arguments)]));
    }

    protected function command(array $arguments): array|string
    {
        $command = [...$this->binary(), ...array_map('strval', $arguments)];

        return PHP_OS_FAMILY === 'Windows' ? implode(' ', array_map('escapeshellarg', $command)) : $command;
    }

    protected function binary(): array
    {
        $binary = $this->binary ?? (getenv(self::BINARY_ENV) ?: null);

        if ($binary === null && is_file($this->workingDirectory . DIRECTORY_SEPARATOR . 'composer.phar')) {
            $binary = $this->workingDirectory . DIRECTORY_SEPARATOR . 'composer.phar';
        }

        $binary = (string) ($binary ?? 'composer');

        return str_ends_with(strtolower($binary), '.phar') ? [PHP_BINARY, $binary] : [$binary];
    }

    protected function assertAvailable(): void
    {
        if (!function_exists('proc_open')) {
            throw new PackageException('The proc_open() function is disabled: it is required to run Composer.');
        }
    }
}