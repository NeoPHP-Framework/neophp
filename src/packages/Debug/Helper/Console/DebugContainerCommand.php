<?php

declare(strict_types=1);

namespace NeoPHP\Package\Debug\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Package\Debug\DebugManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\Formatter;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'debug:container', description: 'Lists the services of the container or shows one of them')]
class DebugContainerCommand extends AbstractConsole
{
    public function __construct(protected ContainerManagerInterface $container, protected DebugManagerInterface $debug)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('search', InputArgument::OPTIONAL, 'A service id to show, or a text to filter the list');
        $input->addOption('dump', 'd', InputOption::VALUE_NONE, 'Resolve the service and dump it (with an id)');
        $input->addOption('parameters', 'p', InputOption::VALUE_NONE, 'List the parameters instead of the services');
        $this->addExample('debug:container');
        $this->addExample('debug:container Router');
        $this->addExample('debug:container kernel.root_path');
        $this->addExample('debug:container "NeoPHP\\Component\\Routing\\RoutingManagerInterface" --dump');
        $this->addExample('debug:container --parameters');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $search = (string) ($input->getArgument('search') ?? '');
        $definitions = $this->container->getDefinitions();
        $aliases = $this->container->getAliases();

        if ($search !== '' && (isset($definitions[$search]) || isset($aliases[$search]))) {
            return $this->show($search, $definitions, $aliases, (bool) $input->getOption('dump'), $output);
        }

        $parameters = (bool) $input->getOption('parameters');
        $rows = [];

        foreach ($definitions as $id => $definition) {
            if (($definition['kind'] === 'parameter') !== $parameters) {
                continue;
            }

            $rows[] = [(string) $id, $definition['kind'], $parameters ? $this->scalar($this->container->get((string) $id)) : (string) ($definition['class'] ?? $definition['concrete']), $definition['resolved'] ? 'yes' : 'no'];
        }

        if (!$parameters) {
            foreach ($aliases as $alias => $target) {
                $rows[] = [(string) $alias, 'alias', '@' . $target, ''];
            }
        }

        if ($search !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => stripos($row[0] . ' ' . $row[2], $search) !== false));
        }

        if ($rows === []) {
            $output->note($search === '' ? 'No service found.' : 'No service matches "' . $search . '".');

            return self::SUCCESS;
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $output->table(['Id', 'Kind', $parameters ? 'Value' : 'Class', 'Resolved'], $rows);
        $output->text(sprintf('%d result(s). Show one with <info>php bin/neo debug:container \\<id> [--dump]</info>.', count($rows)));

        return self::SUCCESS;
    }

    protected function show(string $id, array $definitions, array $aliases, bool $dump, OutputInterface $output): int
    {
        $target = $id;
        $seen = [];

        while (isset($aliases[$target]) && !isset($seen[$target])) {
            $seen[$target] = true;
            $target = $aliases[$target];
        }

        $definition = $definitions[$target] ?? ['kind' => 'autowired', 'concrete' => $target, 'class' => null, 'resolved' => false];
        $class = $definition['class'] ?? ($definition['concrete'] !== 'closure' && class_exists((string) $definition['concrete']) ? (string) $definition['concrete'] : null);
        $rows = [
            ['Id', $id],
            ['Service', $target !== $id ? $target . ' (alias)' : $target],
            ['Kind', $definition['kind']],
            ['Definition', (string) $definition['concrete']],
            ['Class', (string) ($class ?? ($definition['concrete'] === 'closure' ? 'built by a closure (not resolved yet)' : '?'))],
            ['Resolved', $definition['resolved'] ? 'yes' : 'no'],
            ['Aliases', implode(', ', array_keys(array_filter($aliases, static fn (string $alias): bool => $alias === $target))) ?: '-'],
        ];

        if ($definition['kind'] === 'parameter') {
            $rows[] = ['Value', $this->scalar($this->container->get($target))];
        } elseif ($class !== null && class_exists($class)) {
            $rows[] = ['Arguments', $this->arguments($class)];
        }

        $output->table(['Property', 'Value'], $rows);

        if ($dump) {
            try {
                $output->write(Formatter::escape($this->debug->toText($this->container->get($target), $target, $output->isDecorated())));
            } catch (Throwable $exception) {
                $output->error(sprintf('Unable to resolve "%s": %s', $target, $exception->getMessage()));

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    protected function arguments(string $class): string
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return '-';
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $arguments[] = trim(($type instanceof ReflectionNamedType ? $type->getName() : (string) $type) . ' $' . $parameter->getName() . ($parameter->isDefaultValueAvailable() ? ' = ' . $this->scalar($parameter->getDefaultValue()) : ''));
        }

        return implode(', ', $arguments);
    }

    protected function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value) => strlen($json = (string) json_encode($value, JSON_UNESCAPED_SLASHES)) > 80 ? substr($json, 0, 77) . '...' : $json,
            default => get_debug_type($value),
        };
    }
}