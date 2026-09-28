<?php

declare(strict_types=1);

namespace NeoPHP\Process\Console\Maker;

use NeoPHP\Process\Console\Exception\ConsoleException;

class CommandMaker
{
    public function __construct(protected string $path, protected string $namespace = 'App\\Command')
    {
    }

    public function resolve(string $name): array
    {
        $name = trim(str_replace('/', '\\', $name), '\\');

        if (str_starts_with($name, trim($this->namespace, '\\') . '\\')) {
            $name = substr($name, strlen(trim($this->namespace, '\\')) + 1);
        }

        if (str_ends_with($name, 'Command')) {
            $name = substr($name, 0, -7);
        }

        if (preg_match('/^([A-Z][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new ConsoleException('The name "{name}" is not a valid class name: use StudlyCase (SendReport, Admin\CleanUsers).', 0, null, ['name' => $name]);
        }

        return [
            trim($this->namespace, '\\') . '\\' . $name . 'Command',
            rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $name . 'Command') . '.php',
            $name,
        ];
    }

    public function commandName(string $name): string
    {
        $short = substr($name, (int) strrpos('\\' . $name, '\\'));

        return 'app:' . strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $short));
    }

    public function make(string $name, ?string $commandName = null, bool $force = false): array
    {
        [$class, $file, $short] = $this->resolve($name);
        $commandName ??= $this->commandName($short);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]*$/', $commandName) !== 1) {
            throw new ConsoleException('The command name "{name}" is not valid: use letters, digits, ":", "-" and "_".', 0, null, ['name' => $commandName]);
        }

        if (is_file($file) && !$force) {
            throw new ConsoleException('The file "{file}" already exists: use --force to overwrite it.', 0, null, ['file' => $file]);
        }

        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $shortClass = substr($class, (int) strrpos($class, '\\') + 1);
        $code = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use NeoPHP\\Process\\Console\\Attribute\\AsCommand;
            use NeoPHP\\Process\\Console\\Contract\\AbstractConsole;
            use NeoPHP\\Process\\Console\\Contract\\InputInterface;
            use NeoPHP\\Process\\Console\\Contract\\OutputInterface;
            use NeoPHP\\Process\\Console\\IO\\InputArgument;
            use NeoPHP\\Process\\Console\\IO\\InputOption;

            #[AsCommand(name: '{$commandName}', description: 'Describe what {$commandName} does')]
            class {$shortClass} extends AbstractConsole
            {
                protected function configure(InputInterface \$input, OutputInterface \$output): void
                {
                    \$input->addArgument('name', InputArgument::OPTIONAL, 'A name', 'World');
                    \$input->addOption('shout', 's', InputOption::VALUE_NONE, 'Write the message in upper case');
                    \$this->addExample('{$commandName} Alice --shout');
                }

                protected function do(InputInterface \$input, OutputInterface \$output): int
                {
                    \$message = 'Hello ' . \$input->getArgument('name') . '!';

                    \$output->success(\$input->getOption('shout') ? strtoupper(\$message) : \$message);

                    return self::SUCCESS;
                }
            }

            PHP;

        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ConsoleException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        if (file_put_contents($file, $code) === false) {
            throw new ConsoleException('Unable to write the file "{file}".', 0, null, ['file' => $file]);
        }

        return [$class, $file, $commandName];
    }
}