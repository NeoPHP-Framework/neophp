<?php

declare(strict_types=1);

namespace NeoPHP\Package\Translation\Helper\Console;

use NeoPHP\Package\Translation\Exception\TranslationException;
use NeoPHP\Package\Translation\Extractor\TranslationExtractor;
use NeoPHP\Package\Translation\TranslationManager;
use NeoPHP\Package\Translation\TranslationManagerInterface;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputArgument;
use NeoPHP\Process\Console\IO\InputOption;

/**
 * @internal
 */
#[AsCommand(name: 'translation:debug', description: 'Lists the translation keys with their state (translated, missing, fallback, unused) per locale')]
class TranslationDebugCommand extends AbstractConsole
{
    public const TRANSLATED = 'translated';

    public const MISSING = 'missing';

    public const FALLBACK = 'fallback';

    public const UNUSED = 'unused';

    public const FRAMEWORK_DOMAINS = ['validators', 'security'];

    public function __construct(protected TranslationManagerInterface $translator)
    {
    }

    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addArgument('locale', InputArgument::OPTIONAL, 'The locale to inspect (default: every enabled locale)');
        $input->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Only show this domain');
        $input->addOption('only-missing', null, InputOption::VALUE_NONE, 'Only show the missing keys (and the keys translated by a fallback locale)');
        $input->addOption('only-unused', null, InputOption::VALUE_NONE, 'Only show the keys of the translation files not used in the code');
        $this->setHelp(implode("\n", [
            'The keys are those used in packages.translation.extract.paths and those of the application translation files.',
            'translated: the locale translates the key; fallback: a fallback locale translates it; missing: nobody translates it;',
            'unused: the key is in a translation file but not found in the code (it can still be used dynamically).',
            'The keys of the validators and security domains are used by the framework: they are never unused.',
        ]));
        $this->addExample('translation:debug');
        $this->addExample('translation:debug fr --only-missing');
        $this->addExample('translation:debug en --domain=validators');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $argument = $input->getArgument('locale');
        $locales = $this->translator->getLocales();

        if (is_string($argument) && $argument !== '') {
            $locale = TranslationManager::normalizeLocale($argument);

            if ($locale === null) {
                $output->error(sprintf('The locale "%s" is invalid.', $argument));

                return self::FAILURE;
            }

            $locales = [$locale];
        }

        $domain = $input->getOption('domain');
        $domain = is_string($domain) && $domain !== '' ? $domain : null;
        $onlyMissing = (bool) $input->getOption('only-missing');
        $onlyUnused = (bool) $input->getOption('only-unused');
        $config = $this->translator->getConfig();
        $used = (new TranslationExtractor($this->translator->getDefaultDomain()))->extract((array) ($config['extract']['paths'] ?? []));

        try {
            $defined = $this->defined();
        } catch (TranslationException $exception) {
            $output->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = [];
        $counts = [self::TRANSLATED => 0, self::FALLBACK => 0, self::MISSING => 0, self::UNUSED => 0];

        foreach ($locales as $locale) {
            $keys = [];

            foreach ([$used, $defined] as $source) {
                foreach ($source as $name => $messages) {
                    if ($domain === null || $name === $domain) {
                        foreach (array_keys($messages) as $key) {
                            $keys[$name . "\0" . $key] = [(string) $name, (string) $key];
                        }
                    }
                }
            }

            ksort($keys);

            foreach ($keys as [$name, $key]) {
                $catalogue = $this->translator->getCatalogue($locale, $name);
                $state = isset($catalogue[$key]) && $catalogue[$key] !== '' ? self::TRANSLATED : ($this->translator->has($key, $name, $locale) ? self::FALLBACK : self::MISSING);
                $isUsed = isset($used[$name][$key]) || in_array($name, self::FRAMEWORK_DOMAINS, true);
                $states = $isUsed ? [$state] : [$state, self::UNUSED];

                if (($onlyMissing && $state === self::TRANSLATED) || ($onlyUnused && $isUsed)) {
                    continue;
                }

                foreach ($states as $item) {
                    $counts[$item]++;
                }

                $rows[] = [$locale, $name, self::shorten($key), self::shorten($catalogue[$key] ?? ''), implode(', ', array_map(static fn (string $item): string => self::colorize($item), $states))];
            }
        }

        if ($rows === []) {
            $output->note('No translation key to show.');

            return self::SUCCESS;
        }

        $output->table(['Locale', 'Domain', 'Key', 'Message', 'State'], $rows);
        $output->writeln(sprintf('  %d translated, %d fallback, %d missing, %d unused', $counts[self::TRANSLATED], $counts[self::FALLBACK], $counts[self::MISSING], $counts[self::UNUSED]));
        $output->newLine();

        return self::SUCCESS;
    }

    protected function defined(): array
    {
        $defined = [];

        foreach ($this->translator->getResources() as $resource) {
            foreach (array_keys($this->translator->loadFile($resource['file'])) as $key) {
                $defined[$resource['domain']][(string) $key] = true;
            }
        }

        return $defined;
    }

    protected static function colorize(string $state): string
    {
        return match ($state) {
            self::TRANSLATED => '<success>' . $state . '</success>',
            self::MISSING => '<error>' . $state . '</error>',
            default => '<comment>' . $state . '</comment>',
        };
    }

    protected static function shorten(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        return (string) preg_replace('/^(.{57}).{4,}$/us', '$1...', $value);
    }
}