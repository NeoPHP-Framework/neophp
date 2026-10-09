<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Helper\Console;

use NeoPHP\Package\Translation\Exception\TranslationException;
use NeoPHP\Package\Translation\TranslationManager;
use NeoPHP\Package\Translation\TranslationManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;

/**
 * @internal
 */
#[AsCommand(name: 'translation:lint', description: 'Checks that every translation file can be parsed and that every message has a valid syntax')]
class TranslationLintCommand extends AbstractConsole
{
    public function __construct(protected TranslationManagerInterface $translator)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $this->setHelp(implode("\n", [
            'Parses the application translation files (packages.translation.path) and the files added by addResource().',
            'Each message is checked with the ICU-lite parser (plural, select, number arguments).',
            'Files of the translation directory which do not follow {domain}.{locale}.{yaml|yml|xlf|xliff} are reported as warnings.',
            'The command exits with 1 when an error is found.',
        ]));
        $this->addExample('translation:lint');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $rows = [];
        $errors = 0;
        $warnings = [];
        $files = 0;

        foreach ($this->translator->getResources() as $resource) {
            $files++;

            try {
                $messages = $this->translator->loadFile($resource['file']);
            } catch (TranslationException $exception) {
                $errors++;
                $rows[] = [$this->relative($resource['file']), '', '<error>' . $exception->getMessage() . '</error>'];
                continue;
            }

            foreach ($messages as $key => $message) {
                $error = $this->translator->getFormatter()->validate((string) $message);

                if ($error !== null) {
                    $errors++;
                    $rows[] = [$this->relative($resource['file']), (string) $key, '<error>' . $error . '</error>'];
                }
            }
        }

        $path = $this->translator->getPath();

        foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $name) {
            if ($name[0] !== '.' && is_file($path . '/' . $name) && TranslationManager::describe($path . '/' . $name) === null) {
                $warnings[] = sprintf('The file %s is ignored: name it {domain}.{locale}.{yaml|yml|xlf|xliff}.', $this->relative($path . '/' . $name));
            }
        }

        if ($warnings !== []) {
            $output->warning($warnings);
        }

        if ($errors > 0) {
            $output->table(['File', 'Key', 'Error'], $rows);
            $output->error(sprintf('%d error(s) found in %d translation file(s).', $errors, $files));

            return self::FAILURE;
        }

        $output->success(sprintf('%d translation file(s) checked: no error.', $files));

        return self::SUCCESS;
    }

    protected function relative(string $path): string
    {
        $root = dirname($this->translator->getPath());

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }
}