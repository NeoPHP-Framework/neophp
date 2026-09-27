<?php

declare(strict_types=1);

namespace NeoPHP\Package\NeoAI\Helper\Console;

use NeoPHP\Package\NeoAI\Contract\AbstractAiCommand;
use NeoPHP\Package\NeoAI\Exception\NeoAiException;
use NeoPHP\Package\NeoAI\Model\Message;
use NeoPHP\Process\Console\Attribute\AsCommand;
use NeoPHP\Process\Console\Contract\InputInterface;
use NeoPHP\Process\Console\Contract\OutputInterface;
use NeoPHP\Process\Console\IO\InputOption;

#[AsCommand(name: 'ai:test', description: 'Checks the NeoAI configuration and sends a ping to a connection')]
class AiTestCommand extends AbstractAiCommand
{
    protected function configure(InputInterface $input, OutputInterface $output): void
    {
        $input->addOption('connection', null, InputOption::VALUE_REQUIRED, 'The connection to test (default: default_connection)');
        $input->addOption('no-ping', null, InputOption::VALUE_NONE, 'Only show the configuration, nothing is sent');
        $this->addExample('ai:test');
        $this->addExample('ai:test --connection=local');
    }

    protected function do(InputInterface $input, OutputInterface $output): int
    {
        $manager = $this->manager($output);

        if ($manager === null) {
            return self::FAILURE;
        }

        $config = $manager->getConfig();
        $rows = [];

        foreach ($manager->getConnectionNames() as $name) {
            $info = $manager->describe($name);
            $rows[] = [$name . ($name === $manager->getDefaultConnection() ? ' (default)' : ''), (string) $info['provider'], (string) $info['model'], $info['remote'] === null ? '?' : ($info['remote'] ? 'remote' : 'local'), $info['error'] ?? 'ok'];
        }

        $output->title('NeoAI configuration');
        $output->definitionList([
            'Enabled' => 'yes (web: ' . ($manager->isWebEnabled() ? 'yes' : 'no') . ')',
            'Remote providers' => $config['allow_remote'] ? 'allowed' : 'forbidden (allow_remote: false)',
            'Language' => (string) $config['language'],
            'Storage' => $manager->sandbox()->relative($manager->getStoragePath()),
            'Secrets redacted' => $manager->redactor()->getSecretCount() . ' value(s) from the environment + patterns',
        ]);
        $output->table(['Connection', 'Provider', 'Model', 'Server', 'Status'], $rows);

        if ($input->getOption('no-ping')) {
            return self::SUCCESS;
        }

        $connection = $input->getOption('connection');

        try {
            $provider = $manager->connection(is_string($connection) && $connection !== '' ? $connection : null);
            $this->privacy($output, $provider);
            $start = microtime(true);
            $response = $provider->chat([Message::user('Reply with the single word: pong')], ['max_tokens' => 16]);
        } catch (NeoAiException $exception) {
            $output->error($manager->redactor()->redact($exception->getMessage()));

            return self::FAILURE;
        }

        $output->success(sprintf('%s answered "%s" in %d ms (%d tokens, model %s).', $provider->getName(), mb_substr(trim($response->getContent()), 0, 60), (int) ((microtime(true) - $start) * 1000), $response->getTotalTokens(), $response->getModel()));

        return self::SUCCESS;
    }
}