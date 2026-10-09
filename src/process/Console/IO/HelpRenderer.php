<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\IO;

use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\CommandInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;

class HelpRenderer
{
    public const BINARY = 'php bin/neo';

    public function render(CommandInterface $command, InputDefinition $definition, OutputInterface $output): void
    {
        if ($command->getDescription() !== '') {
            $output->writeln('<comment>Description:</comment>');
            $output->writeln('  ' . $command->getDescription());
            $output->newLine();
        }

        $output->writeln('<comment>Usage:</comment>');

        foreach ([$command->getName(), ...$command->getAliases()] as $name) {
            $output->writeln('  ' . Formatter::escape(trim($name . ' ' . $definition->getSynopsis())));
        }

        $output->newLine();

        $arguments = [];

        foreach ($definition->getArguments() as $argument) {
            $arguments[$argument->getName()] = $argument->getDescription() . $this->defaultOf($argument->isRequired() ? null : $argument->getDefault());
        }

        $options = [];
        $globals = [];

        foreach ($definition->getOptions() as $name => $option) {
            $description = $option->getDescription() . ($option->isArray() ? ' (multiple values allowed)' : '') . $this->defaultOf($option->acceptValue() ? $option->getDefault() : null);

            if ($definition->isGlobal($name)) {
                $globals[$this->optionLabel($option)] = $description;
            } else {
                $options[$this->optionLabel($option)] = $description;
            }
        }

        $width = max(array_map(static fn (string $label): int => Formatter::width($label), [...array_keys($arguments), ...array_keys($options), ...array_keys($globals)]) ?: [0]);

        foreach (['Arguments' => $arguments, 'Options' => $options, 'Global options' => $globals] as $title => $rows) {
            if ($rows === []) {
                continue;
            }

            $output->writeln('<comment>' . $title . ':</comment>');

            foreach ($rows as $label => $description) {
                $output->writeln('  <info>' . Formatter::escape((string) $label) . '</info>' . str_repeat(' ', $width - Formatter::width((string) $label)) . '  ' . $description);
            }

            $output->newLine();
        }

        $help = $command->getHelp();

        if ($help !== null && $help !== '') {
            $output->writeln('<comment>Help:</comment>');

            foreach (explode("\n", $help) as $line) {
                $output->writeln('  ' . $line);
            }

            $output->newLine();
        }

        if ($command->getExamples() !== []) {
            $output->writeln('<comment>Examples:</comment>');

            foreach ($command->getExamples() as $example) {
                $output->writeln('  ' . (str_starts_with($example, 'php ') ? '' : self::BINARY . ' ') . Formatter::escape($example));
            }

            $output->newLine();
        }
    }

    public function renderList(array $commands, OutputInterface $output, string $version = '', ?string $namespace = null): void
    {
        $output->writeln('<title>NeoPHP</title>' . ($version !== '' ? ' <info>' . $version . '</info>' : ''));
        $output->newLine();
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  ' . self::BINARY . ' ' . Formatter::escape('<command> [arguments] [options]'));
        $output->writeln('  ' . self::BINARY . ' ' . Formatter::escape('<command> --help'));
        $output->newLine();
        $output->writeln('<comment>Global options:</comment>');
        $globals = [];

        foreach (AbstractConsole::getGlobalOptions() as $option) {
            $globals[$this->optionLabel($option)] = $option->getDescription();
        }

        $width = max(array_map(static fn (string $label): int => Formatter::width($label), array_keys($globals)));

        foreach ($globals as $label => $description) {
            $output->writeln('  <info>' . $label . '</info>' . str_repeat(' ', $width - Formatter::width($label)) . '  ' . $description);
        }

        $output->newLine();

        $groups = [];

        foreach ($commands as $name => $command) {
            if (!empty($command['hidden'])) {
                continue;
            }

            $group = str_contains($name, ':') ? substr($name, 0, (int) strpos($name, ':')) : '';

            if ($namespace !== null && $group !== $namespace) {
                continue;
            }

            $groups[$group][$name] = $command['description'] . ($command['aliases'] !== [] ? ' <muted>(' . implode(', ', $command['aliases']) . ')</muted>' : '');
        }

        ksort($groups);
        $names = array_merge(...array_map('array_keys', array_values($groups)) ?: [[]]);
        $width = max(array_map(static fn (string $name): int => Formatter::width($name), $names) ?: [0]);
        $output->writeln($namespace !== null ? '<comment>Available commands for the "' . $namespace . '" namespace:</comment>' : '<comment>Available commands:</comment>');

        foreach ($groups as $group => $rows) {
            ksort($rows);

            if ($group !== '') {
                $output->writeln(' <comment>' . $group . '</comment>');
            }

            foreach ($rows as $name => $description) {
                $output->writeln('  <info>' . $name . '</info>' . str_repeat(' ', $width - Formatter::width($name)) . '  ' . $description);
            }
        }
    }

    public function optionLabel(InputOption $option): string
    {
        $shortcut = $option->getShortcut();

        if ($option->getName() === 'verbose') {
            return '-v|vv|vvv, --verbose';
        }

        $label = ($shortcut !== null ? '-' . $shortcut . ', ' : '    ') . '--' . $option->getName();

        if ($option->acceptValue()) {
            $value = strtoupper(str_replace('-', '_', $option->getName()));
            $label .= $option->isValueRequired() ? '=' . $value : '[=' . $value . ']';
        }

        return $label;
    }

    protected function defaultOf(mixed $default): string
    {
        if ($default === null || $default === false || $default === []) {
            return '';
        }

        return ' <comment>[default: ' . Formatter::escape(is_scalar($default) ? var_export($default, true) : (string) json_encode($default, JSON_UNESCAPED_SLASHES)) . ']</comment>';
    }
}