<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Helper\Console;

use NeoPHP\Component\Container\ContainerManagerInterface;
use NeoPHP\Component\Kernel\Module\InstalledPackages;
use NeoPHP\Package\Translation\Dumper\XliffDumper;
use NeoPHP\Package\Translation\Dumper\YamlDumper;
use NeoPHP\Package\Translation\Exception\TranslationException;
use NeoPHP\Package\Translation\Extractor\TranslationExtractor;
use NeoPHP\Package\Translation\TranslationManager;
use NeoPHP\Package\Translation\TranslationManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'translation:generate', description: 'Extracts the translation keys of the templates and the code and adds the missing ones to the translation files')]
class TranslationGenerateCommand extends AbstractConsole
{
    protected ?string $directory = null;

    public function __construct(protected TranslationManagerInterface $translator, protected ?ContainerManagerInterface $container = null)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('locale', 'l', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'The locale(s) to update (default: every enabled locale)');
        $input->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Only update this domain');
        $input->addOption('format', null, InputOption::VALUE_REQUIRED, 'The format of the new files: yaml or xliff (default: packages.translation.format)');
        $input->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the changes without writing any file');
        $input->addOption('clean', null, InputOption::VALUE_NONE, 'Remove the keys which are no longer used in the code');
        $input->addOption('package', 'p', InputOption::VALUE_REQUIRED, 'Extract the keys of a NeoPHP package (templates/ and src/ of the package) into its translations/ directory');
        $this->setHelp(implode("\n", [
            'Scans packages.translation.extract.paths (templates/ and src/ by default) for translate(\'key\'), \'key\'|trans,',
            '$this->translate(\'key\') and ->translate(\'key\'), with an optional literal domain argument.',
            'The missing keys are added to translations/{domain}.{locale}.{yaml|xlf}: the value is the key for the default locale, "" otherwise.',
            'Existing translations are kept; an existing file keeps its format. --clean removes the unused keys of the extracted domains.',
            'With --package, the templates/ and src/ of the NeoPHP package are scanned and its translations/ directory is updated.',
        ]));
        $this->addExample('translation:generate');
        $this->addExample('translation:generate --locale=fr --locale=de --format=xliff');
        $this->addExample('translation:generate --domain=admin --dry-run');
        $this->addExample('translation:generate --clean');
        $this->addExample('translation:generate --package=billing');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->translator->getConfig();
        $format = strtolower((string) ($input->getOption('format') ?? $config['format']));
        $format = TranslationManager::FORMATS[$format] ?? null;

        if ($format === null) {
            $output->error('The format must be "yaml" or "xliff".');

            return self::FAILURE;
        }

        $locales = [];

        foreach ((array) ($input->getOption('locale') ?: $this->translator->getLocales()) as $locale) {
            $normalized = TranslationManager::normalizeLocale((string) $locale);

            if ($normalized === null) {
                $output->error(sprintf('The locale "%s" is invalid.', (string) $locale));

                return self::FAILURE;
            }

            $locales[$normalized] = true;
        }

        $paths = (array) ($config['extract']['paths'] ?? []);
        $this->directory = null;
        $package = $input->getOption('package');

        if (is_string($package) && $package !== '') {
            $installed = $this->container !== null && $this->container->has('kernel.root_path') ? InstalledPackages::find((string) $this->container->get('kernel.root_path'), $package) : null;

            if ($installed === null || $installed['path'] === '') {
                $output->error(sprintf('The NeoPHP package "%s" is not installed.', $package));

                return self::FAILURE;
            }

            $paths = array_values(array_filter([$installed['path'] . '/templates', $installed['path'] . '/src'], 'is_dir'));
            $this->directory = str_replace('\\', '/', $installed['translations'] ?? $installed['path'] . '/translations');
        }

        $extractor = new TranslationExtractor($this->translator->getDefaultDomain());
        $extracted = $extractor->extract($paths);
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
            $output->note('No translation key found in ' . implode(', ', array_map(fn (string $path): string => $this->relative((string) $path), $paths)) . '.');

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
        if ($this->directory !== null) {
            foreach (['yaml' => 'yaml', 'yml' => 'yaml', 'xlf' => 'xliff', 'xliff' => 'xliff'] as $extension => $fileFormat) {
                $file = $this->directory . '/' . $domain . '.' . $locale . '.' . $extension;

                if (is_file($file)) {
                    return [$file, $fileFormat, true];
                }
            }

            return [$this->directory . '/' . $domain . '.' . $locale . '.' . ($format === 'xliff' ? 'xlf' : 'yaml'), $format, false];
        }

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