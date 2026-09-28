<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Helper\Console;

use NeoPHP\Package\Translation\Contract\AbstractTranslator;
use NeoPHP\Package\Translation\Contract\TranslatorInterface;
use NeoPHP\Package\Translation\Dumper\XliffDumper;
use NeoPHP\Package\Translation\Dumper\YamlDumper;
use NeoPHP\Package\Translation\Exception\TranslationException;
use NeoPHP\Package\Translation\Extractor\TranslationExtractor;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

#[AsCommand(name: 'translation:generate', description: 'Extracts the translation keys of the templates and the code and adds the missing ones to the translation files')]
class TranslationGenerateCommand extends AbstractConsole
{
    public function __construct(protected TranslatorInterface $translator)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('locale', 'l', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The locale(s) to update (default: every enabled locale)');
        $input->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Only update this domain');
        $input->addOption('format', null, InputOption::VALUE_REQUIRED, 'The format of the new files: yaml or xliff (default: packages.translation.format)');
        $input->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes without writing any file');
        $input->addOption('clean', null, InputOption::VALUE_NONE, 'Remove the keys which are no longer used in the code');
        $this->setHelp(implode("\n", [
            'Scans packages.translation.extract.paths (templates/ and src/ by default) for translate(\'key\'), \'key\'|trans,',
            '$this->translate(\'key\') and ->translate(\'key\'), with an optional literal domain argument.',
            'The missing keys are added to translations/{domain}.{locale}.{yaml|xlf}: the value is the key for the default locale, "" otherwise.',
            'Existing translations are kept; an existing file keeps its format. --clean removes the unused keys of the extracted domains.',
        ]));
        $this->addExample('translation:generate');
        $this->addExample('translation:generate --locale=fr --locale=de --format=xliff');
        $this->addExample('translation:generate --domain=admin --dry-run');
        $this->addExample('translation:generate --clean');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->translator->getConfig();
        $format = strtolower((string) ($input->getOption('format') ?? $config['format']));
        $format = AbstractTranslator::FORMATS[$format] ?? null;

        if ($format === null) {
            $output->error('The format must be "yaml" or "xliff".');

            return self::FAILURE;
        }

        $locales = [];

        foreach ((array) ($input->getOption('locale') ?: $this->translator->getLocales()) as $locale) {
            $normalized = AbstractTranslator::normalizeLocale((string) $locale);

            if ($normalized === null) {
                $output->error(sprintf('The locale "%s" is invalid.', (string) $locale));

                return self::FAILURE;
            }

            $locales[$normalized] = true;
        }

        $extractor = new TranslationExtractor($this->translator->getDefaultDomain());
        $extracted = $extractor->extract((array) ($config['extract']['paths'] ?? []));
        $domain = $input->getOption('domain');

        if (is_string($domain) && $domain !== '') {
            $extracted = [$domain => $extracted[$domain] ?? []];
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $clean = (bool) $input->getOption('clean');
        $rows = [];
        $details = [];
        $changed = 0;

        try {
            foreach (array_keys($locales) as $locale) {
                foreach ($extracted as $name => $keys) {
                    [$file, $fileFormat, $exists] = $this->file((string) $name, (string) $locale, $format);
                    $messages = $exists ? $this->translator->loadFile($file) : [];
                    $added = array_values(array_diff(array_map('strval', array_keys($keys)), array_map('strval', array_keys($messages))));
                    $removed = $clean && $keys !== [] ? array_values(array_diff(array_map('strval', array_keys($messages)), array_map('strval', array_keys($keys)))) : [];

                    foreach ($added as $key) {
                        $messages[$key] = $locale === $this->translator->getDefaultLocale() ? $key : '';
                    }

                    foreach ($removed as $key) {
                        unset($messages[$key]);
                    }

                    $status = $added === [] && $removed === [] ? 'unchanged' : ($exists ? 'updated' : 'created');

                    if ($status !== 'unchanged') {
                        $changed++;

                        if (!$dryRun) {
                            $this->write($file, $fileFormat, $messages, (string) $locale, (string) $name);
                        }
                    }

                    $rows[] = [$this->relative($file), $locale, $name, '+' . count($added), '-' . count($removed), (string) count($messages), $dryRun && $status !== 'unchanged' ? $status . ' (dry-run)' : $status];

                    foreach ($added as $key) {
                        $details[] = sprintf('  <success>+</success> %s [%s] %s', $this->relative($file), $locale, $key);
                    }

                    foreach ($removed as $key) {
                        $details[] = sprintf('  <error>-</error> %s [%s] %s', $this->relative($file), $locale, $key);
                    }
                }
            }
        } catch (TranslationException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $output->note('No translation key found in ' . implode(', ', array_map(fn (string $path): string => $this->relative($path), (array) ($config['extract']['paths'] ?? []))) . '.');

            return self::SUCCESS;
        }

        $output->table(['File', 'Locale', 'Domain', 'Added', 'Removed', 'Total', 'Status'], $rows);

        if ($details !== [] && ($dryRun || $output->isVerbose())) {
            foreach ($details as $line) {
                $output->writeln($line);
            }

            $output->newLine();
        }

        if (!$dryRun && $changed > 0) {
            $this->translator->clearCache();
        }

        $output->success($dryRun
            ? sprintf('Dry run: %d file(s) would be written.', $changed)
            : sprintf('%d translation file(s) written.', $changed));

        return self::SUCCESS;
    }

    protected function file(string $domain, string $locale, string $format): array
    {
        $path = $this->translator->getPath();

        foreach ($this->translator->getResources($locale) as $resource) {
            if ($resource['domain'] === $domain && dirname($resource['file']) === $path) {
                return [$resource['file'], $resource['format'], true];
            }
        }

        return [$path . '/' . $domain . '.' . $locale . '.' . ($format === 'xliff' ? 'xlf' : 'yaml'), $format, false];
    }

    protected function write(string $file, string $format, array $messages, string $locale, string $domain): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new TranslationException('Unable to create the directory "{directory}".', 0, null, ['directory' => $directory]);
        }

        $dumper = $format === 'xliff' ? new XliffDumper() : new YamlDumper();

        if (file_put_contents($file, $dumper->dump($messages, $locale, $domain, $this->translator->getDefaultLocale())) === false) {
            throw new TranslationException('Unable to write the translation file "{file}".', 0, null, ['file' => $file]);
        }
    }

    protected function relative(string $path): string
    {
        $root = dirname($this->translator->getPath());

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }
}