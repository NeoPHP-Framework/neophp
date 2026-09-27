<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Contract;

use NeoPHP\Component\Container\Contract\ContainerInterface;
use NeoPHP\Package\NeoAI\Exception\NeoAiException;
use NeoPHP\Package\NeoAI\Model\Patch;
use NeoPHP\Package\NeoAI\NeoAiManager;
use NeoPHP\Package\NeoAI\Provider\NeoAiProvider;
use NeoPHP\Process\Console\Contract\AbstractConsole;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\Formatter;

abstract class AbstractAiCommand extends AbstractConsole
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    protected function manager(OutputInterface $output): ?NeoAiManager
    {
        if (!$this->container->bound(NeoAiProvider::CONFIG_ID)) {
            $output->error('The NeoAI package is not registered: add NeoAiProvider::class to the kernel providers.');

            return null;
        }

        $manager = $this->container->get(NeoAiManager::class);

        if (!$manager->isEnabled()) {
            $output->error('NeoAI is disabled: it is a development tool, enabled by default only when kernel.debug is true (force it with "enabled: true" in config/packages/neo_ai.yaml).');

            return null;
        }

        if ($manager->getConnectionNames() === []) {
            $output->error('No AI connection configured: define "connections" in config/packages/neo_ai.yaml.');

            return null;
        }

        return $manager;
    }

    protected function privacy(OutputInterface $output, ProviderInterface $provider): void
    {
        $output->writeln(sprintf(
            ' <muted>Data is sent to</muted> <info>%s</info> <muted>/</muted> <info>%s</info> <muted>(connection "%s", %s). Secrets are redacted before sending.</muted>',
            Formatter::escape($provider->getType()),
            Formatter::escape($provider->getModel()),
            Formatter::escape($provider->getName()),
            $provider->isLocal() ? '<success>local</success>' : '<warning>remote server</warning>',
        ));
    }

    protected function markdown(OutputInterface $output, string $text): void
    {
        $code = false;

        foreach (preg_split('/\r\n|\n|\r/', $text) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $code = !$code;
                $output->writeln(' <muted>' . Formatter::escape(ltrim($line)) . '</muted>');
                continue;
            }

            $escaped = Formatter::escape($line);

            if ($code) {
                $output->writeln(' <comment>' . $escaped . '</comment>');
                continue;
            }

            if (preg_match('/^#{1,6}\s+(.*)$/', $line, $matches) === 1) {
                $output->writeln(' <title>' . Formatter::escape($matches[1]) . '</title>');
                continue;
            }

            $escaped = (string) preg_replace('/\*\*([^*]+)\*\*/', '<bold>$1</bold>', $escaped);
            $escaped = (string) preg_replace('/`([^`]+)`/', '<info>$1</info>', $escaped);
            $output->writeln(' ' . $escaped);
        }
    }

    protected function diff(OutputInterface $output, Patch $patch, int $number): void
    {
        $output->writeln(sprintf(' <title>Patch #%d</title> <muted>%s</muted>%s', $number, Formatter::escape(implode(', ', $patch->getFiles())), $patch->getDescription() !== '' ? ' - ' . Formatter::escape($patch->getDescription()) : ''));

        foreach (explode("\n", rtrim($patch->getDiff(), "\n")) as $line) {
            $escaped = Formatter::escape($line);
            $style = match (true) {
                str_starts_with($line, '+++'), str_starts_with($line, '---') => 'bold',
                str_starts_with($line, '@@') => 'question',
                str_starts_with($line, '+') => 'info',
                str_starts_with($line, '-') => 'error',
                default => null,
            };
            $output->writeln(' ' . ($style !== null ? sprintf('<%1$s>%2$s</%1$s>', $style, $escaped) : $escaped));
        }
    }

    protected function review(OutputInterface $output, NeoAiManager $manager, array $patches): int
    {
        $applied = 0;
        $applier = $manager->patches();

        foreach (array_values($patches) as $index => $patch) {
            $output->newLine();
            $this->diff($output, $patch, $index + 1);

            if (!$output->isInteractive()) {
                $output->comment('Non interactive mode: the patch was not applied.');
                continue;
            }

            if (!$output->confirm(sprintf('Apply patch #%d to %s?', $index + 1, implode(', ', $patch->getFiles())), false)) {
                $output->comment('Skipped.');
                continue;
            }

            try {
                $result = $applier->apply($patch);
            } catch (NeoAiException $exception) {
                $output->error($exception->getMessage());
                continue;
            }

            $applied++;
            $output->success(sprintf('Applied: %s. Backup: %s', implode(', ', array_map(static fn (string $file, string $action): string => $file . ' (' . $action . ')', array_keys($result['files']), $result['files'])), $manager->sandbox()->relative($result['backup'])));
        }

        return $applied;
    }
}